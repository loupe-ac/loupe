package mcpproxy

import (
	"encoding/json"
	"io"
)

// readMethods are the MCP methods that change nothing on Loupe.
var readMethods = map[string]bool{
	"initialize":               true,
	"ping":                     true,
	"tools/list":               true,
	"prompts/list":             true,
	"resources/list":           true,
	"resources/templates/list": true,
}

// readTools are the Loupe tools that a repeat cannot harm. A tool that is not
// listed counts as a write, so a new tool is safe before it is added here.
var readTools = map[string]bool{
	"analysis_get":          true,
	"analytics_settings_get": true,
	"artifact_get":          true,
	"artifact_list":         true,
	"artifact_read":         true,
	"board_columns":         true,
	"bridge_host_samples":   true,
	"bridge_list":           true,
	"bucket_rule_list":      true,
	"card_get":              true,
	"card_get_history":      true,
	"card_list":             true,
	"card_search":           true,
	"document_get":          true,
	"document_get_review":   true,
	"document_list":         true,
	"experiment_get":        true,
	"feedback_list":         true,
	"graph_list":            true,
	"inbox_actions":         true,
	"inbox_get":             true,
	"inbox_list":            true,
	"inbox_search":          true,
	"metric_list":           true,
	"metric_query":          true,
	"project_current":       true,
	"readiness_get":         true,
	"series_list":           true,
	"tag_list":              true,
	"worker_run_get":        true,
	"worker_run_list":       true,
	"worker_run_tool_calls": true,
	"workflow_get":          true,
}

// isRead reports whether the body holds one JSON-RPC request that changes
// nothing on Loupe. Any body it cannot read counts as a write.
func isRead(rewind func() (io.ReadCloser, error)) bool {
	if rewind == nil {
		return false
	}
	body, err := rewind()
	if err != nil {
		return false
	}
	defer body.Close()

	var msg struct {
		Method string `json:"method"`
		Params struct {
			Name string `json:"name"`
		} `json:"params"`
	}
	if json.NewDecoder(body).Decode(&msg) != nil {
		return false
	}
	if msg.Method == "tools/call" {
		return readTools[msg.Params.Name]
	}

	return readMethods[msg.Method]
}
