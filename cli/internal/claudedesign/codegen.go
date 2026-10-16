package claudedesign

import (
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strconv"
	"strings"

	"golang.org/x/net/html"
)

//go:embed runtime.js
var runtimeJS string

var voidElements = map[string]bool{
	"area": true, "base": true, "basefont": true, "bgsound": true, "br": true, "col": true,
	"command": true, "embed": true, "frame": true, "hr": true, "image": true, "img": true,
	"input": true, "keygen": true, "link": true, "meta": true, "param": true, "source": true,
	"track": true, "wbr": true,
}

// rawParents hold text that the serializer writes unescaped.
var rawParents = map[string]bool{
	"iframe": true, "noembed": true, "noframes": true, "noscript": true,
	"plaintext": true, "script": true, "style": true, "xmp": true,
}

var htmlEscaper = strings.NewReplacer("&", "&amp;", "'", "&#39;", "<", "&lt;", ">", "&gt;", `"`, "&#34;", "\r", "&#13;")

// jsonJS gives a JSON value as JavaScript source. The text holds no <, > or &,
// no line separator and no canvas suffix, so no scan of a script can find
// canvas text in it and it cannot close the script element.
func jsonJS(v any) (string, error) {
	b, err := json.Marshal(v)
	if err != nil {
		return "", err
	}

	return strings.ReplaceAll(string(b), canvasSuffix, ".dc\\"+"u002ehtml"), nil
}

func jsStr(s string) string {
	out, _ := jsonJS(s)

	return out
}

type gen struct {
	p      *page
	out    strings.Builder
	lit    strings.Builder
	events map[string]bool
	blobs  bool
}

func (g *gen) text(s string) { g.lit.WriteString(s) }

func (g *gen) flush() {
	if g.lit.Len() > 0 {
		g.out.WriteString("o+=" + jsStr(g.lit.String()) + ";\n")
		g.lit.Reset()
	}
}

func (g *gen) line(s string) {
	g.flush()
	g.out.WriteString(s + "\n")
}

func (g *gen) expr(js string) { g.line("o+=" + js + ";") }

// genRender writes the JavaScript source of render(s0, h, R) for the children
// of the root, except <helmet>, as the page builder reads them before the
// expansion. It also gives the sorted names of the events that the markup binds,
// and the blob files that R.bl resolves when an attribute with a hole holds a
// /_blob/ path.
func (p *page) genRender(root *html.Node) (render string, events []string, blobs map[string]string, err error) {
	g := &gen{p: p, events: map[string]bool{}}
	for c := root.FirstChild; c != nil; c = c.NextSibling {
		if c.Type == html.ElementNode && c.Data == "helmet" {
			continue
		}
		if err := g.node(c, 0); err != nil {
			return "", nil, nil, err
		}
	}
	g.flush()
	events = []string{}
	for e := range g.events {
		events = append(events, e)
	}
	sort.Strings(events)
	blobs = map[string]string{}
	if g.blobs {
		blobs = p.blobMap()
	}

	return "function render(s0, h, R) {\nvar o = \"\";\nfunction t(v) { return v == null ? \"\" : \"\" + v; }\n" + g.out.String() + "return o;\n}", events, blobs, nil
}

func scopeVar(d int) string { return "s" + strconv.Itoa(d) }

func get(d int, path string) string { return "R.g(" + scopeVar(d) + "," + jsStr(path) + ")" }

func (g *gen) node(n *html.Node, d int) error {
	switch n.Type {
	case html.TextNode:
		return g.textNode(n, d)
	case html.CommentNode:
		g.text("<!--" + n.Data + "-->")

		return nil
	case html.ElementNode:
	default:
		return nil
	}

	switch {
	case isCanvasScript(n):
		return nil
	case n.Data == "sc-for":
		return g.forNode(n, d)
	case n.Data == "sc-if":
		val, _ := attrOf(n, "value")
		path, ok := plainHole(val)
		if !ok {
			return fmt.Errorf("<sc-if> without a plain value: %q", val)
		}
		g.line("if (R.T(" + get(d, path) + ")) {")
		if err := g.children(n, d); err != nil {
			return err
		}
		g.line("}")

		return nil
	case n.Data == "sc-else", strings.HasPrefix(n.Data, "dc-"):
		return errors.New("<" + n.Data + "> cannot be redrawn")
	}

	return g.element(n, d)
}

func (g *gen) children(n *html.Node, d int) error {
	for c := n.FirstChild; c != nil; c = c.NextSibling {
		if err := g.node(c, d); err != nil {
			return err
		}
	}

	return nil
}

func (g *gen) forNode(n *html.Node, d int) error {
	listAttr, _ := attrOf(n, "list")
	as, _ := attrOf(n, "as")
	path, ok := plainHole(listAttr)
	if !ok || as == "" {
		return fmt.Errorf("<sc-for> without a plain list and as: list=%q as=%q", listAttr, as)
	}
	x := "x" + strconv.Itoa(d+1)
	g.line("for (const " + x + " of R.A(" + get(d, path) + ")) {")
	g.line("const " + scopeVar(d+1) + " = Object.assign({}, " + scopeVar(d) + ", {[" + jsStr(as) + "]: " + x + "});")
	if err := g.children(n, d+1); err != nil {
		return err
	}
	g.line("}")

	return nil
}

type segment struct {
	lit  string
	path string
	hole bool
}

// segments splits a string at its holes. A hole must be a plain path.
func segments(s string) ([]segment, error) {
	var out []segment
	last := 0
	for _, m := range holeRe.FindAllStringSubmatchIndex(s, -1) {
		path := s[m[2]:m[3]]
		if !pathRe.MatchString(path) {
			return nil, fmt.Errorf("a hole that is not a plain path: %s", s[m[0]:m[1]])
		}
		if m[0] > last {
			out = append(out, segment{lit: s[last:m[0]]})
		}
		out = append(out, segment{path: path, hole: true})
		last = m[1]
	}
	if last < len(s) {
		out = append(out, segment{lit: s[last:]})
	}

	return out, nil
}

