package claudedesign

import (
	_ "embed"
	"encoding/json"
	"fmt"
	"path"
	"regexp"
	"strconv"
	"strings"

	"golang.org/x/net/html"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/artifactcompile"
)

//go:embed mount.js
var mountJS string

//go:embed react/react.production.min.js
var reactJS []byte

//go:embed react/react-dom.production.min.js
var reactDOMJS []byte

// The React files ship once per push, beside the pages.
const (
	reactPath    = "_runtime/react.production.min.js"
	reactDOMPath = "_runtime/react-dom.production.min.js"
)

const (
	importTag  = "x-import"
	importAttr = "component-from-global-scope"
	mountTag   = "loupe-mount"
)

var (
	componentRe = regexp.MustCompile(`^[A-Za-z_$][A-Za-z0-9_$]*(\.[A-Za-z_$][A-Za-z0-9_$]*)*$`)
	fontPathRe  = regexp.MustCompile(`^fonts/[^/]+$`)
	cssPathRe   = regexp.MustCompile(`^(components/)?[^/]+\.css$`)
)

// pushCtx holds what the compiler learns from the whole push before it
// compiles a page: the width of each artboard and the installed design systems.
type pushCtx struct {
	widths map[string]int
	// systems holds the folder of each installed design system, with a slash.
	systems []string
	// sheets and scripts hold the kept design system files by path.
	sheets  map[string]api.ArtifactFile
	scripts map[string]bool
	// copies holds the per-width stylesheets by path.
	copies map[string]api.ArtifactFile
	// copied and linked say which stylesheets a page replaced and which a page
	// links as they are.
	copied      map[string]bool
	linked      map[string]bool
	needRuntime bool
}

type canvasLayout struct {
	Boards map[string]struct {
		W float64 `json:"w"`
	} `json:"boards"`
	DesignSystems []struct {
		Namespace string `json:"namespace"`
	} `json:"designSystems"`
}

func newPushCtx(files []api.ArtifactFile) *pushCtx {
	c := &pushCtx{
		widths: map[string]int{}, sheets: map[string]api.ArtifactFile{}, scripts: map[string]bool{},
		copies: map[string]api.ArtifactFile{}, copied: map[string]bool{}, linked: map[string]bool{},
	}
	for _, f := range files {
		if !isBoardLayout(f.Path) {
			continue
		}
		var l canvasLayout
		if json.Unmarshal(f.Contents, &l) != nil {
			continue
		}
		dir := path.Dir(f.Path)
		for name, b := range l.Boards {
			if b.W >= 1 {
				c.widths[path.Join(dir, name)] = int(b.W)
			}
		}
		for _, ds := range l.DesignSystems {
			if ds.Namespace != "" && !strings.Contains(ds.Namespace, "/") && ds.Namespace != ".." {
				c.systems = append(c.systems, path.Join(dir, "ds", ds.Namespace)+"/")
			}
		}
	}
	for _, f := range files {
		if rel, ok := c.inSystem(f.Path); ok && keptInSystem(rel) {
			switch {
			case strings.HasSuffix(rel, ".css"):
				c.sheets[f.Path] = f
			case strings.HasSuffix(rel, ".js"):
				c.scripts[f.Path] = true
			}
		}
	}

	return c
}

// inSystem gives the path of a file inside an installed design system folder,
// relative to that folder.
func (c *pushCtx) inSystem(p string) (string, bool) {
	for _, dir := range c.systems {
		if rel, ok := strings.CutPrefix(p, dir); ok {
			return rel, true
		}
	}

	return "", false
}

// keptInSystem says whether a file of an installed design system is a file a
// page needs: the component script, the stylesheets and the fonts.
func keptInSystem(rel string) bool {
	return rel == "components/bundle.js" || cssPathRe.MatchString(rel) || fontPathRe.MatchString(rel)
}

// keeps says whether a pushed file reaches the output.
func (c *pushCtx) keeps(p string) bool {
	rel, ok := c.inSystem(p)

	return !ok || keptInSystem(rel)
}

