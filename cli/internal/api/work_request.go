package api

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// The states of a work request.
const (
	WorkRequestOpen      = "open"
	WorkRequestClaimed   = "claimed"
	WorkRequestDone      = "done"
	WorkRequestRefused   = "refused"
	WorkRequestExpired   = "expired"
	WorkRequestCancelled = "cancelled"
)

// WorkRequest is one piece of agent work on a subject, such as a card, that
// the server offers to the bridges. It arrives as a bridge.work_request event,
// in each heartbeat reply, and in the answer to a claim. A null capability
// decodes as "".
type WorkRequest struct {
	Type          string             `json:"type"`
	ProjectID     string             `json:"projectId"`
	Subject       WorkRequestSubject `json:"subject"`
	WorkRequestID string             `json:"workRequestId"`
	Kind          string             `json:"kind"`
	Capability    string             `json:"capability"`
	State         string             `json:"state"`
	// SubjectType and SubjectID name what the work is about.
	SubjectType string `json:"subjectType"`
	SubjectID   string `json:"subjectId"`
	// CardNumber is the label a person sees, for a card subject only.
	CardNumber int       `json:"cardNumber,omitempty"`
	RuleID     string    `json:"ruleId"`
	CreatedAt  time.Time `json:"createdAt"`
	// ResumeSessionID is the session of an unfinished run of the card and
	// kind, which the run of this request resumes. A null decodes as "".
	ResumeSessionID string `json:"resumeSessionId,omitempty"`
	// Prompt is the app prompt of the kind, with the placeholders of a work
	// entry. A bridge that sets appPrompts runs it for a kind its work map does
	// not hold. A null decodes as "".
	Prompt string `json:"prompt,omitempty"`
	// Context is what the card held when the request opened.
	Context WorkRequestContext `json:"context"`
	// Model and Effort are what the run asks claude for. Model wins over the
	// model of the work entry. A null decodes as "".
	Model  string `json:"model,omitempty"`
	Effort string `json:"effort,omitempty"`
	// InboxActionID, InboxItemNumber and InboxItemID name the inbox action the owner
	// clicked to send an agent or to retry the card, and its item. They carry ids only, and never
	// the owner's note. A null decodes as "" or 0, and an older server sends
	// none.
	InboxActionID   string `json:"inboxActionId,omitempty"`
	InboxItemNumber int    `json:"inboxItemNumber,omitempty"`
	InboxItemID     string `json:"inboxItemId,omitempty"`
	// Branch and Params belong to a request with the capability run-command.
	// Branch names the worktree the command runs in, and an absent one means
	// DefaultBranch. Params hold one value for each parameter of the entry.
	Branch string     `json:"branch,omitempty"`
	Params WorkParams `json:"params,omitempty"`
	// Run is the run list of the entry, with its placeholders, as the owner
	// confirmed it. The bridge refuses the request when its entry differs. An
	// older server sends none, and the bridge then compares nothing.
	Run []string `json:"run,omitempty"`
	// TargetBridgeID names the one bridge that may take the request. A null
	// decodes as "", which any bridge may take.
	TargetBridgeID string `json:"targetBridgeId,omitempty"`
}

// ForBridge reports whether the bridge may take the request. A request with a
// target belongs to that bridge alone, whatever the case of the id.
func (w WorkRequest) ForBridge(bridgeID string) bool {
	return w.TargetBridgeID == "" || strings.EqualFold(w.TargetBridgeID, bridgeID)
}

// DefaultBranch is the branch a run-command request runs on when it names none.
const DefaultBranch = "main"

// RunBranch is the branch the request runs on.
func (w WorkRequest) RunBranch() string {
	if w.Branch == "" {
		return DefaultBranch
	}

	return w.Branch
}

// WorkParams are the parameter values of a run-command request, by name. It
// decodes null and an empty list, which a PHP server writes for an empty map,
// as no values.
type WorkParams map[string]string

// UnmarshalJSON reads an object of strings, null, or an empty list.
func (p *WorkParams) UnmarshalJSON(data []byte) error {
	trimmed := bytes.TrimSpace(data)
	if string(trimmed) == "null" || string(trimmed) == "[]" {
		*p = nil

		return nil
	}
	var m map[string]string
	if err := json.Unmarshal(trimmed, &m); err != nil {
		return err
	}
	*p = m

	return nil
}

// Efforts are the levels claude --effort takes.
var Efforts = []string{"low", "medium", "high", "xhigh", "max"}

// SubjectCard is the subject type of a card.
const SubjectCard = "card"

// SubjectInboxItem is the subject type of an inbox item.
const SubjectInboxItem = "inbox-item"

// OnCard reports whether the work is about a card.
func (w WorkRequest) OnCard() bool {
	return w.SubjectType == SubjectCard
}

