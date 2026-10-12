package claudedesign

import (
	"bytes"
	"encoding/json"
	"fmt"
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
}

func (p *page) addGap(n *html.Node, pr problem) *html.Node {
	p.gaps = append(p.gaps, artifactcompile.Gap{File: p.file, Line: lineOf(n), Feature: pr.feature, Detail: pr.detail})

	return &html.Node{Type: html.CommentNode, Data: strings.TrimSuffix(strings.TrimPrefix(Marker(p.gaps[len(p.gaps)-1]), "<!--"), "-->")}
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

// compilePage compiles one canvas file to a page.
func compilePage(file string, src []byte) ([]byte, []artifactcompile.Gap) {
	p := &page{file: file}
	doc, err := html.Parse(bytes.NewReader(injectLines(src)))
	if err != nil {
		return markTop(src, artifactcompile.Gap{File: file, Line: 1, Feature: "markup that does not parse", Detail: err.Error()}),
			[]artifactcompile.Gap{{File: file, Line: 1, Feature: "markup that does not parse", Detail: err.Error()}}
	}
	root := find(doc, isTag("x-dc"))
	if root == nil {
		g := artifactcompile.Gap{File: file, Line: 1, Feature: "a file with no <x-dc> element", Detail: "the file is not a canvas"}

		return markTop(src, g), []artifactcompile.Gap{g}
	}

	scope := map[string]any{}
	if script := find(doc, func(n *html.Node) bool { _, ok := attrOf(n, "data-dc-script"); return n.Data == "script" && ok }); script != nil {
		scope = p.scriptScope(script)
	}

	head := &html.Node{Type: html.ElementNode, Data: "head"}
	body := &html.Node{Type: html.ElementNode, Data: "body"}
	head.AppendChild(&html.Node{Type: html.ElementNode, Data: "meta", Attr: []html.Attribute{{Key: "charset", Val: "utf-8"}}})
	head.AppendChild(&html.Node{Type: html.ElementNode, Data: "meta", Attr: []html.Attribute{{Key: "name", Val: "viewport"}, {Key: "content", Val: "width=device-width, initial-scale=1"}}})
	if oldHead := find(doc, isTag("head")); oldHead != nil {
		for c := oldHead.FirstChild; c != nil; c = c.NextSibling {
			if !dropFromHead(c) && !blank(c) {
				head.AppendChild(cloneDeep(c))
			}
		}
	}
	for _, g := range p.gaps {
		body.AppendChild(&html.Node{Type: html.CommentNode, Data: strings.TrimSuffix(strings.TrimPrefix(Marker(g), "<!--"), "-->")})
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
		for _, n := range p.expandNode(c, scope) {
			dest.AppendChild(n)
		}
	}

	out := &html.Node{Type: html.DocumentNode}
	out.AppendChild(&html.Node{Type: html.DoctypeNode, Data: "html"})
	htmlEl := &html.Node{Type: html.ElementNode, Data: "html"}
	out.AppendChild(htmlEl)
	htmlEl.AppendChild(head)
	htmlEl.AppendChild(body)
	stripLines(out)

	var buf bytes.Buffer
	if err := html.Render(&buf, out); err != nil {
		g := artifactcompile.Gap{File: file, Line: 1, Feature: "a page that does not render", Detail: err.Error()}

		return markTop(src, g), []artifactcompile.Gap{g}
	}
	if len(p.gaps) > 0 {
		return buf.Bytes(), p.gaps
	}

	return checkOutput(file, buf.Bytes())
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

		return strings.HasSuffix(src, "support.js")
	case "meta":
		_, charset := attrOf(n, "charset")
		name, _ := attrOf(n, "name")

		return charset || strings.EqualFold(name, "viewport")
	}

	return false
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
	var src strings.Builder
	for c := script.FirstChild; c != nil; c = c.NextSibling {
		src.WriteString(c.Data)
	}
	vals, err := runScript(src.String(), props)
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
	return []*html.Node{p.addGap(n, pr), cloneDeep(n)}
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

func (p *page) holePath(s string) (string, bool) {
	m := soleRe.FindStringSubmatch(s)
	if m == nil || !pathRe.MatchString(m[1]) {
		return "", false
	}

	return m[1], true
}

func (p *page) expandFor(n *html.Node, scope map[string]any) []*html.Node {
	listAttr, _ := attrOf(n, "list")
	as, _ := attrOf(n, "as")
	path, ok := p.holePath(listAttr)
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

	return out
}

func (p *page) expandIf(n *html.Node, scope map[string]any) []*html.Node {
	val, _ := attrOf(n, "value")
	path, ok := p.holePath(val)
	if !ok {
		return p.rawGap(n, problem{"<sc-if> without a plain value", fmt.Sprintf("value=%q", val)})
	}
	if v, found := lookup(scope, path); !found || !truthy(v) {
		return nil
	}

	return p.expandChildren(n, scope)
}

// interpolate fills the holes of a string. A hole it cannot fill prints as
// nothing and comes back as a problem.
func interpolate(s string, scope map[string]any) (string, []problem) {
	var problems []problem
	if strings.Contains(s, "/_blob/") {
		problems = append(problems, problem{"a /_blob/ path", s})
	}
	out := holeRe.ReplaceAllStringFunc(s, func(h string) string {
		path := holeRe.FindStringSubmatch(h)[1]
		if !pathRe.MatchString(path) {
			problems = append(problems, problem{"a hole that is not a plain path", h})

			return ""
		}
		v, _ := lookup(scope, path)
		t, ok := text(v)
		if !ok {
			problems = append(problems, problem{"a hole whose value is not text", h})
		}

		return t
	})

	return out, problems
}

func (p *page) expandText(n *html.Node, scope map[string]any) []*html.Node {
	if n.Parent != nil && (n.Parent.Data == "style" || n.Parent.Data == "script") {
		var out []*html.Node
		if strings.Contains(n.Data, "/_blob/") {
			out = append(out, p.addGap(n, problem{"a /_blob/ path", "in " + n.Parent.Data}))
		}

		return append(out, cloneDeep(n))
	}
	s, problems := interpolate(n.Data, scope)
	var out []*html.Node
	for _, pr := range problems {
		out = append(out, p.addGap(n, pr))
	}

	return append(out, &html.Node{Type: html.TextNode, Data: s})
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
			marks = append(marks, p.addGap(n, problem{"event attribute " + a.Key, fmt.Sprintf("%s=%q was removed", a.Key, a.Val)}))
		case a.Key == "dangerouslysetinnerhtml":
			markup, pr := innerHTML(a.Val, scope)
			if pr != nil {
				marks = append(marks, p.addGap(n, *pr))
			}
			inner = &markup
		default:
			val, problems := interpolate(a.Val, scope)
			for _, pr := range problems {
				marks = append(marks, p.addGap(n, pr))
			}
			if a.Key == "href" {
				val = rewriteLink(val)
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
		}
	} else {
		for _, c := range p.expandChildren(n, scope) {
			el.AppendChild(c)
		}
	}

	return append(marks, el)
}

// innerHTML gives the markup of a dangerouslySetInnerHTML hole.
func innerHTML(val string, scope map[string]any) (string, *problem) {
	m := soleRe.FindStringSubmatch(val)
	if m == nil || !pathRe.MatchString(m[1]) {
		return "", &problem{"dangerouslySetInnerHTML without a plain path", val}
	}
	v, _ := lookup(scope, m[1])
	if obj, ok := v.(map[string]any); ok {
		v = obj["__html"]
	}
	s, ok := text(v)
	if !ok {
		return "", &problem{"dangerouslySetInnerHTML without markup text", val}
	}

	return s, nil
}

// rewriteLink points a link to a canvas at the compiled page.
func rewriteLink(href string) string {
	if strings.Contains(href, "://") || strings.HasPrefix(href, "//") || strings.HasPrefix(href, "/") {
		return href
	}
	base, rest := href, ""
	if i := strings.IndexAny(href, "?#"); i >= 0 {
		base, rest = href[:i], href[i:]
	}
	if !strings.HasSuffix(base, canvasSuffix) {
		return href
	}

	return strings.TrimSuffix(base, canvasSuffix) + ".html" + rest
}
