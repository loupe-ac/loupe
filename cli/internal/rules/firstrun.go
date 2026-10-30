package rules

import (
	"bytes"
	"errors"
	"fmt"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"

	"go.yaml.in/yaml/v3"
)

// ProductDesignKind is the work kind of the interactive entry that first-run
// writes.
const ProductDesignKind = "product-design"

// TechDesignSessionKind is the work kind of the interactive tech design entry
// that first-run writes beside the product-design entry.
const TechDesignSessionKind = "tech-design-session"

// techDesignSessionPrompt is the prompt of that entry.
const techDesignSessionPrompt = "/loupe:tech-design {cardNumber}"

// productDesignPrompt is the prompt of that entry.
const productDesignPrompt = "/loupe:product-design {cardNumber}"

// terminals lists the Linux terminals first-run looks for, in order, each with
// the argv that runs a script in a new window.
var terminals = [][]string{
	{"gnome-terminal", "--", "{script}"},
	{"konsole", "-e", "{script}"},
	{"xfce4-terminal", "-x", "{script}"},
	{"kitty", "{script}"},
	{"alacritty", "-e", "{script}"},
	{"xterm", "-e", "{script}"},
}

// FirstRunOptions say what the first-run rule file holds. A nil LookPath looks
// on the PATH of the process, and an empty GOOS means the running OS.
type FirstRunOptions struct {
	// Slug is the project key, and Dir is the repository directory it maps to.
	// ID is the project id, whose entry Merge renames to Slug.
	Slug string
	ID   string
	Dir  string

	LookPath func(string) (string, error)
	GOOS     string
}

func (o FirstRunOptions) look(name string) bool {
	look := o.LookPath
	if look == nil {
		look = exec.LookPath
	}
	_, err := look(name)

	return err == nil
}

// Harness is the harness of the account first-run writes: claude-code when
// claude is on the PATH, else codex when codex is, else claude-code.
func (o FirstRunOptions) Harness() string {
	if !o.look("claude") && o.look("codex") {
		return HarnessCodex
	}

	return HarnessClaudeCode
}

// Terminal is the launch command for this machine, or nil when no terminal is
// known. macOS always has Terminal. Linux takes the first terminal on the PATH.
func (o FirstRunOptions) Terminal() []string {
	goos := o.GOOS
	if goos == "" {
		goos = runtime.GOOS
	}

	switch goos {
	case "darwin":
		return []string{"open", "-a", "Terminal", "{script}"}
	case "linux":
		for _, argv := range terminals {
			if o.look(argv[0]) {
				return append([]string(nil), argv...)
			}
		}
	}

	return nil
}

// FirstRun builds a new rule file: app prompts on, one account for the
// detected harness, the project, and, where a terminal is known, an
// interactive product-design entry with its launch command.
func FirstRun(o FirstRunOptions) ([]byte, error) {
	account := o.Harness()
	top := mappingNode(
		"appPrompts", boolNode(true),
		"accounts", mappingNode(account, mappingNode("harness", scalarNode(account))),
		"defaults", mappingNode("account", scalarNode(account), "permissions", scalarNode(PermissionsWorkspace)),
		"projects", projectsNode(o),
	)
	if launch := o.Terminal(); launch != nil {
		top.Content = append(top.Content, scalarNode("launch"), launchNode(launch), scalarNode("work"), mappingNode(ProductDesignKind, productDesignNode(), TechDesignSessionKind, techDesignSessionNode()))
	}

	return encodeRules(&yaml.Node{Kind: yaml.DocumentNode, Content: []*yaml.Node{top}})
}