// WorkRequestContext is the pull request a work request acts on and why, and
// the document it revises, the inbox action it starts from, as the card held them when the request opened. An
// older server sends no context, and an unknown value is null. Both decode as
// 0 or "".
type WorkRequestContext struct {
	PullRequestNumber int    `json:"pullRequestNumber"`
	PullRequestURL    string `json:"pullRequestUrl"`
	HeadSHA           string `json:"headSha"`
	Reason            string `json:"reason"`
	DocumentID        string `json:"documentId"`
	EpicBranch        string `json:"epicBranch"`
	InboxActionID     string `json:"inboxActionId"`
	InboxItemNumber   int    `json:"inboxItemNumber"`
	InboxItemID       string `json:"inboxItemId"`
}

// WorkRequestSubject names the work request itself.
type WorkRequestSubject struct {
	Type string `json:"type"`
	ID   string `json:"id"`
}

// WorkClaim is one claim a heartbeat renews.
type WorkClaim struct {
	ID         string `json:"id"`
	ClaimToken string `json:"claimToken"`
}

// MaxWorkClaims is the server's cap on the claims of one heartbeat.
const MaxWorkClaims = 200

// Claim is the answer to a claim. WorkRequest is decoded and not checked. Run
// it through event.CheckWorkRequest before use.
type Claim struct {
	WorkRequestID string
	ClaimToken    string
	LeaseUntil    time.Time
	WorkRequest   WorkRequest
}

// ErrWorkRequestAlreadyClaimed marks a request that is not open. Another
// bridge holds it, or it settled.
var ErrWorkRequestAlreadyClaimed = errors.New("the work request is not open")

// ErrWorkRequestNotFound marks a request the server does not hold for this
// bridge.
var ErrWorkRequestNotFound = errors.New("the server holds no such work request")

// ErrWorkRequestsUnsupported marks a 404 with no error code. The server has no
// work request endpoint, or agent push is switched off.
var ErrWorkRequestsUnsupported = errors.New("the server has no work request endpoint, or agent push is switched off")

// ErrClaimLost marks a result the server refused, because this bridge no
// longer holds the claim with that token.
var ErrClaimLost = errors.New("the bridge no longer holds the claim")

// WorkRequestRefusal is a 422 or a 429 from a work request endpoint. Code is
// the error code of the body, and empty for a 429.
type WorkRequestRefusal struct {
	Status int
	Code   string
}

func (e *WorkRequestRefusal) Error() string {
	if e.Code == "" {
		return fmt.Sprintf("the server refused the work request call (HTTP %d)", e.Status)
	}

	return fmt.Sprintf("the server refused the work request call (HTTP %d): %s", e.Status, e.Code)
}

// RateLimited reports whether the refusal is a rate limit, which clears on
// its own.
func (e *WorkRequestRefusal) RateLimited() bool {
	return e.Status == http.StatusTooManyRequests
}

// ClaimWorkRequest claims an open work request for this bridge. account is
// the account the bridge chose to run it, and "" sends no body.
func (c *Client) ClaimWorkRequest(ctx context.Context, bridgeID, id, account string) (Claim, error) {
	var body io.Reader
	if account != "" {
		raw, err := json.Marshal(struct {
			Account string `json:"account"`
		}{account})
		if err != nil {
			return Claim{}, err
		}
		body = bytes.NewReader(raw)
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.workRequestURL(bridgeID, id, "claim"), body)
	if err != nil {
		return Claim{}, err
	}
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}

	resp, err := c.do(req)
	if err != nil {
		return Claim{}, fmt.Errorf("claim work request %s: %w", id, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return Claim{}, fmt.Errorf("claim work request %s: %w", id, workRequestError(resp, ErrWorkRequestAlreadyClaimed, "already_claimed"))
	}

	var raw struct {
		WorkRequestID string      `json:"workRequestId"`
		ClaimToken    string      `json:"claimToken"`
		LeaseUntil    time.Time   `json:"leaseUntil"`
		WorkRequest   WorkRequest `json:"workRequest"`
	}
	if err := decodeBody(resp.Body, &raw); err != nil {
		return Claim{}, fmt.Errorf("decode the claim of work request %s: %w", id, err)
	}
	switch {
	case !strings.EqualFold(raw.WorkRequestID, id):
		return Claim{}, fmt.Errorf("the claim of work request %s answers for another request, %q", id, raw.WorkRequestID)
	case raw.ClaimToken == "":
		return Claim{}, fmt.Errorf("the claim of work request %s holds no claim token", id)
	case raw.LeaseUntil.IsZero():
		return Claim{}, fmt.Errorf("the claim of work request %s holds no leaseUntil", id)
	}

	return Claim{WorkRequestID: strings.ToLower(raw.WorkRequestID), ClaimToken: raw.ClaimToken, LeaseUntil: raw.LeaseUntil, WorkRequest: raw.WorkRequest}, nil
}

// SettleWorkRequest sends the result of a claimed request, done or refused,
// and returns the state the server stores. An empty reason sends no reason
// key.
func (c *Client) SettleWorkRequest(ctx context.Context, bridgeID, id, token, state, reason string) (string, error) {
	return c.putResult(ctx, bridgeID, id, struct {
		ClaimToken string `json:"claimToken"`
		State      string `json:"state"`
		Reason     string `json:"reason,omitempty"`
	}{token, state, strings.TrimSpace(reason)})
}

