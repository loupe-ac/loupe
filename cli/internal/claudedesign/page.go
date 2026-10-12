package claudedesign

import (
	"bytes"
	"encoding/json"
	"fmt"
	"path"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"

	"golang.org/x/net/html"

	"github.com/ubermuda/loupe/cli/internal/artifactcompile"
)

const lineAttr = "data-loupe-line"

var eventAttrRe = regexp.MustCompile(`^on[a-z]+$`)

// injectLines writes the source line of each start tag into the tag, because
// the parser keeps no line numbers.
func injectLines(src []byte) []byte {
	z := html.NewTokenizer(bytes.NewReader(src))
	var out bytes.Buffer
	line := 1
	for {
		tt := z.Next()
		if tt == html.ErrorToken {
			break
		}
		raw := append([]byte(nil), z.Raw()...)
		if tt == html.StartTagToken || tt == html.SelfClosingTagToken {
			name, _ := z.TagName()
			at := 1 + len(name)
			if at <= len(raw) {
				attr := fmt.Sprintf(" %s=\"%d\"", lineAttr, line)
				raw = append(raw[:at], append([]byte(attr), raw[at:]...)...)
			}
		}
		out.Write(raw)
		line += bytes.Count(raw, []byte("\n"))
	}

	return out.Bytes()
}

type problem struct{ feature, detail string }

type page struct {
	file string
	gaps []artifactcompile.Gap
	// links gives the page path of each canvas in the push.
	links map[string]string
	// blobs holds the pushed files under _blob/.
	blobs blobFiles
	// out is the path the page is written to.
	out string
	// kept holds the markers and the source fragments kept after them.
	kept map[*html.Node]bool
	// keptSelf holds the nodes that keep source text after a marker. Their
	// children are still checked.
	keptSelf map[*html.Node]bool
	// interactive says that an event attribute with a plain hole is not a gap,
	// because the page ships a redraw runtime that binds it.
	interactive bool
	// props holds the prop defaults of the canvas script.
	props map[string]any
}

func (p *page) addGap(n *html.Node, pr problem) *html.Node {
	p.gaps = append(p.gaps, artifactcompile.Gap{File: p.file, Line: lineOf(n), Feature: pr.feature, Detail: pr.detail})

	return p.marker(p.gaps[len(p.gaps)-1])
}

func (p *page) marker(g artifactcompile.Gap) *html.Node {
	m := &html.Node{Type: html.CommentNode, Data: strings.TrimSuffix(strings.TrimPrefix(Marker(g), "<!--"), "-->")}
	p.kept[m] = true

	return m
}

func (p *page) keep(n *html.Node) *html.Node {
	p.kept[n] = true

	return n
}

func lineOf(n *html.Node) int {
	for ; n != nil; n = n.Parent {
		if n.Type != html.ElementNode {
			continue
		}
		for _, a := range n.Attr {
			if a.Key == lineAttr {
				l, _ := strconv.Atoi(a.Val)

				return l
			}
		}
	}

	return 1
}

func attrOf(n *html.Node, key string) (string, bool) {
	for _, a := range n.Attr {
		if a.Key == key {
			return a.Val, true
		}
	}

	return "", false
}

func find(n *html.Node, match func(*html.Node) bool) *html.Node {
	if n.Type == html.ElementNode && match(n) {
		return n
	}
	for c := n.FirstChild; c != nil; c = c.NextSibling {
		if f := find(c, match); f != nil {
			return f
		}
	}

	return nil
}

func isTag(name string) func(*html.Node) bool {
	return func(n *html.Node) bool { return n.Data == name }
}

// compilePage compiles one canvas file to the page at out. A page with events
// or timers ships its script and a redraw runtime. A page with any gap falls
// back to the static page, with the event attributes as gaps and no script.
func compilePage(file, out string, src []byte, links map[string]string, blobs blobFiles) ([]byte, []artifactcompile.Gap) {
	built, gaps, retry := buildPage(file, out, src, links, blobs, true)
	if retry {
		built, gaps, _ = buildPage(file, out, src, links, blobs, false)
	}

	return built, gaps
}

