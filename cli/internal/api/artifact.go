package api

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"net/textproto"
	"net/url"
	"sort"
	"strings"
)

// SessionHeader names the session that makes a request. The store route reads
// it to refuse a worker and to say who stored a version.
const SessionHeader = "X-Loupe-Session"

// ArtifactFile is one file of a version to store. Path is relative, with
// slashes, and ContentType is the type the server keeps for it.
type ArtifactFile struct {
	Path        string
	ContentType string
	Contents    []byte
}

// ArtifactPush is the input of PushArtifact. A new artifact needs a Name, and
// a new version of an existing one needs its ArtifactID.
type ArtifactPush struct {
	Name       string
	ArtifactID string
	SourceLink string
	Card       string
	// Session is the id of the agent session that stores the files, or "".
	Session string
	Files   []ArtifactFile
}

// ArtifactStored is what the store route answers: the stable path of the
// artifact and the path of the version just stored.
type ArtifactStored struct {
	ArtifactID    string `json:"artifactId"`
	VersionNumber int    `json:"versionNumber"`
	Path          string `json:"path"`
	VersionPath   string `json:"versionPath"`
}

// ArtifactRefused is a store the server refused with a 422. Errors maps the
// field to the reason.
type ArtifactRefused struct {
	Errors map[string]string
}

func (e *ArtifactRefused) Error() string {
	fields := make([]string, 0, len(e.Errors))
	for field := range e.Errors {
		fields = append(fields, field)
	}
	sort.Strings(fields)
	reasons := make([]string, 0, len(fields))
	for _, field := range fields {
		reasons = append(reasons, e.Errors[field])
	}
	if len(reasons) == 0 {
		return "the server refused the artifact"
	}

	return strings.Join(reasons, "; ")
}

// PushArtifact stores the files as a new artifact or a new version of one, in
// the project named by handle.
func (c *Client) PushArtifact(ctx context.Context, handle string, push ArtifactPush) (ArtifactStored, error) {
	var out ArtifactStored

	var body bytes.Buffer
	form := multipart.NewWriter(&body)
	fields := [][2]string{{"name", push.Name}, {"artifactId", push.ArtifactID}, {"sourceLink", push.SourceLink}, {"card", push.Card}}
	for _, f := range fields {
		if f[1] == "" {
			continue
		}
		if err := form.WriteField(f[0], f[1]); err != nil {
			return out, err
		}
	}
	for _, f := range push.Files {
		if err := form.WriteField("paths[]", f.Path); err != nil {
			return out, err
		}
	}
	for i, f := range push.Files {
		header := textproto.MIMEHeader{}
		header.Set("Content-Disposition", fmt.Sprintf(`form-data; name="files[]"; filename="file-%d"`, i))
		header.Set("Content-Type", f.ContentType)
		part, err := form.CreatePart(header)
		if err != nil {
			return out, err
		}
		if _, err := part.Write(f.Contents); err != nil {
			return out, err
		}
	}
	if err := form.Close(); err != nil {
		return out, err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost,
		c.baseURL+"/api/projects/"+url.PathEscape(handle)+"/artifacts", bytes.NewReader(body.Bytes()))
	if err != nil {
		return out, err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", form.FormDataContentType())
	if push.Session != "" {
		req.Header.Set(SessionHeader, push.Session)
	}

	resp, err := c.do(req)
	if err != nil {
		return out, fmt.Errorf("store the artifact in %s: %w", handle, err)
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusCreated, http.StatusOK:
	case http.StatusNotFound:
		return out, fmt.Errorf("%w: %s", notFound(resp.Body), handle)
	case http.StatusUnprocessableEntity:
		var refusal struct {
			Errors map[string]string `json:"errors"`
		}
		data, _ := io.ReadAll(io.LimitReader(resp.Body, maxBody))
		if err := json.Unmarshal(data, &refusal); err != nil || len(refusal.Errors) == 0 {
			return out, errors.New("the server refused the artifact (HTTP 422): " + strings.TrimSpace(clip(string(data), 512)))
		}

		return out, &ArtifactRefused{Errors: refusal.Errors}
	case http.StatusUnauthorized, http.StatusForbidden:
		return out, fmt.Errorf("the store was rejected (HTTP %d): the API token must have the agent scope, and the project must be yours", resp.StatusCode)
	case http.StatusTooManyRequests:
		return out, errors.New("the store was rate limited (HTTP 429): wait a minute and try again")
	case http.StatusRequestEntityTooLarge:
		return out, errors.New("the server refused the request as too large (HTTP 413): send fewer or smaller files")
	default:
		detail, _ := io.ReadAll(io.LimitReader(resp.Body, 512))

		return out, fmt.Errorf("the store failed (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(detail)))
	}

	if err := decodeBody(resp.Body, &out); err != nil {
		return out, fmt.Errorf("decode the store answer: %w", err)
	}

	return out, nil
}