// styleFor gives the stylesheet a page of this width links in place of the
// one at target. It is false when the stylesheet has nothing to resolve.
func (c *pushCtx) styleFor(target string, width int) (string, bool) {
	f, ok := c.sheets[target]
	if !ok {
		return "", false
	}
	at := strings.TrimSuffix(target, ".css") + ".w" + strconv.Itoa(width) + ".css"
	if _, done := c.copies[at]; done {
		return at, true
	}
	resolved := resolveWidthMedia(string(f.Contents), width)
	if resolved == string(f.Contents) {
		return "", false
	}
	c.copies[at] = api.ArtifactFile{Path: at, ContentType: f.ContentType, Contents: []byte(resolved)}
	c.copied[target] = true

	return at, true
}

// assetTarget gives the push path that a relative link of a page reaches.
func assetTarget(file, href string) string {
	if href == "" || strings.ContainsAny(href, "?#:{") || strings.HasPrefix(href, "/") {
		return ""
	}
	target := path.Join(path.Dir(file), href)
	if target == ".." || strings.HasPrefix(target, "../") {
		return ""
	}

	return target
}

// headAssets points each design system stylesheet of a page at the copy for
// its width, and takes the design system scripts out of the head when the page
// draws components, because they need React first. It gives the script nodes.
func (p *page) headAssets(head *html.Node) []*html.Node {
	if p.ctx == nil {
		return nil
	}
	width := p.ctx.widths[p.file]
	var scripts []*html.Node
	for c := head.FirstChild; c != nil; c = c.NextSibling {
		if c.Type != html.ElementNode {
			continue
		}
		switch c.Data {
		case "link":
			rel, _ := attrOf(c, "rel")
			if !strings.EqualFold(rel, "stylesheet") {
				continue
			}
			for i, a := range c.Attr {
				if a.Key != "href" {
					continue
				}
				target := assetTarget(p.file, a.Val)
				if _, known := p.ctx.sheets[target]; !known {
					continue
				}
				if copyAt, ok := p.ctx.styleFor(target, width); ok && width > 0 {
					c.Attr[i].Val = relTo(path.Dir(p.file), copyAt)
				} else {
					p.ctx.linked[target] = true
				}
			}
		case "script":
			src, _ := attrOf(c, "src")
			if p.mounts > 0 && p.ctx.scripts[assetTarget(p.file, src)] {
				scripts = append(scripts, c)
			}
		}
	}

	return scripts
}

// addRuntime moves the design system scripts to the end of the body, behind
// React and the script that draws the components.
func (p *page) addRuntime(head, body *html.Node, scripts []*html.Node) {
	for _, s := range scripts {
		head.RemoveChild(s)
	}
	dir := path.Dir(p.out)
	for _, src := range []string{reactPath, reactDOMPath} {
		body.AppendChild(&html.Node{Type: html.ElementNode, Data: "script", Attr: []html.Attribute{{Key: "src", Val: relTo(dir, src)}}})
	}
	for _, s := range scripts {
		s.Parent, s.PrevSibling, s.NextSibling = nil, nil, nil
		body.AppendChild(s)
	}
	mount := &html.Node{Type: html.ElementNode, Data: "script"}
	mount.AppendChild(&html.Node{Type: html.TextNode, Data: mountJS})
	body.AppendChild(mount)
	p.ctx.needRuntime = true
}