// buildPage builds a page. retry is true when the page wants to be interactive
// and has a gap, so the caller must build the static page instead.
func buildPage(file, out string, src []byte, links map[string]string, blobs blobFiles, mayBeInteractive bool) ([]byte, []artifactcompile.Gap, bool) {
	p := &page{file: file, out: out, links: links, blobs: blobs, kept: map[*html.Node]bool{}, keptSelf: map[*html.Node]bool{}}
	doc, err := html.Parse(bytes.NewReader(injectLines(src)))
	if err != nil {
		return markTop(src, artifactcompile.Gap{File: file, Line: 1, Feature: "markup that does not parse", Detail: err.Error()}),
			[]artifactcompile.Gap{{File: file, Line: 1, Feature: "markup that does not parse", Detail: err.Error()}}, false
	}
	root := find(doc, isTag("x-dc"))
	if root == nil {
		g := artifactcompile.Gap{File: file, Line: 1, Feature: "a file with no <x-dc> element", Detail: "the file is not a canvas"}

		return markTop(src, g), []artifactcompile.Gap{g}, false
	}

	scope := map[string]any{}
	script := find(doc, isCanvasScript)
	if script != nil {
		scope = p.scriptScope(script)
	}
	wantsRuntime := mayBeInteractive && script != nil && wantsInteractive(root, script)

	head := &html.Node{Type: html.ElementNode, Data: "head"}
	body := &html.Node{Type: html.ElementNode, Data: "body"}
	head.AppendChild(&html.Node{Type: html.ElementNode, Data: "meta", Attr: []html.Attribute{{Key: "charset", Val: "utf-8"}}})
	head.AppendChild(&html.Node{Type: html.ElementNode, Data: "meta", Attr: []html.Attribute{{Key: "name", Val: "viewport"}, {Key: "content", Val: "width=device-width, initial-scale=1"}}})
	scriptGaps := p.gaps
	if oldHead := find(doc, isTag("head")); oldHead != nil {
		for c := oldHead.FirstChild; c != nil; c = c.NextSibling {
			if dropFromHead(c) || blank(c) {
				continue
			}
			for _, n := range p.expandNode(c, scope) {
				head.AppendChild(n)
			}
		}
	}
	for _, g := range scriptGaps {
		body.AppendChild(p.marker(g))
	}
	if len(scriptGaps) > 0 {
		body.AppendChild(p.keep(cloneDeep(script)))
	}
	for c := root.FirstChild; c != nil; c = c.NextSibling {
		dest := body
		if c.Type == html.ElementNode && c.Data == "helmet" {
			dest = head
			for _, n := range p.expandChildren(c, scope) {
				if !blank(n) {
					dest.AppendChild(n)
				}
			}

			continue
		}
		p.interactive = wantsRuntime
		for _, n := range p.expandNode(c, scope) {
			dest.AppendChild(n)
		}
		p.interactive = false
	}

	tree := &html.Node{Type: html.DocumentNode}
	tree.AppendChild(&html.Node{Type: html.DoctypeNode, Data: "html"})
	htmlEl := &html.Node{Type: html.ElementNode, Data: "html"}
	tree.AppendChild(htmlEl)
	htmlEl.AppendChild(head)
	htmlEl.AppendChild(body)
	p.markLeftovers(tree)
	stripLines(tree)
	if r, moved := p.rebaser(); moved {
		r.tree(tree)
	}

	if wantsRuntime {
		if len(p.gaps) == 0 {
			if s := p.runtimeScript(root, script); s != nil {
				body.AppendChild(s)
			}
		}
		if len(p.gaps) > 0 {
			return nil, nil, true
		}
	}

	var buf bytes.Buffer
	if err := html.Render(&buf, tree); err != nil {
		g := artifactcompile.Gap{File: file, Line: 1, Feature: "a page that does not render", Detail: err.Error()}

		return markTop(src, g), []artifactcompile.Gap{g}, false
	}

	return buf.Bytes(), p.gaps, false
}

// markLeftovers marks the canvas text left in the page, outside the source
// fragments that a marker already covers.
func (p *page) markLeftovers(n *html.Node) {
	for c := n.FirstChild; c != nil; c = c.NextSibling {
		if p.kept[c] {
			continue
		}
		if bad := leftIn(c); bad != "" && !p.keptSelf[c] {
			n.InsertBefore(p.addGap(c, problem{"canvas text left in the output", fmt.Sprintf("%q is left in the page", bad)}), c)
		}
		p.markLeftovers(c)
	}
}

