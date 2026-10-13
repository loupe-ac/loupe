package rules

import (
	"encoding/json"
	"strings"
	"testing"
)

func TestResultSchemaCarriesTheTitleAndTheSuggestedActions(t *testing.T) {
	raw, err := resultSchema(nil)
	if err != nil {
		t.Fatal(err)
	}
	var schema struct {
		Properties map[string]struct {
			Type  string `json:"type"`
			Items struct {
				Properties map[string]struct {
					Type string `json:"type"`
				} `json:"properties"`
				Required []string `json:"required"`
			} `json:"items"`
		} `json:"properties"`
		Required []string `json:"required"`
	}
	if err := json.Unmarshal([]byte(raw), &schema); err != nil {
		t.Fatal(err)
	}

	if got := schema.Properties["title"].Type; got != "string" {
		t.Fatalf("title type = %q", got)
	}
	actions := schema.Properties["suggestedActions"]
	if actions.Type != "array" {
		t.Fatalf("suggestedActions type = %q", actions.Type)
	}
	for name, want := range map[string]string{"action": "string", "reason": "string", "recommended": "boolean"} {
		if got := actions.Items.Properties[name].Type; got != want {
			t.Fatalf("suggestedActions.%s type = %q, want %q", name, got, want)
		}
	}
	if strings.Join(actions.Items.Required, ",") != "action,reason" {
		t.Fatalf("suggestedActions required = %v", actions.Items.Required)
	}
	if strings.Join(schema.Required, ",") != "status,summary" {
		t.Fatalf("the result still requires only status and summary, got %v", schema.Required)
	}
}

func TestResultSchemaRefusesACoreFieldAsAnExtraField(t *testing.T) {
	for _, name := range []string{"status", "summary", "reason", "title", "suggestedActions"} {
		if _, err := resultSchema(map[string]any{name: map[string]any{"type": "string"}}); err == nil {
			t.Fatalf("resultSchema accepted the core field %q", name)
		}
	}
}
