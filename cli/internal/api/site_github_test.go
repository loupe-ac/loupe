package api

import (
	"encoding/json"
	"testing"
)

func TestSiteReadsItsGitHubFacts(t *testing.T) {
	body := `{"id":"p","github":{"appInstalled":true,"repositories":[` +
		`{"path":"acme/app","defaultBranch":"main","rulesRead":"plan-limited","reviewRequired":false,"mergeApproval":null,"mergeApprovalSource":"unset"},` +
		`{"path":"acme/new","defaultBranch":null,"rulesRead":null,"reviewRequired":null,"mergeApproval":"person","mergeApprovalSource":"file"}]}}`
	var site Site
	if err := json.Unmarshal([]byte(body), &site); err != nil {
		t.Fatal(err)
	}
	if site.GitHub == nil || !site.GitHub.AppInstalled || len(site.GitHub.Repositories) != 2 {
		t.Fatalf("github = %+v", site.GitHub)
	}
	first, second := site.GitHub.Repositories[0], site.GitHub.Repositories[1]
	if first.Path != "acme/app" || *first.DefaultBranch != "main" || *first.RulesRead != "plan-limited" ||
		*first.ReviewRequired || first.MergeApproval != nil || first.MergeApprovalSource != "unset" {
		t.Fatalf("first = %+v", first)
	}
	if second.DefaultBranch != nil || second.RulesRead != nil || second.ReviewRequired != nil ||
		*second.MergeApproval != "person" || second.MergeApprovalSource != "file" {
		t.Fatalf("second = %+v", second)
	}
}

func TestSiteFromAnOlderAppHasNoGitHubFacts(t *testing.T) {
	var site Site
	if err := json.Unmarshal([]byte(`{"id":"p"}`), &site); err != nil {
		t.Fatal(err)
	}
	if site.GitHub != nil {
		t.Fatalf("github = %+v", site.GitHub)
	}
}