func leftIn(n *html.Node) string {
	texts := []string{n.Data}
	if n.Type == html.ElementNode {
		texts = []string{"<" + n.Data}
		for _, a := range n.Attr {
			if !eventAttrRe.MatchString(a.Key) {
				texts = append(texts, a.Val)
			}
		}
	}
	for _, t := range texts {
		if bad := forbiddenIn(t); bad != "" {
			return bad
		}
	}

	return ""
}

func blank(n *html.Node) bool {
	return n.Type == html.TextNode && strings.TrimSpace(n.Data) == ""
}

func dropFromHead(n *html.Node) bool {
	if n.Type != html.ElementNode {
		return false
	}
	switch n.Data {
	case "script":
		src, _ := attrOf(n, "src")

		return strings.HasSuffix(src, "support.js") || isCanvasScript(n)
	case "meta":
		_, charset := attrOf(n, "charset")
		name, _ := attrOf(n, "name")

		return charset || strings.EqualFold(name, "viewport")
	}

	return false
}

func isCanvasScript(n *html.Node) bool {
	_, ok := attrOf(n, "data-dc-script")

	return n.Type == html.ElementNode && n.Data == "script" && ok
}

func cloneDeep(n *html.Node) *html.Node {
	c := &html.Node{Type: n.Type, DataAtom: n.DataAtom, Data: n.Data, Namespace: n.Namespace, Attr: append([]html.Attribute(nil), n.Attr...)}
	for k := n.FirstChild; k != nil; k = k.NextSibling {
		c.AppendChild(cloneDeep(k))
	}

	return c
}

func stripLines(n *html.Node) {
	if n.Type == html.ElementNode {
		kept := n.Attr[:0]
		for _, a := range n.Attr {
			if a.Key != lineAttr {
				kept = append(kept, a)
			}
		}
		n.Attr = kept
	}
	for c := n.FirstChild; c != nil; c = c.NextSibling {
		stripLines(c)
	}
}

func scriptSource(script *html.Node) string {
	var src strings.Builder
	for c := script.FirstChild; c != nil; c = c.NextSibling {
		src.WriteString(c.Data)
	}

	return src.String()
}

// scriptScope reads the prop defaults, runs the script, and gives the values.
func (p *page) scriptScope(script *html.Node) map[string]any {
	props := map[string]any{}
	if raw, ok := attrOf(script, "data-props"); ok && strings.TrimSpace(raw) != "" {
		var decl map[string]any
		if err := json.Unmarshal([]byte(raw), &decl); err != nil {
			p.gaps = append(p.gaps, artifactcompile.Gap{File: p.file, Line: lineOf(script), Feature: "data-props that is not JSON", Detail: err.Error()})
		}
		for k, v := range decl {
			if m, ok := v.(map[string]any); ok && k != "$preview" {
				if d, has := m["default"]; has {
					props[k] = d
				}
			}
		}
	}
	p.props = props
	vals, err := runScript(scriptSource(script), props)
	if err != nil {
		p.gaps = append(p.gaps, artifactcompile.Gap{File: p.file, Line: lineOf(script), Feature: "a script that fails", Detail: scriptMessage(err)})

		return map[string]any{}
	}
	if m, ok := vals.(map[string]any); ok {
		return m
	}

	return map[string]any{}
}

func (p *page) expandChildren(n *html.Node, scope map[string]any) []*html.Node {
	var out []*html.Node
	for c := n.FirstChild; c != nil; c = c.NextSibling {
		out = append(out, p.expandNode(c, scope)...)
	}

	return out
}

// rawGap marks a node the compiler cannot handle, and keeps its source.
func (p *page) rawGap(n *html.Node, pr problem) []*html.Node {
	return []*html.Node{p.addGap(n, pr), p.keep(cloneDeep(n))}
}

func (p *page) expandNode(n *html.Node, scope map[string]any) []*html.Node {
	switch n.Type {
	case html.TextNode:
		return p.expandText(n, scope)
	case html.ElementNode:
	default:
		return []*html.Node{cloneDeep(n)}
	}

	switch {
	case isCanvasScript(n):
		return nil
	case n.Data == "sc-for":
		return p.expandFor(n, scope)
	case n.Data == "sc-if":
		return p.expandIf(n, scope)
	case n.Data == "sc-else":
		return p.rawGap(n, problem{"<sc-else>", "write the else branch by hand"})
	case strings.HasPrefix(n.Data, "dc-"):
		return p.rawGap(n, problem{"<" + n.Data + "> (an embedded artboard)", "paste the markup of the artboard here"})
	}

	return p.expandElement(n, scope)
}

