// Package gen reads the OpenAPI spec of the public API and writes the Go table
// of its operations. `just cli-generate` runs it, and a test runs it again to
// prove that the committed table matches the spec.
package gen

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"go/format"
	"net/http"
	"sort"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/publicapi/flagname"
)

// Reserved names are flags that every command shares. A parameter that would
// take one of these names is an error, so a spec change cannot hide a flag.
var reserved = map[string]bool{
	"format": true, "retry": true, "project": true, "yes": true, "input": true,
	"body-file": true, "idempotency-key": true, "output": true, "clobber": true,
	"help": true, "config": true,
}

type spec struct {
	Paths      map[string]map[string]json.RawMessage `json:"paths"`
	Components struct {
		Schemas map[string]*schema `json:"schemas"`
	} `json:"components"`
}

type schema struct {
	Ref         string             `json:"$ref"`
	Type        string             `json:"type"`
	Format      string             `json:"format"`
	Description string             `json:"description"`
	Nullable    bool               `json:"nullable"`
	Required    []string           `json:"required"`
	Enum        []any              `json:"enum"`
	Properties  map[string]*schema `json:"properties"`
	Items       *schema            `json:"items"`
	AnyOf       []*schema          `json:"anyOf"`
	OneOf       []*schema          `json:"oneOf"`
	AllOf       []*schema          `json:"allOf"`
	// order keeps the property order of the spec, which a map loses.
	order []string
}

type parameter struct {
	Name        string  `json:"name"`
	In          string  `json:"in"`
	Description string  `json:"description"`
	Required    bool    `json:"required"`
	Schema      *schema `json:"schema"`
}

type operation struct {
	ID          string      `json:"operationId"`
	Summary     string      `json:"summary"`
	Description string      `json:"description"`
	Parameters  []parameter `json:"parameters"`
	RequestBody *struct {
		Content map[string]struct {
			Schema *schema `json:"schema"`
		} `json:"content"`
	} `json:"requestBody"`
	Responses map[string]struct {
		Content map[string]struct {
			Schema *schema `json:"schema"`
		} `json:"content"`
	} `json:"responses"`
}

type param struct {
	Name, Type, Format, Items, Description string
	Required, Nullable                     bool
	Enum                                   []string
}

type op struct {
	ID, Method, Path, Summary, Description string
	PathArgs                               []string
	HasProject                             bool
	Query, Body                            []param
	Idempotent, TakesFields, Multipart     bool
	AnswerFields, TimeFields               []string
}

// Generate turns the spec bytes into the source of operations_gen.go.
func Generate(specJSON []byte) ([]byte, error) {
	var sp spec
	if err := json.Unmarshal(specJSON, &sp); err != nil {
		return nil, fmt.Errorf("read the spec: %w", err)
	}
	order, err := propertyOrder(specJSON)
	if err != nil {
		return nil, err
	}
	g := &generator{spec: &sp, order: order}

	var ops []op
	paths := make([]string, 0, len(sp.Paths))
	for p := range sp.Paths {
		paths = append(paths, p)
	}
	sort.Strings(paths)
	for _, p := range paths {
		methods := make([]string, 0, len(sp.Paths[p]))
		for m := range sp.Paths[p] {
			methods = append(methods, m)
		}
		sort.Strings(methods)
		for _, m := range methods {
			switch m {
			case "get", "put", "post", "patch", "delete":
			default:
				continue
			}
			var raw operation
			if err := json.Unmarshal(sp.Paths[p][m], &raw); err != nil {
				return nil, fmt.Errorf("read %s %s: %w", m, p, err)
			}
			o, err := g.build(p, strings.ToUpper(m), raw)
			if err != nil {
				return nil, fmt.Errorf("%s %s: %w", strings.ToUpper(m), p, err)
			}
			ops = append(ops, o)
		}
	}
	sort.Slice(ops, func(i, j int) bool { return ops[i].ID < ops[j].ID })
	for i := 1; i < len(ops); i++ {
		if ops[i].ID == ops[i-1].ID {
			return nil, fmt.Errorf("operation id %s is used twice", ops[i].ID)
		}
	}

	return render(ops)
}

// propertyOrder reads each schema's property names in spec order. The
// decoder of a map type sorts them, and the answer order matters to a person.
func propertyOrder(specJSON []byte) (map[string][]string, error) {
	var root struct {
		Components struct {
			Schemas map[string]json.RawMessage `json:"schemas"`
		} `json:"components"`
	}
	if err := json.Unmarshal(specJSON, &root); err != nil {
		return nil, err
	}
	out := map[string][]string{}
	for name, raw := range root.Components.Schemas {
		keys, err := objectKeys(raw, "properties")
		if err != nil {
			return nil, fmt.Errorf("schema %s: %w", name, err)
		}
		out[name] = keys
	}

	return out, nil
}

