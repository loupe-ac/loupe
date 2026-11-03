package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/projectfile"
)

const projectEnv = "LOUPE_PROJECT"

const waysToChooseProject = "pass --project <id or slug>, set " + projectEnv + ", or write " + projectfile.Name + " with `loupe project link`"

// publicProject gives the project of a command whose path needs one. It tries
// --project, LOUPE_PROJECT, the project link of the setup, the project file,
// then the single project of the credential. It never guesses: no project, or
// several, exits 2. The first two sources take an id or a slug, as the API does.
func publicProject(ctx context.Context, s *publicSession, flag string, o sendOptions) (string, error) {
	if v := strings.TrimSpace(flag); v != "" {
		return v, nil
	}
	if v := strings.TrimSpace(os.Getenv(projectEnv)); v != "" {
		return v, nil
	}

	dir, err := os.Getwd()
	if err != nil {
		return "", &exitError{exitUsage, err}
	}
	link, err := setupLink(dir)
	if err != nil {
		return "", &exitError{exitUsage, fmt.Errorf("read the project links of the setup: %w", err)}
	}
	if link != "" {
		return link, nil
	}
	file, err := projectfile.Load(dir)
	switch {
	case err == nil && file.Project != "":
		return file.Project, nil
	case err != nil && !errors.Is(err, projectfile.ErrNoFile):
		return "", &exitError{exitUsage, err}
	}

	resp, err := s.send(ctx, api.PublicRequest{
		Method: http.MethodGet,
		Path:   "/api/v1/projects",
		Query:  url.Values{"limit": {"2"}, "fields": {"id"}},
	}, o)
	if err != nil {
		return "", err
	}
	var list struct {
		Items []struct {
			ID string `json:"id"`
		} `json:"items"`
	}
	if err := json.Unmarshal(resp.Body, &list); err != nil {
		return "", &exitError{1, fmt.Errorf("read the list of projects: %w", err)}
	}

	switch len(list.Items) {
	case 1:
		return list.Items[0].ID, nil
	case 0:
		return "", &exitError{exitUsage, errors.New("this credential reaches no project: " + waysToChooseProject)}
	default:
		return "", &exitError{exitUsage, errors.New("this credential reaches more than one project: " + waysToChooseProject)}
	}
}