// Merge adds to an existing rule file what first-run needs and the file lacks:
// the appPrompts key, an account when the file has none, defaults.account when
// it has one account and no default, defaults.permissions, the project, the
// launch command and the product-design entry. A key that is present stays as
// it is. The second result names what Merge added, and is empty when the file
// needed nothing, in which case the first result is the input. A merged file
// that does not load fails. Comments and layout stay.
func Merge(existing []byte, o FirstRunOptions) ([]byte, []string, error) {
	if len(bytes.TrimSpace(existing)) == 0 {
		out, err := FirstRun(o)

		return out, []string{"the whole file"}, err
	}
	doc, top, err := editableRules(existing)
	if err != nil {
		return nil, nil, err
	}

	added, err := addMissing(top, o, o.Terminal())
	if err != nil {
		return nil, nil, err
	}
	if len(added) == 0 {
		return existing, nil, nil
	}
	out, err := encodeRules(doc)
	if err != nil {
		return nil, nil, err
	}
	// Writing a file the bridge cannot load would report a success that the
	// next bridge start contradicts.
	if _, err := Parse(out, Defaults{}); err != nil {
		return nil, nil, fmt.Errorf("the merged rule file does not load, so it is not written: fix the file, then run loupe init again: %w", err)
	}

	return out, added, nil
}

// Reset builds a new first-run file and keeps the projects map and the
// autoUpdate key of the existing one. The project of o is added when the old
// map lacks it. A map that holds the project under another directory fails.
func Reset(existing []byte, o FirstRunOptions) ([]byte, error) {
	fresh, err := FirstRun(o)
	if err != nil {
		return nil, err
	}
	if len(bytes.TrimSpace(existing)) == 0 {
		return fresh, nil
	}
	_, old, err := editableRules(existing)
	if err != nil {
		return nil, err
	}
	projects, hasProjects := lookup(old, "projects")
	hasProjects = hasProjects && !isNull(projects)
	autoUpdate, hasAuto := lookup(old, autoUpdateKey)
	if !hasProjects && !hasAuto {
		return fresh, nil
	}

	doc, top, err := editableRules(fresh)
	if err != nil {
		return nil, err
	}
	if hasProjects {
		i, _ := index(top, "projects")
		top.Content[i+1] = projects
		if _, err := addMissing(top, o, nil); err != nil {
			return nil, err
		}
	}
	if hasAuto {
		top.Content = append(top.Content, scalarNode(autoUpdateKey), autoUpdate)
	}

	return encodeRules(doc)
}