func (p *page) expandFor(n *html.Node, scope map[string]any) []*html.Node {
	listAttr, _ := attrOf(n, "list")
	as, _ := attrOf(n, "as")
	path, ok := plainHole(listAttr)
	if !ok || as == "" {
		return p.rawGap(n, problem{"<sc-for> without a plain list and as", fmt.Sprintf("list=%q as=%q", listAttr, as)})
	}
	v, _ := lookup(scope, path)
	items, _ := v.([]any)
	var out []*html.Node
	for _, item := range items {
		inner := make(map[string]any, len(scope)+1)
		for k, x := range scope {
			inner[k] = x
		}
		inner[as] = item
		out = append(out, p.expandChildren(n, inner)...)
	}

	if len(items) == 0 {
		return p.pruned(n, without(scope, as))
	}

	return out
}

// pruned drops a branch that does not render. An unsupported feature inside it
// is still a gap, also one that a known value puts there, so the branch then
// stays as source after its markers.
func (p *page) pruned(n *html.Node, scope map[string]any) []*html.Node {
	var marks []*html.Node
	var walk func(*html.Node, map[string]any)
	walk = func(c *html.Node, scope map[string]any) {
		var problems []problem
		switch c.Type {
		case html.TextNode:
			problems = p.textProblems(c)
			if len(problems) == 0 && (c.Parent == nil || (c.Parent.Data != "style" && c.Parent.Data != "script")) {
				problems = valueProblems(c.Data, scope)
			}
		case html.ElementNode:
			problems = p.elementProblems(c, true)
			var inner []*html.Node
			problems, inner = p.attrValueProblems(c, scope, problems)
			marks = append(marks, inner...)
			if as, _ := attrOf(c, "as"); c.Data == "sc-for" && as != "" {
				scope = without(scope, as)
			}
		}
		for _, pr := range problems {
			marks = append(marks, p.addGap(c, pr))
		}
		for k := c.FirstChild; k != nil; k = k.NextSibling {
			walk(k, scope)
		}
	}
	for c := n.FirstChild; c != nil; c = c.NextSibling {
		walk(c, scope)
	}
	if len(marks) == 0 {
		return nil
	}

	return append(marks, p.keep(cloneDeep(n)))
}

// without gives the values with one name hidden, such as the item of a loop
// whose value no pruned branch knows.
func without(scope map[string]any, name string) map[string]any {
	if _, ok := scope[name]; !ok {
		return scope
	}
	out := make(map[string]any, len(scope))
	for k, v := range scope {
		if k != name {
			out[k] = v
		}
	}

	return out
}

// attrValueProblems adds the problems that known values put in the attributes
// of an element that does not render. Markup from a value comes back as the
// markers of its own gaps.
func (p *page) attrValueProblems(n *html.Node, scope map[string]any, out []problem) ([]problem, []*html.Node) {
	var marks []*html.Node
	for _, a := range n.Attr {
		switch {
		case a.Key == lineAttr, strings.HasPrefix(a.Key, "hint-placeholder-"), controlAttr(n, a.Key), eventAttrRe.MatchString(a.Key):
		case a.Key == "dangerouslysetinnerhtml":
			path, ok := plainHole(a.Val)
			if !ok {
				continue
			}
			if _, found := lookup(scope, path); !found {
				continue
			}
			markup, pr := innerHTML(a.Val, scope)
			if pr != nil {
				out = append(out, *pr)

				continue
			}
			host := &html.Node{Type: html.ElementNode, DataAtom: n.DataAtom, Data: n.Data, Namespace: n.Namespace, Attr: n.Attr}
			frags, err := html.ParseFragment(strings.NewReader(markup), host)
			if err != nil {
				out = append(out, problem{"dangerouslySetInnerHTML that does not parse", err.Error()})
			}
			for _, f := range frags {
				host.AppendChild(f)
				marks = append(marks, p.checkFragment(f)...)
			}
		default:
			problems := valueProblems(a.Val, scope)
			if val, known := fillKnown(a.Val, scope); a.Key == "href" && known && holeRe.MatchString(a.Val) && len(problems) == 0 && len(holeProblems(a.Val)) == 0 {
				if _, ok := p.rewriteLink(val); !ok {
					problems = append(problems, missingLink(val))
				}
			}
			out = append(out, problems...)
		}
	}

	return out, marks
}

