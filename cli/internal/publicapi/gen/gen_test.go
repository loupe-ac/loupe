package gen

import (
	"strings"
	"testing"
)

const miniSpec = `{"paths":{"/api/v1/projects/{project}/things/{number}":{"get":{"operationId":"getThing",
"parameters":[{"name":"project","in":"path","required":true,"schema":{"type":"string"}},
{"name":"number","in":"path","required":true,"schema":{"type":"integer"}},
{"name":"%s","in":"query","schema":{"type":"string"}},
{"name":"fields","in":"query","schema":{"type":"array","items":{"type":"string"}}}],
"responses":{"200":{"content":{"application/json":{"schema":{"$ref":"#/components/schemas/Thing"}}}}}}}},
"components":{"schemas":{"Thing":{"properties":{"zeta":{"type":"string"},"alpha":{"type":"string","format":"date-time"}}}}}}`

func TestGenerateKeepsTheSpecOrderAndTheShape(t *testing.T) {
	out, err := Generate([]byte(strings.Replace(miniSpec, "%s", "state", 1)))
	if err != nil {
		t.Fatal(err)
	}
	src := string(out)
	for _, want := range []string{
		`PathArgs: []string{"number"}`, "HasProject: true", "TakesFields: true",
		`AnswerFields: []string{"zeta", "alpha"}`, `TimeFields: []string{"alpha"}`, `Name: "state"`,
	} {
		if !strings.Contains(src, want) {
			t.Errorf("the table lacks %q:\n%s", want, src)
		}
	}
}

func TestGenerateRefusesAParameterThatTakesASharedFlag(t *testing.T) {
	for _, name := range []string{"format", "retry", "project"} {
		_, err := Generate([]byte(strings.Replace(miniSpec, "%s", name, 1)))
		if err == nil || !strings.Contains(err.Error(), "collides") {
			t.Errorf("%s: got %v, want a collision error", name, err)
		}
	}
}
