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

func TestStrictSchemaMakesTheTitleAndTheSuggestedActionsNullable(t *testing.T) {
	in := `{"type":"object","properties":{"status":{"type":"string"},"title":{"type":"string"},"suggestedActions":{"type":"array","items":{"type":"object","properties":{"action":{"type":"string"},"reason":{"type":"string"},"recommended":{"type":"boolean"}},"required":["action","reason"]}}},"required":["status"]}`
	want := `{"type":"object","additionalProperties":false,"properties":{"status":{"type":"string"},"title":{"type":["string","null"]},"suggestedActions":{"type":["array","null"],"items":{"type":"object","additionalProperties":false,"properties":{"action":{"type":"string"},"reason":{"type":"string"},"recommended":{"type":["boolean","null"]}},"required":["action","reason","recommended"]}}},"required":["status","suggestedActions","title"]}`
	var got, exp any
	if err := json.Unmarshal([]byte(strictSchema(in)), &got); err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal([]byte(want), &exp); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(got, exp) {
		t.Fatalf("strictSchema = %s\nwant %s", strictSchema(in), want)
	}
}

func TestStrictSchemaKeepsTheParamsOfARunCommandAsAList(t *testing.T) {
	in := `{"type":"object","properties":{"command":{"type":"object","properties":{"entry":{"type":"string"},"branch":{"type":"string"},"params":{"type":"array","items":{"type":"object","properties":{"name":{"type":"string"},"value":{"type":"string"}},"required":["name","value"]}}},"required":["entry"]}}}`
	want := `{"type":"object","additionalProperties":false,"properties":{"command":{"type":["object","null"],"additionalProperties":false,"properties":{"entry":{"type":"string"},"branch":{"type":["string","null"]},"params":{"type":["array","null"],"items":{"type":"object","additionalProperties":false,"properties":{"name":{"type":"string"},"value":{"type":"string"}},"required":["name","value"]}}},"required":["branch","entry","params"]}},"required":["command"]}`
	var got, exp any
	if err := json.Unmarshal([]byte(strictSchema(in)), &got); err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal([]byte(want), &exp); err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(got, exp) {
		t.Fatalf("strictSchema = %s\nwant %s", strictSchema(in), want)
	}
}