// valueProblems gives the problems that known values put in a string. A hole
// with no known value is left alone, because the branch may never fill it.
func valueProblems(s string, scope map[string]any) []problem {
	if !holeRe.MatchString(s) || len(holeProblems(s)) > 0 {
		return nil
	}
	var out []problem
	for _, m := range holeRe.FindAllStringSubmatch(s, -1) {
		v, found := lookup(scope, m[1])
		if !found {
			continue
		}
		t, ok := text(v)
		if !ok {
			out = append(out, problem{"a hole whose value is not text", m[0]})
		} else if strings.Contains(t, "/_blob/") {
			out = append(out, problem{"a /_blob/ path", m[0] + " gives " + t})
		}
	}

	return out
}

// fillKnown fills the holes of a string from known values. known is false when
// a hole has no known text.
func fillKnown(s string, scope map[string]any) (string, bool) {
	known := true
	out := holeRe.ReplaceAllStringFunc(s, func(h string) string {
		v, found := lookup(scope, holeRe.FindStringSubmatch(h)[1])
		t, ok := text(v)
		if !found || !ok {
			known = false

			return h
		}

		return t
	})

	return out, known
}

// elementProblems gives the problems of an element that hold whatever the
// values, as the expansion finds them. A templated element is one that the
// page redraws, so an interactive page binds its plain event holes.
func (p *page) elementProblems(n *html.Node, templated bool) []problem {
	var out []problem
	switch {
	case n.Data == "sc-else":
		out = append(out, problem{"<sc-else>", "write the else branch by hand"})
	case strings.HasPrefix(n.Data, "dc-"):
		out = append(out, problem{"<" + n.Data + "> (an embedded artboard)", "paste the markup of the artboard here"})
	case n.Data == "sc-for":
		listAttr, _ := attrOf(n, "list")
		as, _ := attrOf(n, "as")
		if _, ok := plainHole(listAttr); !ok || as == "" {
			out = append(out, problem{"<sc-for> without a plain list and as", fmt.Sprintf("list=%q as=%q", listAttr, as)})
		}
	case n.Data == "sc-if":
		val, _ := attrOf(n, "value")
		if _, ok := plainHole(val); !ok {
			out = append(out, problem{"<sc-if> without a plain value", fmt.Sprintf("value=%q", val)})
		}
	}
	for _, a := range n.Attr {
		switch {
		case a.Key == lineAttr, strings.HasPrefix(a.Key, "hint-placeholder-"), controlAttr(n, a.Key):
		case eventAttrRe.MatchString(a.Key):
			if !(templated && p.interactive && plainHoleOK(a.Val)) {
				out = append(out, eventProblem(a))
			}
		case a.Key == "dangerouslysetinnerhtml":
			if _, ok := plainHole(a.Val); !ok {
				out = append(out, problem{"dangerouslySetInnerHTML without a plain path", a.Val})
			}
		default:
			_, blobProblems := p.rewriteBlobs(a.Val)
			problems := append(holeProblems(a.Val), blobProblems...)
			if a.Key == "href" && len(problems) == 0 && !holeRe.MatchString(a.Val) {
				if _, ok := p.rewriteLink(a.Val); !ok {
					problems = append(problems, missingLink(a.Val))
				}
			}
			out = append(out, problems...)
		}
	}

	return out
}

func missingLink(href string) problem {
	return problem{"a link to an artboard that is not in the push", fmt.Sprintf("href=%q", href)}
}

// controlAttr says whether an attribute steers an <sc-for> or an <sc-if>.
func controlAttr(n *html.Node, key string) bool {
	return (n.Data == "sc-for" && (key == "list" || key == "as")) || (n.Data == "sc-if" && key == "value")
}

// textProblems gives the problems of a text node that hold whatever the values.
func (p *page) textProblems(n *html.Node) []problem {
	if n.Parent != nil && n.Parent.Data == "style" {
		_, problems := p.rewriteBlobs(n.Data)

		return problems
	}
	if n.Parent != nil && n.Parent.Data == "script" {
		if strings.Contains(n.Data, "/_blob/") {
			return []problem{{"a /_blob/ path", "in " + n.Parent.Data}}
		}

		return nil
	}

	return sourceProblems(n.Data)
}

