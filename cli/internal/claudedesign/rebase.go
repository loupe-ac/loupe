package claudedesign

import (
	"path"
	"regexp"
	"strings"

	"golang.org/x/net/html"
)

// cssURLRe finds a url() in CSS, with its optional quote.
var cssURLRe = regexp.MustCompile(`url\(\s*(['"]?)([^'")\s]*)(['"]?)\s*\)`)

// rebaser points the relative links of a page that the compiler writes to
// another folder than its canvas, so each still reaches the same file.
type rebaser struct{ from, to string }

func (r rebaser) tree(n *html.Node) {
	if n.Type == html.ElementNode {
		for i, a := range n.Attr {
			switch a.Key {
			case "href", "src", "poster", "action", "data":
				n.Attr[i].Val = r.url(a.Val)
			case "srcset":
				n.Attr[i].Val = r.srcset(a.Val)
			case "style":
				n.Attr[i].Val = r.css(a.Val)
			}
		}
		if n.Data == "style" {
			for c := n.FirstChild; c != nil; c = c.NextSibling {
				if c.Type == html.TextNode {
					c.Data = r.css(c.Data)
				}
			}
		}
	}
	for c := n.FirstChild; c != nil; c = c.NextSibling {
		r.tree(c)
	}
}

// url rebases a link relative to the canvas folder. An absolute link, a
// fragment, a link with a scheme and a link above the push root stay as they
// are.
func (r rebaser) url(u string) string {
	if u == "" || strings.HasPrefix(u, "#") || strings.HasPrefix(u, "/") || strings.Contains(u, "{{") {
		return u
	}
	base, rest := u, ""
	if i := strings.IndexAny(u, "?#"); i >= 0 {
		base, rest = u[:i], u[i:]
	}
	if strings.Contains(base, ":") || base == "" {
		return u
	}
	target := path.Join(r.from, base)
	if target == ".." || strings.HasPrefix(target, "../") {
		return u
	}

	return relTo(r.to, target) + rest
}

func (r rebaser) srcset(s string) string {
	parts := strings.Split(s, ",")
	for i, part := range parts {
		fields := strings.Fields(part)
		if len(fields) == 0 {
			continue
		}
		lead := part[:strings.Index(part, fields[0])]
		parts[i] = lead + r.url(fields[0]) + part[len(lead)+len(fields[0]):]
	}

	return strings.Join(parts, ",")
}

func (r rebaser) css(s string) string {
	return cssURLRe.ReplaceAllStringFunc(s, func(m string) string {
		g := cssURLRe.FindStringSubmatch(m)

		return "url(" + g[1] + r.url(g[2]) + g[3] + ")"
	})
}
