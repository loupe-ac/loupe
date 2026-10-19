package claudedesign

import (
	"regexp"
	"strconv"
	"strings"
)

// tri is the answer to a media condition for a fixed viewport width.
type tri int

const (
	triFalse tri = iota
	triTrue
	triUnknown
)

// remPx is the size of one rem or em in pixels.
const remPx = 16.0

var (
	mediaAtRe    = regexp.MustCompile(`(?i)^@media(\s|\(|$)`)
	lengthRe     = regexp.MustCompile(`^(-?\d*\.?\d+)(px|rem|em)?$`)
	featureRe    = regexp.MustCompile(`^(min-width|max-width|width)\s*:\s*(\S+)$`)
	rangeOpRe    = regexp.MustCompile(`\s*(<=|>=|<|>|=)\s*`)
	commentRe    = regexp.MustCompile(`(?s)/\*.*?\*/`)
	notPrefixRe  = regexp.MustCompile(`(?i)^not\s+`)
	onlyPrefixRe = regexp.MustCompile(`(?i)^only\s+`)
)

// resolveWidthMedia gives the CSS with each @media rule whose condition holds
// only on the width resolved. A true rule becomes @media all, and a false rule
// goes. A rule that depends on anything else stays as it is.
func resolveWidthMedia(css string, width int) string {
	var out strings.Builder
	last, stmt, parens := 0, 0, 0
	for i := 0; i < len(css); {
		c := css[i]
		switch {
		case c == '/' && i+1 < len(css) && css[i+1] == '*':
			i = skipComment(css, i)
		case c == '"' || c == '\'':
			i = skipString(css, i)
		case c == '\\':
			i += 2
		case c == '(':
			parens++
			i++
		case c == ')':
			if parens > 0 {
				parens--
			}
			i++
		case c == ';' && parens == 0:
			i++
			stmt = i
		case c == '{' && parens == 0:
			end := matchBrace(css, i)
			prelude := css[stmt:i]
			body := resolveWidthMedia(css[i+1:end], width)
			out.WriteString(css[last:stmt])
			out.WriteString(mediaRule(prelude, body, width))
			if end < len(css) {
				end++
			}
			i, last, stmt = end, end, end
		default:
			i++
		}
	}
	out.WriteString(css[last:])

	return out.String()
}

// mediaRule writes one rule with its prelude and its resolved body.
func mediaRule(prelude, body string, width int) string {
	head := strings.TrimSpace(commentRe.ReplaceAllString(prelude, ""))
	if !mediaAtRe.MatchString(head) {
		return prelude + "{" + body + "}"
	}
	list := strings.TrimSpace(head[len("@media"):])
	var keep []string
	queries := splitTop(list, ',')
	for _, q := range queries {
		switch evalQuery(q, width) {
		case triTrue:
			return "@media all{" + body + "}"
		case triUnknown:
			keep = append(keep, strings.TrimSpace(q))
		}
	}
	if len(keep) == 0 {
		return ""
	}
	if len(keep) == len(queries) {
		return prelude + "{" + body + "}"
	}

	return "@media " + strings.Join(keep, ",") + "{" + body + "}"
}

// evalQuery answers one media query. A query that holds anything but width
// conditions and the screen types is unknown, unless one width condition
// makes it false.
func evalQuery(q string, width int) tri {
	q = strings.TrimSpace(q)
	negate := false
	if notPrefixRe.MatchString(q) {
		negate = true
		q = strings.TrimSpace(notPrefixRe.ReplaceAllString(q, ""))
	} else {
		q = strings.TrimSpace(onlyPrefixRe.ReplaceAllString(q, ""))
	}
	if q == "" {
		return triUnknown
	}
	res := triTrue
	for _, part := range splitAnd(q) {
		part = strings.TrimSpace(part)
		var got tri
		switch lower := strings.ToLower(part); {
		case lower == "all" || lower == "screen":
			got = triTrue
		case strings.HasPrefix(part, "(") && strings.HasSuffix(part, ")"):
			got = evalCondition(strings.TrimSpace(part[1:len(part)-1]), float64(width))
		default:
			got = triUnknown
		}
		switch {
		case got == triFalse:
			res = triFalse
		case got == triUnknown && res == triTrue:
			res = triUnknown
		}
		if res == triFalse {
			break
		}
	}
	if negate && res != triUnknown {
		if res == triTrue {
			return triFalse
		}

		return triTrue
	}
	if negate {
		return triUnknown
	}

	return res
}