// sourceProblems gives the problems of a string that hold whatever the values.
func sourceProblems(s string) []problem {
	var out []problem
	if strings.Contains(s, "/_blob/") {
		out = append(out, problem{"a /_blob/ path", s})
	}

	return append(out, holeProblems(s)...)
}

// holeProblems gives the problems of the holes of a string.
func holeProblems(s string) []problem {
	var out []problem
	for _, m := range holeRe.FindAllStringSubmatch(s, -1) {
		if !pathRe.MatchString(m[1]) {
			out = append(out, problem{"a hole that is not a plain path", m[0]})
		}
	}

	return out
}

func plainHoleOK(s string) bool {
	_, ok := plainHole(s)

	return ok
}

// plainHole gives the path of a string that is one plain hole.
func plainHole(s string) (string, bool) {
	m := soleRe.FindStringSubmatch(s)
	if m == nil || !pathRe.MatchString(m[1]) {
		return "", false
	}

	return m[1], true
}

func eventProblem(a html.Attribute) problem {
	return problem{"event attribute " + a.Key, fmt.Sprintf("%s=%q needs a handler written by hand", a.Key, a.Val)}
}

func (p *page) expandIf(n *html.Node, scope map[string]any) []*html.Node {
	val, _ := attrOf(n, "value")
	path, ok := plainHole(val)
	if !ok {
		return p.rawGap(n, problem{"<sc-if> without a plain value", fmt.Sprintf("value=%q", val)})
	}
	if v, found := lookup(scope, path); !found || !truthy(v) {
		return p.pruned(n, scope)
	}

	return p.expandChildren(n, scope)
}

// interpolate fills the holes of a string. A hole it cannot fill keeps its
// source and comes back as a problem.
func interpolate(s string, scope map[string]any) (string, []problem) {
	out, problems := fill(s, scope)

	return out, append(sourceProblems(s), problems...)
}

// interpolateAttr fills the holes of an attribute value, and points its blob
// paths at the pushed files.
func (p *page) interpolateAttr(s string, scope map[string]any) (string, []problem) {
	out, problems := fill(s, scope)
	problems = append(holeProblems(s), problems...)
	out, blobProblems := p.rewriteBlobs(out)

	return out, append(problems, blobProblems...)
}

// fill fills the holes of a string and gives the problems of their values.
func fill(s string, scope map[string]any) (string, []problem) {
	var problems []problem
	out := holeRe.ReplaceAllStringFunc(s, func(h string) string {
		path := holeRe.FindStringSubmatch(h)[1]
		if !pathRe.MatchString(path) {
			return h
		}
		v, _ := lookup(scope, path)
		t, ok := text(v)
		if !ok {
			problems = append(problems, problem{"a hole whose value is not text", h})

			return h
		}
		if strings.Contains(t, "/_blob/") {
			problems = append(problems, problem{"a /_blob/ path", h + " gives " + t})

			return h
		}

		return t
	})

	return out, problems
}

func (p *page) expandText(n *html.Node, scope map[string]any) []*html.Node {
	if n.Parent != nil && (n.Parent.Data == "style" || n.Parent.Data == "script") {
		var out []*html.Node
		t := cloneDeep(n)
		var problems []problem
		if n.Parent.Data == "style" {
			t.Data, problems = p.rewriteBlobs(n.Data)
		} else {
			problems = p.textProblems(n)
		}
		for _, pr := range problems {
			out = append(out, p.addGap(n, pr))
		}
		if len(out) > 0 {
			p.keptSelf[t] = true
		}

		return append(out, t)
	}
	s, problems := interpolate(n.Data, scope)
	var out []*html.Node
	for _, pr := range problems {
		out = append(out, p.addGap(n, pr))
	}
	t := &html.Node{Type: html.TextNode, Data: s}
	if len(problems) > 0 {
		p.keptSelf[t] = true
	}

	return append(out, t)
}

