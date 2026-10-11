package codex

import (
	"encoding/json"
	"maps"
	"slices"
)

// strictSchema rewrites a JSON Schema into the strict form that Codex sends to
// the OpenAI API. Every object sets additionalProperties false and lists each
// property as required, and a property that was optional also takes null. A
// schema that does not decode as an object goes out unchanged.
func strictSchema(schema string) string {
	var root map[string]any
	if json.Unmarshal([]byte(schema), &root) != nil {
		return schema
	}
	out, err := json.Marshal(strictNode(root))
	if err != nil {
		return schema
	}

	return string(out)
}

func strictNode(node map[string]any) map[string]any {
	for _, key := range []string{"anyOf", "oneOf", "allOf", "prefixItems"} {
		if list, ok := node[key].([]any); ok {
			for i, item := range list {
				if child, ok := item.(map[string]any); ok {
					list[i] = strictNode(child)
				}
			}
		}
	}
	for _, key := range []string{"$defs", "definitions"} {
		if defs, ok := node[key].(map[string]any); ok {
			for name, def := range defs {
				if child, ok := def.(map[string]any); ok {
					defs[name] = strictNode(child)
				}
			}
		}
	}
	if items, ok := node["items"].(map[string]any); ok {
		node["items"] = strictNode(items)
	}
	if !isObject(node) {
		return node
	}

	props, _ := node["properties"].(map[string]any)
	if props == nil {
		props = map[string]any{}
	}
	required := map[string]bool{}
	if list, ok := node["required"].([]any); ok {
		for _, name := range list {
			if s, ok := name.(string); ok {
				required[s] = true
			}
		}
	}
	for name, prop := range props {
		child, ok := prop.(map[string]any)
		if !ok {
			continue
		}
		child = strictNode(child)
		if !required[name] {
			child = nullable(child)
		}
		props[name] = child
	}
	node["properties"] = props
	node["required"] = append([]string{}, slices.Sorted(maps.Keys(props))...)
	node["additionalProperties"] = false

	return node
}

func isObject(node map[string]any) bool {
	if _, ok := node["properties"]; ok {
		return true
	}
	switch t := node["type"].(type) {
	case string:
		return t == "object"
	case []any:
		return slices.Contains(t, any("object"))
	}

	return false
}

// nullable lets a property take null, so a strict schema can require it and
// still leave it unset.
func nullable(node map[string]any) map[string]any {
	switch t := node["type"].(type) {
	case string:
		if t != "null" {
			node["type"] = []any{t, "null"}
		}
	case []any:
		if !slices.Contains(t, any("null")) {
			node["type"] = append(t, "null")
		}
	default:
		return map[string]any{"anyOf": []any{node, map[string]any{"type": "null"}}}
	}
	if enum, ok := node["enum"].([]any); ok && !slices.Contains(enum, nil) {
		node["enum"] = append(enum, nil)
	}

	return node
}