// addMissing adds the missing keys to the top mapping and names them. A nil
// launch adds neither the launch command nor the interactive entry.
func addMissing(top *yaml.Node, o FirstRunOptions, launch []string) ([]string, error) {
	var added []string

	if _, ok := lookup(top, "appPrompts"); !ok {
		top.Content = append(top.Content, scalarNode("appPrompts"), boolNode(true))
		added = append(added, "appPrompts")
	}

	defaults, err := childMapping(top, "defaults")
	if err != nil {
		return nil, err
	}
	if _, ok := lookup(defaults, "permissions"); !ok {
		defaults.Content = append(defaults.Content, scalarNode("permissions"), scalarNode(PermissionsWorkspace))
		added = append(added, "defaults.permissions")
	}

	// A file with no account runs no worker, so it gets the detected one,
	// under the name defaults.account gives when it gives one.
	accounts, err := childMapping(top, "accounts")
	if err != nil {
		return nil, err
	}
	if len(accounts.Content) == 0 {
		harness := o.Harness()
		name := harness
		if n, ok := lookup(defaults, "account"); ok && n.Kind == yaml.ScalarNode && !isNull(n) && n.Value != "" {
			name = n.Value
		} else {
			setKey(defaults, "account", scalarNode(harness))
			added = append(added, "defaults.account")
		}
		accounts.Content = append(accounts.Content, scalarNode(name), mappingNode("harness", scalarNode(harness)))
		added = append(added, "accounts."+name)
	} else if n, ok := lookup(defaults, "account"); (!ok || isNull(n) || n.Value == "") && len(accounts.Content) == 2 {
		// One account is the only default the bridge can take. With several,
		// the choice stays with the person and Parse names it.
		setKey(defaults, "account", scalarNode(accounts.Content[0].Value))
		added = append(added, "defaults.account")
	}

	projects, err := childMapping(top, "projects")
	if err != nil {
		return nil, err
	}
	idKey, byID := idEntry(projects, o)
	if project, ok := lookup(projects, o.Slug); ok {
		if byID {
			return nil, fmt.Errorf("projects.%s and projects.%s name the same project, and the bridge refuses both: remove projects.%s, then run loupe init again", o.Slug, idKey.Value, idKey.Value)
		}
		if err := sameDir(project, o.Slug, o.Dir); err != nil {
			return nil, err
		}
	} else if byID {
		project, _ := lookup(projects, idKey.Value)
		if err := sameDir(project, idKey.Value, o.Dir); err != nil {
			return nil, err
		}
		added = append(added, "projects."+o.Slug+" (renamed from projects."+idKey.Value+")")
		idKey.Value = o.Slug
	} else {
		projects.Content = append(projects.Content, scalarNode(o.Slug), mappingNode("dir", scalarNode(o.Dir)))
		added = append(added, "projects."+o.Slug)
	}

	if launch == nil {
		return added, nil
	}
	if _, ok := lookup(top, "launch"); !ok {
		top.Content = append(top.Content, scalarNode("launch"), launchNode(launch))
		added = append(added, "launch")
	}
	work, err := childMapping(top, "work")
	if err != nil {
		return nil, err
	}
	if _, ok := lookup(work, ProductDesignKind); !ok {
		work.Content = append(work.Content, scalarNode(ProductDesignKind), productDesignNode())
		added = append(added, "work."+ProductDesignKind)
	}
	if _, ok := lookup(work, TechDesignSessionKind); !ok {
		work.Content = append(work.Content, scalarNode(TechDesignSessionKind), techDesignSessionNode())
		added = append(added, "work."+TechDesignSessionKind)
	}

	return added, nil
}

// idEntry finds the key of the entry that names the project by its id. The
// bridge takes only the slug of a project that has one.
func idEntry(projects *yaml.Node, o FirstRunOptions) (*yaml.Node, bool) {
	if o.ID == "" || strings.EqualFold(o.ID, o.Slug) {
		return nil, false
	}
	for i := 0; i+1 < len(projects.Content); i += 2 {
		if strings.EqualFold(projects.Content[i].Value, o.ID) {
			return projects.Content[i], true
		}
	}

	return nil, false
}

// sameDir fails when the project entry maps another directory than dir. An
// entry with no dir is left to Parse.
func sameDir(project *yaml.Node, slug, dir string) error {
	n, ok := lookup(project, "dir")
	if project.Kind != yaml.MappingNode || !ok || n.Kind != yaml.ScalarNode || n.Value == "" {
		return nil
	}
	mapped, err := expandHome(n.Value)
	if err != nil {
		return err
	}
	if realPath(mapped) == realPath(dir) {
		return nil
	}

	return fmt.Errorf("projects.%s maps %s, not this directory %s: change its dir, or remove the entry, then run loupe init again", slug, n.Value, dir)
}

// realPath resolves the symlinks of a path that exists, and cleans any other.
func realPath(p string) string {
	if real, err := filepath.EvalSymlinks(p); err == nil {
		return real
	}

	return filepath.Clean(p)
}

// editableRules parses the file into a node tree and returns the top mapping.
func editableRules(data []byte) (*yaml.Node, *yaml.Node, error) {
	if _, err := decodeMap(data); err != nil {
		return nil, nil, err
	}
	var doc yaml.Node
	if err := yaml.Unmarshal(data, &doc); err != nil {
		return nil, nil, fmt.Errorf("parse rule file: %w", err)
	}
	if doc.Kind != yaml.DocumentNode || len(doc.Content) != 1 || doc.Content[0].Kind != yaml.MappingNode {
		return nil, nil, errors.New("parse rule file: the first YAML document is not a mapping, so the bridge cannot edit it")
	}

	return &doc, doc.Content[0], nil
}