// putResult sends the result body of a claimed request, and returns the state
// the server stores.
func (c *Client) putResult(ctx context.Context, bridgeID, id string, payload any) (string, error) {
	body, err := json.Marshal(payload)
	if err != nil {
		return "", err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPut, c.workRequestURL(bridgeID, id, "result"), bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.do(req)
	if err != nil {
		return "", fmt.Errorf("settle work request %s: %w", id, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("settle work request %s: %w", id, workRequestError(resp, ErrClaimLost, "claim_lost"))
	}

	var stored struct {
		State string `json:"state"`
	}
	if err := decodeBody(resp.Body, &stored); err != nil {
		return "", fmt.Errorf("decode the result of work request %s: %w", id, err)
	}

	return stored.State, nil
}

// The causes of a hand-back.
const (
	CauseUsageLimit = "usage-limit"
	CauseNetwork    = "network"
)

// HandBackReading is the reading that stopped a run: the window, its use, its
// reset time and where the bridge read it. UsedPercent and ResetsAt send null
// when the source gives none.
type HandBackReading struct {
	Window      string     `json:"window"`
	UsedPercent *float64   `json:"usedPercent"`
	ResetsAt    *time.Time `json:"resetsAt"`
	Limited     bool       `json:"limited"`
	Source      string     `json:"source"`
}

// HandBack is the result of a run that a usage limit or a network outage
// stopped. The bridge gives the request back to Loupe in place of a refusal.
// A network hand-back holds no Reading, and sends none. HasAssistantTurn says the run
// did work, so a resume belongs on this machine.
type HandBack struct {
	Cause            string
	Account          string
	Reading          *HandBackReading
	SessionID        string
	HasAssistantTurn bool
}

// HandBackWorkRequest gives a claimed request back to Loupe with the state
// open, and returns the state the server stores.
func (c *Client) HandBackWorkRequest(ctx context.Context, bridgeID, id, token string, h HandBack) (string, error) {
	return c.putResult(ctx, bridgeID, id, struct {
		ClaimToken       string           `json:"claimToken"`
		State            string           `json:"state"`
		Cause            string           `json:"cause"`
		Account          string           `json:"account"`
		Reading          *HandBackReading `json:"reading,omitempty"`
		SessionID        string           `json:"sessionId"`
		HasAssistantTurn bool             `json:"hasAssistantTurn"`
	}{token, WorkRequestOpen, h.Cause, h.Account, h.Reading, h.SessionID, h.HasAssistantTurn})
}

func (c *Client) workRequestURL(bridgeID, id, action string) string {
	return c.baseURL + "/api/bridges/" + url.PathEscape(bridgeID) + "/work-requests/" + url.PathEscape(id) + "/" + action
}

// workRequestError reads a failed answer. conflict is the error of a 409 that
// carries conflictCode.
func workRequestError(resp *http.Response, conflict error, conflictCode string) error {
	detail, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
	var payload struct {
		Error string `json:"error"`
	}
	_ = json.Unmarshal(detail, &payload)

	switch {
	case resp.StatusCode == http.StatusConflict && payload.Error == conflictCode:
		return conflict
	case resp.StatusCode == http.StatusNotFound && payload.Error == "work_request_not_found":
		return ErrWorkRequestNotFound
	case resp.StatusCode == http.StatusNotFound && payload.Error == "":
		return ErrWorkRequestsUnsupported
	case resp.StatusCode == http.StatusUnprocessableEntity, resp.StatusCode == http.StatusTooManyRequests:
		return &WorkRequestRefusal{Status: resp.StatusCode, Code: payload.Error}
	}

	return fmt.Errorf("HTTP %d: %s", resp.StatusCode, strings.TrimSpace(string(detail)))
}

// decodeWorkRequests decodes each offer alone and drops one it cannot decode.
func decodeWorkRequests(raw []json.RawMessage) []WorkRequest {
	var out []WorkRequest
	for _, item := range raw {
		var w WorkRequest
		if json.Unmarshal(item, &w) == nil {
			out = append(out, w)
		}
	}

	return out
}

// decodeBlockedAccounts keeps each entry that names an account. A reply with
// no key gives nil, and one with an empty list gives an empty list.
func decodeBlockedAccounts(raw []json.RawMessage) []BlockedAccount {
	if raw == nil {
		return nil
	}
	out := []BlockedAccount{}
	for _, item := range raw {
		var b BlockedAccount
		if json.Unmarshal(item, &b) == nil && b.Account != "" {
			out = append(out, b)
		}
	}

	return out
}

// decodeLostClaims keeps each id that is a string, in lower case.
func decodeLostClaims(raw []json.RawMessage) []string {
	var out []string
	for _, item := range raw {
		var id string
		if json.Unmarshal(item, &id) == nil && id != "" {
			out = append(out, strings.ToLower(id))
		}
	}

	return out
}

// decodeWatchSessions keeps the string ids of a watch list and drops any
// other item.
func decodeWatchSessions(raw []json.RawMessage) []string {
	var out []string
	for _, item := range raw {
		var id string
		if json.Unmarshal(item, &id) == nil && id != "" {
			out = append(out, id)
		}
	}

	return out
}
