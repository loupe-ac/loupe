package claudedesign

import (
	"math"
	"regexp"
	"strconv"
	"strings"
)

var (
	holeRe = regexp.MustCompile(`\{\{\s*([^{}]*?)\s*\}\}`)
	pathRe = regexp.MustCompile(`^[A-Za-z_$][A-Za-z0-9_$]*(\.[A-Za-z0-9_$]+)*$`)
	soleRe = regexp.MustCompile(`^\s*\{\{\s*([^{}]*?)\s*\}\}\s*$`)
)

// lookup follows a dotted path through the values. A missing key is not found.
func lookup(scope map[string]any, p string) (any, bool) {
	parts := strings.Split(p, ".")
	cur, ok := scope[parts[0]]
	if !ok {
		return nil, false
	}
	for _, part := range parts[1:] {
		switch c := cur.(type) {
		case map[string]any:
			cur, ok = c[part]
			if !ok {
				return nil, false
			}
		case []any:
			i, err := strconv.Atoi(part)
			if err != nil || i < 0 || i >= len(c) {
				return nil, false
			}
			cur = c[i]
		default:
			return nil, false
		}
	}

	return cur, true
}

func truthy(v any) bool {
	switch x := v.(type) {
	case nil:
		return false
	case bool:
		return x
	case string:
		return x != ""
	case int64:
		return x != 0
	case int:
		return x != 0
	case float64:
		return x != 0 && !math.IsNaN(x)
	}

	return true
}

// text is how a value prints in a hole. ok is false for a value with no text.
func text(v any) (string, bool) {
	switch x := v.(type) {
	case nil:
		return "", true
	case string:
		return x, true
	case bool:
		return strconv.FormatBool(x), true
	case int64:
		return strconv.FormatInt(x, 10), true
	case int:
		return strconv.Itoa(x), true
	case float64:
		if math.IsNaN(x) {
			return "NaN", true
		}

		return strconv.FormatFloat(x, 'f', -1, 64), true
	}

	return "", false
}
