package codex

import (
	"encoding/json"
	"reflect"
	"testing"
)

func TestStrictSchema(t *testing.T) {
	for name, tc := range map[string]struct {
		in, want string
	}{
		"the bridge result schema": {
			in:   `{"type":"object","properties":{"status":{"type":"string","enum":["finished","blocked"]},"summary":{"type":"string"},"reason":{"type":"string"}},"required":["status","summary"]}`,
			want: `{"type":"object","additionalProperties":false,"properties":{"status":{"type":"string","enum":["finished","blocked"]},"summary":{"type":"string"},"reason":{"type":["string","null"]}},"required":["reason","status","summary"]}`,
		},
		"an optional enum takes null": {
			in:   `{"type":"object","properties":{"kind":{"type":"string","enum":["a","b"]}}}`,
			want: `{"type":"object","additionalProperties":false,"properties":{"kind":{"type":["string","null"],"enum":["a","b",null]}},"required":["kind"]}`,
		},
		"an optional field with no type": {
			in:   `{"type":"object","properties":{"any":{"enum":[1,2]}}}`,
			want: `{"type":"object","additionalProperties":false,"properties":{"any":{"anyOf":[{"enum":[1,2]},{"type":"null"}]}},"required":["any"]}`,
		},
		"a nested object and an array of objects": {
			in:   `{"type":"object","properties":{"pr":{"type":"object","properties":{"url":{"type":"string"}},"required":["url"]},"list":{"type":"array","items":{"type":"object","properties":{"n":{"type":"integer"}}}}},"required":["pr","list"]}`,
			want: `{"type":"object","additionalProperties":false,"properties":{"pr":{"type":"object","additionalProperties":false,"properties":{"url":{"type":"string"}},"required":["url"]},"list":{"type":"array","items":{"type":"object","additionalProperties":false,"properties":{"n":{"type":["integer","null"]}},"required":["n"]}}},"required":["list","pr"]}`,
		},
		"an object with no properties": {
			in:   `{"type":"object"}`,
			want: `{"type":"object","additionalProperties":false,"properties":{},"required":[]}`,
		},
		"a type list that already takes null": {
			in:   `{"type":"object","properties":{"n":{"type":["integer","null"]}}}`,
			want: `{"type":"object","additionalProperties":false,"properties":{"n":{"type":["integer","null"]}},"required":["n"]}`,
		},
	} {
		t.Run(name, func(t *testing.T) {
			var got, want any
			if err := json.Unmarshal([]byte(strictSchema(tc.in)), &got); err != nil {
				t.Fatal(err)
			}
			if err := json.Unmarshal([]byte(tc.want), &want); err != nil {
				t.Fatal(err)
			}
			if !reflect.DeepEqual(got, want) {
				t.Fatalf("strictSchema = %s\nwant %s", strictSchema(tc.in), tc.want)
			}
		})
	}
}

func TestStrictSchemaKeepsATextThatIsNoObject(t *testing.T) {
	for _, in := range []string{"", "not json", `["a"]`} {
		if got := strictSchema(in); got != in {
			t.Fatalf("strictSchema(%q) = %q", in, got)
		}
	}
}