// evalCondition answers one condition without its parentheses.
func evalCondition(c string, width float64) tri {
	if rest, ok := strings.CutPrefix(strings.ToLower(c), "not "); ok {
		rest = strings.TrimSpace(rest)
		if closes(rest) {
			switch evalCondition(strings.TrimSpace(rest[1:len(rest)-1]), width) {
			case triTrue:
				return triFalse
			case triFalse:
				return triTrue
			}
		}

		return triUnknown
	}
	if strings.ContainsAny(c, "()") || strings.Contains(strings.ToLower(c), " or ") || strings.HasPrefix(strings.ToLower(c), "not ") {
		return triUnknown
	}
	if m := featureRe.FindStringSubmatch(strings.ToLower(c)); m != nil {
		v, ok := lengthPx(m[2])
		if !ok {
			return triUnknown
		}
		switch m[1] {
		case "min-width":
			return boolTri(width >= v)
		case "max-width":
			return boolTri(width <= v)
		}

		return boolTri(width == v)
	}

	return evalRange(strings.ToLower(c), width)
}

// closes says whether s is one group in parentheses.
func closes(s string) bool {
	if len(s) < 2 || s[0] != '(' || s[len(s)-1] != ')' {
		return false
	}
	depth := 0
	for i := 0; i < len(s); i++ {
		switch s[i] {
		case '(':
			depth++
		case ')':
			depth--
			if depth == 0 && i != len(s)-1 {
				return false
			}
		}
	}

	return depth == 0
}

// evalRange answers `width >= 40rem`, `40rem <= width` and `a <= width <= b`.
func evalRange(c string, width float64) tri {
	ops := rangeOpRe.FindAllString(c, -1)
	operands := rangeOpRe.Split(c, -1)
	if len(ops) < 1 || len(ops) > 2 || len(operands) != len(ops)+1 {
		return triUnknown
	}
	vals := make([]float64, len(operands))
	seenWidth := false
	for i, o := range operands {
		o = strings.TrimSpace(o)
		if o == "width" {
			if seenWidth {
				return triUnknown
			}
			seenWidth = true
			vals[i] = width

			continue
		}
		v, ok := lengthPx(o)
		if !ok {
			return triUnknown
		}
		vals[i] = v
	}
	if !seenWidth {
		return triUnknown
	}
	for i, op := range ops {
		if !compare(vals[i], strings.TrimSpace(op), vals[i+1]) {
			return triFalse
		}
	}

	return triTrue
}

func compare(a float64, op string, b float64) bool {
	switch op {
	case "<":
		return a < b
	case "<=":
		return a <= b
	case ">":
		return a > b
	case ">=":
		return a >= b
	}

	return a == b
}

func boolTri(b bool) tri {
	if b {
		return triTrue
	}

	return triFalse
}

// lengthPx reads a length in px, rem or em. A bare 0 is a length.
func lengthPx(s string) (float64, bool) {
	m := lengthRe.FindStringSubmatch(s)
	if m == nil {
		return 0, false
	}
	n, err := strconv.ParseFloat(m[1], 64)
	if err != nil || (m[2] == "" && n != 0) {
		return 0, false
	}
	if m[2] == "rem" || m[2] == "em" {
		n *= remPx
	}

	return n, true
}

// splitAnd splits a query at each "and" outside parentheses.
func splitAnd(q string) []string {
	q = strings.Join(strings.Fields(q), " ")
	lower := strings.ToLower(q)
	var out []string
	depth, start := 0, 0
	for i := 0; i < len(q); i++ {
		switch {
		case q[i] == '(':
			depth++
		case q[i] == ')':
			if depth > 0 {
				depth--
			}
		case depth == 0 && strings.HasPrefix(lower[i:], " and "):
			out = append(out, q[start:i])
			start = i + len(" and ")
			i = start - 1
		}
	}

	return append(out, q[start:])
}

// splitTop splits s at sep outside parentheses.
func splitTop(s string, sep byte) []string {
	var out []string
	depth, start := 0, 0
	for i := 0; i < len(s); i++ {
		switch s[i] {
		case '(':
			depth++
		case ')':
			if depth > 0 {
				depth--
			}
		case sep:
			if depth == 0 {
				out = append(out, s[start:i])
				start = i + 1
			}
		}
	}

	return append(out, s[start:])
}

func skipComment(s string, i int) int {
	if end := strings.Index(s[i+2:], "*/"); end >= 0 {
		return i + 2 + end + 2
	}

	return len(s)
}

func skipString(s string, i int) int {
	quote := s[i]
	for i++; i < len(s); i++ {
		switch s[i] {
		case '\\':
			i++
		case quote:
			return i + 1
		case '\n':
			return i
		}
	}

	return len(s)
}

// matchBrace gives the index of the brace that closes the one at open, or the
// length of s when it never closes.
func matchBrace(s string, open int) int {
	depth, parens := 0, 0
	for i := open; i < len(s); {
		switch c := s[i]; {
		case c == '/' && i+1 < len(s) && s[i+1] == '*':
			i = skipComment(s, i)

			continue
		case c == '"' || c == '\'':
			i = skipString(s, i)

			continue
		case c == '\\':
			i += 2

			continue
		case c == '(':
			parens++
		case c == ')':
			if parens > 0 {
				parens--
			}
		case c == '{' && parens == 0:
			depth++
		case c == '}' && parens == 0:
			depth--
			if depth == 0 {
				return i
			}
		}
		i++
	}

	return len(s)
}