func objectKeys(raw json.RawMessage, field string) ([]string, error) {
	var outer map[string]json.RawMessage
	if err := json.Unmarshal(raw, &outer); err != nil {
		return nil, err
	}
	props, ok := outer[field]
	if !ok {
		return nil, nil
	}
	dec := json.NewDecoder(bytes.NewReader(props))
	if t, err := dec.Token(); err != nil || t != json.Delim('{') {
		return nil, errors.New("properties is not an object")
	}
	var keys []string
	for dec.More() {
		t, err := dec.Token()
		if err != nil {
			return nil, err
		}
		keys = append(keys, t.(string))
		var skip json.RawMessage
		if err := dec.Decode(&skip); err != nil {
			return nil, err
		}
	}

	return keys, nil
}

type generator struct {
	spec  *spec
	order map[string][]string
}

func (g *generator) resolve(s *schema) (*schema, []string, error) {
	var keys []string
	for hops := 0; s != nil && s.Ref != ""; hops++ {
		if hops > 20 {
			return nil, nil, errors.New("a $ref loops")
		}
		name, ok := strings.CutPrefix(s.Ref, "#/components/schemas/")
		if !ok {
			return nil, nil, fmt.Errorf("unsupported $ref %q", s.Ref)
		}
		next, found := g.spec.Components.Schemas[name]
		if !found {
			return nil, nil, fmt.Errorf("$ref %q has no schema", s.Ref)
		}
		s, keys = next, g.order[name]
	}

	return s, keys, nil
}

// fieldNames gives the property names of a schema in spec order. An anyOf or
// allOf gives the union. A list gives the names of its rows.
func (g *generator) fieldNames(s *schema, rows bool) ([]string, map[string]*schema, error) {
	s, keys, err := g.resolve(s)
	if err != nil || s == nil {
		return nil, nil, err
	}
	names := []string{}
	props := map[string]*schema{}
	add := func(n []string, p map[string]*schema) {
		for _, k := range n {
			if _, dup := props[k]; !dup {
				names = append(names, k)
			}
			props[k] = p[k]
		}
	}

	for _, group := range [][]*schema{s.AnyOf, s.OneOf, s.AllOf} {
		for _, member := range group {
			n, p, err := g.fieldNames(member, rows)
			if err != nil {
				return nil, nil, err
			}
			add(n, p)
		}
	}
	if len(s.Properties) == 0 {
		return names, props, nil
	}
	if items, ok := s.Properties["items"]; ok && rows {
		inner, _, err := g.resolve(items)
		if err != nil {
			return nil, nil, err
		}
		if inner != nil && inner.Type == "array" && inner.Items != nil {
			return g.fieldNames(inner.Items, false)
		}
	}
	if keys == nil {
		keys = sortedKeys(s.Properties)
	}
	add(keys, s.Properties)

	return names, props, nil
}

func sortedKeys[V any](m map[string]V) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)

	return keys
}

func kebab(s string) string {
	var b strings.Builder
	for i, r := range s {
		if r >= 'A' && r <= 'Z' {
			if i > 0 {
				b.WriteByte('-')
			}
			r += 'a' - 'A'
		}
		b.WriteRune(r)
	}

	return b.String()
}

func (g *generator) toParam(name string, s *schema, required bool, description string) (param, error) {
	rs, _, err := g.resolve(s)
	if err != nil {
		return param{}, err
	}
	if rs == nil {
		return param{}, fmt.Errorf("%s has no schema", name)
	}
	p := param{Name: name, Type: rs.Type, Format: rs.Format, Required: required, Nullable: rs.Nullable, Description: description}
	if p.Type == "" {
		p.Type = "object"
	}
	if p.Description == "" {
		p.Description = rs.Description
	}
	if rs.Type == "array" && rs.Items != nil {
		item, _, err := g.resolve(rs.Items)
		if err != nil {
			return param{}, err
		}
		p.Items = "object"
		if item != nil && item.Type != "" {
			p.Items = item.Type
		}
		if item != nil {
			rs = &schema{Enum: item.Enum}
		}
	}
	for _, e := range rs.Enum {
		p.Enum = append(p.Enum, fmt.Sprint(e))
	}

	return p, nil
}