func (g *gen) textNode(n *html.Node, d int) error {
	parent := ""
	if n.Parent != nil {
		parent = n.Parent.Data
	}
	if parent == "style" {
		css, problems := g.p.rewriteBlobs(n.Data)
		if len(problems) > 0 {
			return fmt.Errorf("%s: %s", problems[0].feature, problems[0].detail)
		}
		if r, ok := g.p.rebaser(); ok {
			css = r.css(css)
		}
		g.text(css)

		return nil
	}
	if parent == "script" {
		g.text(n.Data)

		return nil
	}
	segs, err := segments(n.Data)
	if err != nil {
		return err
	}
	for _, s := range segs {
		switch {
		case !s.hole && rawParents[parent]:
			g.text(s.lit)
		case !s.hole:
			g.text(htmlEscaper.Replace(s.lit))
		case rawParents[parent]:
			g.expr("t(" + get(d, s.path) + ")")
		default:
			g.expr("R.e(t(" + get(d, s.path) + "))")
		}
	}

	return nil
}

func (g *gen) element(n *html.Node, d int) error {
	g.text("<" + n.Data)
	inner := ""
	for _, a := range n.Attr {
		switch {
		case a.Key == lineAttr, strings.HasPrefix(a.Key, "hint-placeholder-"):
		case eventAttrRe.MatchString(a.Key):
			path, ok := plainHole(a.Val)
			if !ok {
				return fmt.Errorf("event attribute %s without a plain hole: %q", a.Key, a.Val)
			}
			name := strings.TrimPrefix(a.Key, "on")
			g.events[name] = true
			g.text(" data-on-" + name + `="`)
			g.expr("h(" + get(d, path) + ")")
			g.text(`"`)
		case a.Key == "dangerouslysetinnerhtml":
			path, ok := plainHole(a.Val)
			if !ok {
				return fmt.Errorf("dangerouslySetInnerHTML without a plain path: %q", a.Val)
			}
			inner = path
		default:
			if err := g.attr(a, d); err != nil {
				return err
			}
		}
	}
	if voidElements[n.Data] {
		g.text("/>")

		return nil
	}
	g.text(">")
	if c := n.FirstChild; c != nil && c.Type == html.TextNode && strings.HasPrefix(c.Data, "\n") {
		switch n.Data {
		case "pre", "listing", "textarea":
			g.text("\n")
		}
	}
	if inner != "" {
		g.expr("R.raw(" + get(d, inner) + ")")
	} else if err := g.children(n, d); err != nil {
		return err
	}
	g.text("</" + n.Data + ">")

	return nil
}

// booleanAttrs are the attributes that a browser reads as on when they are
// present, whatever their value. A hole alone in one of them draws the
// attribute or leaves it out, as React does.
var booleanAttrs = map[string]bool{
	"allowfullscreen": true, "async": true, "autofocus": true, "autoplay": true,
	"checked": true, "controls": true, "default": true, "defer": true,
	"disabled": true, "formnovalidate": true, "hidden": true, "inert": true,
	"itemscope": true, "loop": true, "multiple": true, "muted": true,
	"nomodule": true, "novalidate": true, "open": true, "playsinline": true,
	"readonly": true, "required": true, "reversed": true, "selected": true,
}

func (g *gen) attr(a html.Attribute, d int) error {
	key := a.Key
	if a.Namespace != "" {
		key = a.Namespace + ":" + key
	}
	segs, err := segments(a.Val)
	if err != nil {
		return err
	}
	hasHole := false
	for _, s := range segs {
		hasHole = hasHole || s.hole
	}
	if path, ok := plainHole(a.Val); ok && booleanAttrs[a.Key] {
		g.line("if(R.T(" + get(d, path) + ")){")
		g.text(" " + key + `=""`)
		g.line("}")

		return nil
	}
	r, moved := g.p.rebaser()
	rebase := hasHole && moved && rebasedAttrs[a.Key]
	blob := hasHole && strings.Contains(a.Val, "/_blob/")
	g.blobs = g.blobs || blob
	g.text(" " + key + `="`)
	switch {
	case a.Key == "href" && hasHole, rebase, blob:
		parts := []string{`""`}
		for _, s := range segs {
			if s.hole {
				parts = append(parts, "t("+get(d, s.path)+")")
			} else {
				parts = append(parts, strings.ReplaceAll(jsStr(s.lit), "/_blob/", `\/_blob\/`))
			}
		}
		val := strings.Join(parts, " + ")
		if blob {
			val = "R.bl(" + val + ")"
		}
		if a.Key == "href" {
			val = "R.lk(" + val + ")"
		}
		if rebase {
			val = "R.rb(" + jsStr(a.Key) + ", " + val + ")"
		}
		g.expr("R.q(" + val + ")")
	case !hasHole:
		val, problems := g.p.rewriteBlobs(a.Val)
		if len(problems) > 0 {
			return fmt.Errorf("%s: %s", problems[0].feature, problems[0].detail)
		}
		if a.Key == "href" {
			val, _ = g.p.rewriteLink(val)
		}
		if moved {
			val = r.attr(a.Key, val)
		}
		g.text(htmlEscaper.Replace(val))
	default:
		for _, s := range segs {
			if s.hole {
				g.expr("R.q(t(" + get(d, s.path) + "))")
			} else {
				g.text(htmlEscaper.Replace(s.lit))
			}
		}
	}
	g.text(`"`)

	return nil
}
