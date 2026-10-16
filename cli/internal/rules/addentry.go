package rules

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"

	"go.yaml.in/yaml/v3"

	"github.com/ubermuda/loupe/cli/internal/update"
)

// EntryRefusal is the error of AddWorkEntry. Reason is one of the Refuse
// codes, and the rule file is unchanged.
type EntryRefusal struct {
	Reason string
	Err    error
}

func (e *EntryRefusal) Error() string { return e.Reason + ": " + e.Err.Error() }

func (e *EntryRefusal) Unwrap() error { return e.Err }

func refuseEntry(reason string, format string, args ...any) *EntryRefusal {
	return &EntryRefusal{Reason: reason, Err: fmt.Errorf(format, args...)}
}

// AddWorkEntry adds one command entry to the work map of the rule file at
// path. The text is a YAML mapping with one key, the entry name. The file must
// set acceptEntries. The bridge rewrites the whole file from its parsed tree,
// so comments and layout can change. The file stays unchanged when the
// function returns an error, and the result must pass the checks of Parse
// with defaults.
func AddWorkEntry(path, entryYAML string, defaults Defaults) error {
	if resolved, err := filepath.EvalSymlinks(path); err == nil {
		path = resolved
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return refuseEntry(RefuseWriteFailed, "read rule file: %w", err)
	}
	f, _, err := decodeFile(data)
	if err != nil {
		return refuseEntry(RefuseWriteFailed, "%w", err)
	}
	if !f.AcceptEntries {
		return refuseEntry(RefuseNotAccepted, "the rule file does not set acceptEntries: true")
	}

	name, entry, err := parseNewEntry(entryYAML)
	if err != nil {
		return err
	}

	var doc yaml.Node
	if err := yaml.Unmarshal(data, &doc); err != nil {
		return refuseEntry(RefuseWriteFailed, "parse rule file: %w", err)
	}
	if doc.Kind != yaml.DocumentNode || len(doc.Content) != 1 || doc.Content[0].Kind != yaml.MappingNode {
		return refuseEntry(RefuseWriteFailed, "parse rule file: the first YAML document is not a mapping, so the bridge cannot edit it")
	}
	work := workNode(doc.Content[0])
	for i := 0; i+1 < len(work.Content); i += 2 {
		if work.Content[i].Value == name {
			return refuseEntry(RefuseEntryExists, "the work map holds an entry named %q already", name)
		}
	}
	work.Style = 0
	work.Content = append(work.Content, &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!str", Value: name}, entry)

	var out bytes.Buffer
	enc := yaml.NewEncoder(&out)
	enc.SetIndent(2)
	if err := enc.Encode(&doc); err != nil {
		return refuseEntry(RefuseWriteFailed, "encode rule file: %w", err)
	}
	if err := enc.Close(); err != nil {
		return refuseEntry(RefuseWriteFailed, "encode rule file: %w", err)
	}
	set, err := Parse(out.Bytes(), defaults)
	if err != nil {
		return refuseEntry(RefuseEntryInvalid, "the rule file with the new entry fails its checks: %w", err)
	}
	if got, ok := set.work[name]; !ok || got.Action != ActionCommand {
		return refuseEntry(RefuseEntryInvalid, "the entry %q is not a command entry", name)
	}

	info, err := os.Stat(path)
	if err != nil {
		return refuseEntry(RefuseWriteFailed, "write rule file: %w", err)
	}
	if err := update.WriteFile(path, out.Bytes(), info.Mode().Perm()); err != nil {
		return refuseEntry(RefuseWriteFailed, "write rule file: %w", err)
	}

	return nil
}

// workNode returns the work mapping of the top-level node, and makes it when
// the file holds none or holds an empty one.
func workNode(top *yaml.Node) *yaml.Node {
	for i := 0; i+1 < len(top.Content); i += 2 {
		if top.Content[i].Value != "work" {
			continue
		}
		if v := top.Content[i+1]; v.Kind == yaml.MappingNode {
			return v
		}
		top.Content[i+1] = &yaml.Node{Kind: yaml.MappingNode, Tag: "!!map"}

		return top.Content[i+1]
	}
	work := &yaml.Node{Kind: yaml.MappingNode, Tag: "!!map"}
	top.Content = append(top.Content, &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!str", Value: "work"}, work)

	return work
}

// parseNewEntry reads the entry text: one document, one mapping, one key, and
// a mapping body with the action command.
func parseNewEntry(text string) (string, *yaml.Node, error) {
	dec := yaml.NewDecoder(bytes.NewReader([]byte(text)))
	var doc yaml.Node
	if err := dec.Decode(&doc); err != nil {
		return "", nil, refuseEntry(RefuseEntryInvalid, "the entry text does not parse: %w", err)
	}
	var extra yaml.Node
	if err := dec.Decode(&extra); !errors.Is(err, io.EOF) {
		return "", nil, refuseEntry(RefuseEntryInvalid, "the entry text holds more than one YAML document")
	}
	if doc.Kind != yaml.DocumentNode || len(doc.Content) != 1 || doc.Content[0].Kind != yaml.MappingNode {
		return "", nil, refuseEntry(RefuseEntryInvalid, "the entry text is not a mapping")
	}
	top := doc.Content[0]
	if len(top.Content) != 2 {
		return "", nil, refuseEntry(RefuseEntryInvalid, "the entry text must hold exactly one key, the entry name")
	}
	key, body := top.Content[0], top.Content[1]
	if key.Kind != yaml.ScalarNode || key.Value == "" {
		return "", nil, refuseEntry(RefuseEntryInvalid, "the entry name is not a plain word")
	}
	if body.Kind != yaml.MappingNode {
		return "", nil, refuseEntry(RefuseEntryInvalid, "the body of entry %q is not a mapping", key.Value)
	}
	var action string
	for i := 0; i+1 < len(body.Content); i += 2 {
		if body.Content[i].Value == "action" {
			action = body.Content[i+1].Value
		}
	}
	if action != ActionCommand {
		return "", nil, refuseEntry(RefuseEntryInvalid, "the action of entry %q must be %s", key.Value, ActionCommand)
	}

	return key.Value, body, nil
}