// encodeRules writes the tree with the indent the bridge uses elsewhere.
func encodeRules(doc *yaml.Node) ([]byte, error) {
	var out bytes.Buffer
	enc := yaml.NewEncoder(&out)
	enc.SetIndent(2)
	if err := enc.Encode(doc); err != nil {
		return nil, fmt.Errorf("encode rule file: %w", err)
	}
	if err := enc.Close(); err != nil {
		return nil, fmt.Errorf("encode rule file: %w", err)
	}

	return out.Bytes(), nil
}

func index(m *yaml.Node, key string) (int, bool) {
	for i := 0; i+1 < len(m.Content); i += 2 {
		if m.Content[i].Value == key {
			return i, true
		}
	}

	return 0, false
}

// setKey replaces the value of key in the mapping, or appends the key.
func setKey(m *yaml.Node, key string, value *yaml.Node) {
	if i, ok := index(m, key); ok {
		m.Content[i+1] = value

		return
	}
	m.Content = append(m.Content, scalarNode(key), value)
}

func lookup(m *yaml.Node, key string) (*yaml.Node, bool) {
	i, ok := index(m, key)
	if !ok {
		return nil, false
	}

	return m.Content[i+1], true
}

func isNull(n *yaml.Node) bool {
	return n.Kind == yaml.ScalarNode && n.Tag == "!!null"
}

// childMapping returns the mapping under key in m. It creates the mapping when
// the key is absent or empty, and fails when the key holds something else.
func childMapping(m *yaml.Node, key string) (*yaml.Node, error) {
	i, ok := index(m, key)
	if !ok {
		child := &yaml.Node{Kind: yaml.MappingNode, Tag: "!!map"}
		m.Content = append(m.Content, scalarNode(key), child)

		return child, nil
	}
	switch child := m.Content[i+1]; {
	case child.Kind == yaml.MappingNode:
		return child, nil
	case isNull(child):
		m.Content[i+1] = &yaml.Node{Kind: yaml.MappingNode, Tag: "!!map"}

		return m.Content[i+1], nil
	}

	return nil, fmt.Errorf("parse rule file: %s is not a mapping, so the bridge cannot edit it", key)
}

func scalarNode(v string) *yaml.Node {
	return &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!str", Value: v}
}

func boolNode(v bool) *yaml.Node {
	return &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!bool", Value: strings.ToLower(fmt.Sprint(v))}
}

// mappingNode builds a mapping from key and value pairs, where each key is a
// string and each value is a node.
func mappingNode(pairs ...any) *yaml.Node {
	m := &yaml.Node{Kind: yaml.MappingNode, Tag: "!!map"}
	for i := 0; i+1 < len(pairs); i += 2 {
		m.Content = append(m.Content, scalarNode(pairs[i].(string)), pairs[i+1].(*yaml.Node))
	}

	return m
}

func projectsNode(o FirstRunOptions) *yaml.Node {
	return mappingNode(o.Slug, mappingNode("dir", scalarNode(o.Dir)))
}

func launchNode(argv []string) *yaml.Node {
	seq := &yaml.Node{Kind: yaml.SequenceNode, Tag: "!!seq", Style: yaml.FlowStyle}
	for _, arg := range argv {
		n := scalarNode(arg)
		n.Style = yaml.DoubleQuotedStyle
		seq.Content = append(seq.Content, n)
	}

	return mappingNode("command", seq)
}

func productDesignNode() *yaml.Node {
	return mappingNode("action", scalarNode(ActionInteractive), "prompt", scalarNode(productDesignPrompt))
}

func techDesignSessionNode() *yaml.Node {
	return mappingNode("action", scalarNode(ActionInteractive), "prompt", scalarNode(techDesignSessionPrompt))
}
