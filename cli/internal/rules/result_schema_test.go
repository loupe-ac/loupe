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

func TestResultSchemaCarriesTheCommandOfARunCommandSuggestion(t *testing.T) {
	raw, err := resultSchema(nil)
	if err != nil {
		t.Fatal(err)
	}
	var schema struct {
		Properties struct {
			SuggestedActions struct {
				Items struct {
					Properties struct {
						Command struct {
							Type       string   `json:"type"`
							Required   []string `json:"required"`
							Properties struct {
								Entry  struct{ Type string } `json:"entry"`
								Branch struct{ Type string } `json:"branch"`
								Params struct {
									Type  string `json:"type"`
									Items struct {
										Required []string `json:"required"`
									} `json:"items"`
								} `json:"params"`
							} `json:"properties"`
						} `json:"command"`
						ProposedEntry struct{ Type string } `json:"proposedEntry"`
					} `json:"properties"`
					Required []string `json:"required"`
				} `json:"items"`
			} `json:"suggestedActions"`
		} `json:"properties"`
	}
	if err := json.Unmarshal([]byte(raw), &schema); err != nil {
		t.Fatal(err)
	}

	item := schema.Properties.SuggestedActions.Items
	command := item.Properties.Command
	if command.Type != "object" || strings.Join(command.Required, ",") != "entry" {
		t.Fatalf("command = %+v", command)
	}
	if command.Properties.Entry.Type != "string" || command.Properties.Branch.Type != "string" {
		t.Fatalf("command entry and branch types = %q, %q", command.Properties.Entry.Type, command.Properties.Branch.Type)
	}
	if command.Properties.Params.Type != "array" || strings.Join(command.Properties.Params.Items.Required, ",") != "name,value" {
		t.Fatalf("command params = %+v", command.Properties.Params)
	}
	if item.Properties.ProposedEntry.Type != "string" {
		t.Fatalf("proposedEntry type = %q", item.Properties.ProposedEntry.Type)
	}
	if strings.Join(item.Required, ",") != "action,reason" {
		t.Fatalf("a suggestion still requires only action and reason, got %v", item.Required)
	}
}

func TestResultSchemaRefusesACoreFieldAsAnExtraField(t *testing.T) {
	for _, name := range []string{"status", "summary", "reason", "title", "suggestedActions"} {
		if _, err := resultSchema(map[string]any{name: map[string]any{"type": "string"}}); err == nil {
			t.Fatalf("resultSchema accepted the core field %q", name)
		}
	}
}