// expandImport turns a component of the design system into a mount point. The
// viewer draws it. The component name and the props go into attributes, and the
// children stay in the page as the content the viewer replaces.
func (p *page) expandImport(n *html.Node, scope map[string]any) []*html.Node {
	name, _ := attrOf(n, importAttr)
	if !componentRe.MatchString(name) {
		return p.rawGap(n, problem{"<x-import> without a plain component name", importAttr + "=" + strconv.Quote(name)})
	}
	el := &html.Node{Type: html.ElementNode, Data: mountTag}
	el.Attr = []html.Attribute{{Key: "data-component", Val: name}, {Key: "style", Val: "display: contents"}}
	var marks []*html.Node
	props := map[string]any{}
	rb, moved := p.rebaser()
	for _, a := range n.Attr {
		switch {
		case a.Key == lineAttr:
			el.Attr = append(el.Attr, a)
		case a.Key == importAttr, strings.HasPrefix(a.Key, "hint-placeholder-"):
		case eventAttrRe.MatchString(a.Key):
			marks = append(marks, p.addGap(n, eventProblem(a)))
		default:
			val, ok, problems := p.propValue(a, scope)
			for _, pr := range problems {
				marks = append(marks, p.addGap(n, pr))
			}
			if !ok {
				continue
			}
			if s, isText := val.(string); isText && moved {
				val = rb.attr(a.Key, s)
			}
			props[propName(a.Key)] = val
		}
	}
	if len(props) > 0 {
		raw, err := jsonJS(props)
		if err != nil {
			marks = append(marks, p.addGap(n, problem{"props that are not JSON", err.Error()}))
		} else {
			el.Attr = append(el.Attr, html.Attribute{Key: "data-props", Val: raw})
		}
	}
	for _, c := range p.expandChildren(n, scope) {
		el.AppendChild(c)
	}
	p.mounts++
	if len(marks) > 0 {
		p.keptSelf[el] = true
	}

	return append(marks, el)
}

// propValue gives the value of a prop. A sole hole keeps the type of its
// value, so an array or an object reaches the component as it is. ok is false
// when the hole has no value, and then the prop is left out.
func (p *page) propValue(a html.Attribute, scope map[string]any) (any, bool, []problem) {
	if path, sole := plainHole(a.Val); sole {
		v, found := lookup(scope, path)
		if !found {
			return nil, false, nil
		}
		if _, isText := v.(string); !isText {
			return v, true, nil
		}
	}
	val, problems := p.interpolateAttr(a.Val, scope)
	if a.Key == "href" && len(problems) == 0 {
		link, ok := p.rewriteLink(val)
		if !ok {
			problems = append(problems, missingLink(val))
		}
		val = link
	}

	return val, true, problems
}

// propName gives the camelCase prop name of an attribute. A name that starts
// with aria- or data- stays as it is.
func propName(key string) string {
	switch key {
	case "class":
		return "className"
	case "for":
		return "htmlFor"
	}
	if strings.HasPrefix(key, "aria-") || strings.HasPrefix(key, "data-") {
		return key
	}
	parts := strings.Split(key, "-")
	for i := 1; i < len(parts); i++ {
		if parts[i] != "" {
			parts[i] = strings.ToUpper(parts[i][:1]) + parts[i][1:]
		}
	}

	return strings.Join(parts, "")
}

// addFiles adds the files that the pages need to the result: the per-width
// stylesheets and the React files. It drops a design system stylesheet that
// every page replaced with a copy. taken holds the paths of the files already
// in the result.
func (c *pushCtx) addFiles(res *artifactcompile.Result, taken map[string]bool) error {
	for _, f := range c.copies {
		if taken[f.Path] {
			return fmt.Errorf("the push holds %s, which the compiler writes for a page width. Rename or remove it", f.Path)
		}
	}
	if c.needRuntime {
		for _, p := range []string{reactPath, reactDOMPath} {
			if taken[p] {
				return fmt.Errorf("the push holds %s, which the compiler writes to draw the design system components. Rename or remove it", p)
			}
		}
	}
	kept := res.Files[:0]
	for _, f := range res.Files {
		if c.copied[f.Path] && !c.linked[f.Path] {
			continue
		}
		kept = append(kept, f)
	}
	res.Files = kept
	for _, f := range c.copies {
		res.Files = append(res.Files, f)
	}
	if c.needRuntime {
		res.Files = append(res.Files,
			api.ArtifactFile{Path: reactPath, ContentType: jsType, Contents: reactJS},
			api.ArtifactFile{Path: reactDOMPath, ContentType: jsType, Contents: reactDOMJS},
		)
	}

	return nil
}

const jsType = "text/javascript; charset=utf-8"