func (p *page) expandElement(n *html.Node, scope map[string]any) []*html.Node {
	el := &html.Node{Type: html.ElementNode, DataAtom: n.DataAtom, Data: n.Data, Namespace: n.Namespace}
	var marks []*html.Node
	var inner *string
	for _, a := range n.Attr {
		switch {
		case a.Key == lineAttr:
			el.Attr = append(el.Attr, a)
		case strings.HasPrefix(a.Key, "hint-placeholder-"):
		case eventAttrRe.MatchString(a.Key):
			if p.interactive && plainHoleOK(a.Val) {
				continue
			}
			marks = append(marks, p.addGap(n, eventProblem(a)))
			el.Attr = append(el.Attr, a)
		case a.Key == "dangerouslysetinnerhtml":
			markup, pr := innerHTML(a.Val, scope)
			if pr != nil {
				marks = append(marks, p.addGap(n, *pr))
				el.Attr = append(el.Attr, a)

				continue
			}
			inner = &markup
		default:
			val, problems := p.interpolateAttr(a.Val, scope)
			for _, pr := range problems {
				marks = append(marks, p.addGap(n, pr))
			}
			if a.Key == "href" && len(problems) == 0 {
				link, ok := p.rewriteLink(val)
				if !ok {
					marks = append(marks, p.addGap(n, missingLink(val)))
				}
				val = link
			}
			el.Attr = append(el.Attr, html.Attribute{Namespace: a.Namespace, Key: a.Key, Val: val})
		}
	}
	if inner != nil {
		frags, err := html.ParseFragment(strings.NewReader(*inner), el)
		if err != nil {
			marks = append(marks, p.addGap(n, problem{"dangerouslySetInnerHTML that does not parse", err.Error()}))
		}
		for _, f := range frags {
			el.AppendChild(f)
			marks = append(marks, p.checkFragment(f)...)
		}
	} else {
		for _, c := range p.expandChildren(n, scope) {
			el.AppendChild(c)
		}
	}

	if len(marks) > 0 {
		p.keptSelf[el] = true
	}

	return append(marks, el)
}

// innerHTML gives the markup of a dangerouslySetInnerHTML hole.
func innerHTML(val string, scope map[string]any) (string, *problem) {
	path, ok := plainHole(val)
	if !ok {
		return "", &problem{"dangerouslySetInnerHTML without a plain path", val}
	}
	v, _ := lookup(scope, path)
	if obj, ok := v.(map[string]any); ok {
		v = obj["__html"]
	}
	s, ok := text(v)
	if !ok {
		return "", &problem{"dangerouslySetInnerHTML without markup text", val}
	}
	if strings.Contains(s, "/_blob/") {
		return "", &problem{"a /_blob/ path", val + " gives " + s}
	}

	return s, nil
}

// checkFragment marks the unsupported features of markup that a value gives,
// and points its links to a pushed artboard at the compiled page. The node
// stays after its markers, and its children are still checked.
func (p *page) checkFragment(n *html.Node) []*html.Node {
	var marks []*html.Node
	var problems []problem
	switch n.Type {
	case html.TextNode:
		problems = p.textProblems(n)
	case html.ElementNode:
		problems = p.elementProblems(n, false)
		if v, ok := attrOf(n, "dangerouslysetinnerhtml"); ok && plainHoleOK(v) {
			problems = append(problems, problem{"dangerouslySetInnerHTML inside markup from a value", "write the markup by hand"})
		}
		if len(problems) == 0 {
			for i, a := range n.Attr {
				if a.Key == "href" {
					n.Attr[i].Val, _ = p.rewriteLink(a.Val)
				}
			}
		}
	}
	for _, pr := range problems {
		marks = append(marks, p.addGap(n, pr))
	}
	if len(problems) > 0 {
		p.keptSelf[n] = true
	}
	for c := n.FirstChild; c != nil; c = c.NextSibling {
		marks = append(marks, p.checkFragment(c)...)
	}

	return marks
}

// rewriteLink points a link to a canvas at the compiled page. It is false for
// a canvas that the push does not hold, and the link then keeps its source.
func (p *page) rewriteLink(href string) (string, bool) {
	if strings.Contains(href, "://") || strings.HasPrefix(href, "//") || strings.HasPrefix(href, "/") {
		return href, true
	}
	base, rest := href, ""
	if i := strings.IndexAny(href, "?#"); i >= 0 {
		base, rest = href[:i], href[i:]
	}
	if !strings.HasSuffix(base, canvasSuffix) {
		return href, true
	}
	out, ok := p.links[path.Join(path.Dir(p.file), base)]
	if !ok {
		return href, false
	}
	rel, err := filepath.Rel(filepath.FromSlash(path.Dir(p.file)), filepath.FromSlash(out))
	if err != nil {
		return href, false
	}

	return filepath.ToSlash(rel) + rest, true
}