func (g *generator) build(path, method string, raw operation) (op, error) {
	if raw.ID == "" {
		return op{}, errors.New("no operationId")
	}
	o := op{ID: raw.ID, Method: method, Path: path, Summary: raw.Summary, Description: raw.Description}

	byName := map[string]parameter{}
	for _, p := range raw.Parameters {
		byName[p.Name] = p
	}
	for _, seg := range strings.Split(path, "/") {
		name, ok := strings.CutPrefix(strings.TrimSuffix(seg, "}"), "{")
		if !ok {
			continue
		}
		if _, declared := byName[name]; !declared {
			return op{}, fmt.Errorf("path value {%s} has no parameter", name)
		}
		if name == "project" {
			o.HasProject = true
			if !strings.HasSuffix(path, "/projects/{project}") {
				continue
			}
		}
		o.PathArgs = append(o.PathArgs, name)
	}

	seen := map[string]string{}
	claim := func(name, where string) error {
		k := kebab(name)
		if renamed, ok := flagname.Renames[name]; ok && where == "body field" {
			k = renamed
		}
		if reserved[k] {
			return fmt.Errorf("%s %q collides with the shared flag --%s", where, name, k)
		}
		if prev, dup := seen[k]; dup {
			return fmt.Errorf("%s %q collides with %s as the flag --%s", where, name, prev, k)
		}
		seen[k] = where + " " + name

		return nil
	}

	for _, p := range raw.Parameters {
		switch p.In {
		case "query":
			if p.Name == "fields" {
				o.TakesFields = true

				continue
			}
			if err := claim(p.Name, "query parameter"); err != nil {
				return op{}, err
			}
			q, err := g.toParam(p.Name, p.Schema, p.Required, p.Description)
			if err != nil {
				return op{}, err
			}
			o.Query = append(o.Query, q)
		case "header":
			if strings.EqualFold(p.Name, "Idempotency-Key") {
				o.Idempotent = true
			}
		}
	}

	if raw.RequestBody != nil {
		for ct, c := range raw.RequestBody.Content {
			if ct != "application/json" && ct != "multipart/form-data" {
				return op{}, fmt.Errorf("request body type %s is not supported", ct)
			}
			o.Multipart = ct == "multipart/form-data"
			rs, keys, err := g.resolve(c.Schema)
			if err != nil {
				return op{}, err
			}
			if rs == nil {
				continue
			}
			if keys == nil {
				keys = sortedKeys(rs.Properties)
			}
			required := map[string]bool{}
			for _, r := range rs.Required {
				required[r] = true
			}
			for _, name := range keys {
				if err := claim(name, "body field"); err != nil {
					return op{}, err
				}
				b, err := g.toParam(name, rs.Properties[name], required[name], "")
				if err != nil {
					return op{}, err
				}
				o.Body = append(o.Body, b)
			}
		}
	}

	if err := g.answer(&o, raw); err != nil {
		return op{}, err
	}

	return o, nil
}

func (g *generator) answer(o *op, raw operation) error {
	codes := sortedKeys(raw.Responses)
	for _, code := range codes {
		if len(code) != 3 || code[0] != '2' {
			continue
		}
		c, ok := raw.Responses[code].Content["application/json"]
		if !ok {
			return nil
		}
		names, props, err := g.fieldNames(c.Schema, true)
		if err != nil {
			return err
		}
		o.AnswerFields = names
		for _, n := range names {
			p, _, err := g.resolve(props[n])
			if err != nil {
				return err
			}
			if p != nil && p.Format == "date-time" {
				o.TimeFields = append(o.TimeFields, n)
			}
		}

		return nil
	}
	if o.Method == http.MethodGet && len(codes) == 0 {
		return errors.New("no responses")
	}

	return nil
}

func render(ops []op) ([]byte, error) {
	var b bytes.Buffer
	b.WriteString("// Code generated by `just cli-generate` from openapi/public.json. DO NOT EDIT.\n\npackage publicapi\n\n")
	b.WriteString("// Operations holds what the spec says about each operation, sorted by id.\nvar Operations = []Operation{\n")
	for _, o := range ops {
		fmt.Fprintf(&b, "{ID: %q, Method: %q, Path: %q, Summary: %q, Description: %q,\n", o.ID, o.Method, o.Path, o.Summary, o.Description)
		fmt.Fprintf(&b, "PathArgs: %s, HasProject: %t, Query: %s, Body: %s,\n", strs(o.PathArgs), o.HasProject, params(o.Query), params(o.Body))
		fmt.Fprintf(&b, "Idempotent: %t, TakesFields: %t, Multipart: %t, AnswerFields: %s, TimeFields: %s},\n", o.Idempotent, o.TakesFields, o.Multipart, strs(o.AnswerFields), strs(o.TimeFields))
	}
	b.WriteString("}\n")

	out, err := format.Source(b.Bytes())
	if err != nil {
		return nil, fmt.Errorf("format the table: %w", err)
	}

	return out, nil
}

func strs(list []string) string {
	if len(list) == 0 {
		return "nil"
	}
	parts := make([]string, len(list))
	for i, s := range list {
		parts[i] = fmt.Sprintf("%q", s)
	}

	return "[]string{" + strings.Join(parts, ", ") + "}"
}

func params(list []param) string {
	if len(list) == 0 {
		return "nil"
	}
	var b strings.Builder
	b.WriteString("[]Param{\n")
	for _, p := range list {
		fmt.Fprintf(&b, "{Name: %q, Type: %q, Format: %q, Items: %q, Required: %t, Nullable: %t, Description: %q, Enum: %s},\n",
			p.Name, p.Type, p.Format, p.Items, p.Required, p.Nullable, p.Description, strs(p.Enum))
	}
	b.WriteString("}")

	return b.String()
}
