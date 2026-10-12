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

// rebasedAttrs holds the attributes whose links the rebaser points again.
var rebasedAttrs = map[string]bool{"href": true, "src": true, "poster": true, "action": true, "data": true, "srcset": true, "style": true}

// rebaser gives the rebaser of a page written to another folder than its
// canvas, and false when the two folders are the same.
func (p *page) rebaser() (rebaser, bool) {
	r := rebaser{path.Dir(p.file), path.Dir(p.out)}

	return r, r.from != r.to
}

// attr rebases the links of one attribute value.
func (r rebaser) attr(key, val string) string {
	switch key {
	case "srcset":
		return r.srcset(val)
	case "style":
		return r.css(val)
	case "href", "src", "poster", "action", "data":
		return r.url(val)
	}

	return val
}

func (r rebaser) tree(n *html.Node) {
	if n.Type == html.ElementNode {
		for i, a := range n.Attr {
			n.Attr[i].Val = r.attr(a.Key, a.Val)
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

// srcset rebases the URL of each candidate. It parses the candidates as the
// HTML standard does, so a comma inside a URL, such as in a data URL, stays.
func (r rebaser) srcset(s string) string {
	var out strings.Builder
	i := 0
	for i < len(s) {
		start := i
		for i < len(s) && (isHTMLSpace(s[i]) || s[i] == ',') {
			i++
		}
		out.WriteString(s[start:i])
		start = i
		for i < len(s) && !isHTMLSpace(s[i]) {
			i++
		}
		u := strings.TrimRight(s[start:i], ",")
		out.WriteString(r.url(u))
		out.WriteString(s[start+len(u) : i])
		if len(u) < i-start {
			continue
		}
		start = i
		for depth := 0; i < len(s) && (s[i] != ',' || depth > 0); i++ {
			switch s[i] {
			case '(':
				depth++
			case ')':
				depth--
			}
		}
		out.WriteString(s[start:i])
	}

	return out.String()
}

func isHTMLSpace(c byte) bool {
	return c == ' ' || c == '\t' || c == '\n' || c == '\f' || c == '\r'
}

func (r rebaser) css(s string) string {
	return cssURLRe.ReplaceAllStringFunc(s, func(m string) string {
		g := cssURLRe.FindStringSubmatch(m)

		return "url(" + g[1] + r.url(g[2]) + g[3] + ")"
	})
}