var timerRe = regexp.MustCompile(`componentDidMount|setInterval|setTimeout`)

// wantsInteractive says whether a canvas needs its script at run time: the
// template binds an event to a plain hole, or the script starts timers or
// runs a mount hook.
func wantsInteractive(root, script *html.Node) bool {
	if timerRe.MatchString(scriptSource(script)) {
		return true
	}
	var walk func(*html.Node) bool
	walk = func(n *html.Node) bool {
		if n.Type == html.ElementNode {
			for _, a := range n.Attr {
				if eventAttrRe.MatchString(a.Key) && plainHoleOK(a.Val) {
					return true
				}
			}
		}
		for c := n.FirstChild; c != nil; c = c.NextSibling {
			if walk(c) {
				return true
			}
		}

		return false
	}
	for c := root.FirstChild; c != nil; c = c.NextSibling {
		if c.Type == html.ElementNode && c.Data == "helmet" {
			continue
		}
		if walk(c) {
			return true
		}
	}

	return false
}

var (
	dcLogicRe     = regexp.MustCompile(`\bDCLogic\b`)
	scriptCloseRe = regexp.MustCompile(`(?i)</(script)`)
)

// shipScript makes the canvas script safe to put in a script element, and
// points it at the redraw runtime.
func shipScript(src string) string {
	src = dcLogicRe.ReplaceAllString(src, "LoupeView")
	src = scriptCloseRe.ReplaceAllString(src, `<\/${1}`)

	return strings.ReplaceAll(src, "<!--", `<\!--`)
}

// linkMap maps each canvas, as a link on this page writes it, to its page.
func (p *page) linkMap() map[string]string {
	dir := filepath.FromSlash(path.Dir(p.file))
	out := map[string]string{}
	for canvas, page := range p.links {
		key, err := filepath.Rel(dir, filepath.FromSlash(canvas))
		if err != nil {
			continue
		}
		val, err := filepath.Rel(dir, filepath.FromSlash(page))
		if err != nil {
			continue
		}
		out[filepath.ToSlash(key)] = filepath.ToSlash(val)
	}

	return out
}

// runtimeScript builds the script element of an interactive page. It adds a
// gap, and gives nil, when the shipped text holds text a page must not hold.
func (p *page) runtimeScript(root, script *html.Node) *html.Node {
	line := lineOf(script)
	fail := func(feature, detail string) *html.Node {
		p.gaps = append(p.gaps, artifactcompile.Gap{File: p.file, Line: line, Feature: feature, Detail: detail})

		return nil
	}
	render, events, blobs, err := p.genRender(root)
	if err != nil {
		return fail("a template the redraw cannot write", err.Error())
	}
	props, perr := jsonJS(p.props)
	if perr != nil {
		return fail("data-props that is not JSON", perr.Error())
	}
	evJSON, _ := jsonJS(events)
	linkJSON, _ := jsonJS(p.linkMap())
	blobJSON, _ := jsonJS(blobs)
	baseJSON := "null"
	if r, moved := p.rebaser(); moved {
		baseJSON, _ = jsonJS(map[string]string{"from": r.from, "to": r.to})
	}
	code := "(function () {\n" + runtimeJS + "\n" + shipScript(scriptSource(script)) + "\n" + render +
		"\nLoupeView.mount(Component, render, " + props + ", " + evJSON + ", " + linkJSON + ", " + baseJSON + ", " + blobJSON + ");\n})();\n"
	if strings.Contains(strings.ToLower(code), "</script") {
		return fail("canvas text left in the output", `"</script" is left in the shipped script`)
	}
	if bad := forbiddenIn(code); bad != "" {
		return fail("canvas text left in the output", fmt.Sprintf("%q is left in the shipped script", bad))
	}
	el := &html.Node{Type: html.ElementNode, Data: "script", Attr: []html.Attribute{{Key: "id", Val: "loupe-runtime"}}}
	el.AppendChild(&html.Node{Type: html.TextNode, Data: code})

	return el
}
