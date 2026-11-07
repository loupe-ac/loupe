// Package projectfile reads .loupe.yaml, the file that names the Loupe project
// a repository belongs to.
//
// The reader accepts keys it does not know, so a later version that adds a
// projects list or a path map does not break a file written today.
package projectfile

import (
	"bytes"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"go.yaml.in/yaml/v3"
)

// Name is the file name, in the working directory.
const Name = ".loupe.yaml"

// ErrNoFile reports that the directory holds no .loupe.yaml. It is not a
// failure: a user with one project needs no file, because the server falls
// back to the single project the grant covers.
var ErrNoFile = errors.New("no " + Name + " in this directory")

// uuidPattern matches the RFC 4122 text a project id is written in.
var uuidPattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

// maxSize caps the file the reader buffers. A project file is one short line,
// so anything larger is a mistake rather than a file to parse.
const maxSize = 64 << 10

// File is what .loupe.yaml says. Only Project is read today.
type File struct {
	// Project is the project id, lower-case RFC 4122, or "" when the file
	// names none.
	Project string `yaml:"project"`
}

// Load reads .loupe.yaml from dir. It returns ErrNoFile when the file is
// absent, and an error that names the file for anything it cannot read.
func Load(dir string) (File, error) {
	path := filepath.Join(dir, Name)

	info, err := os.Stat(path)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return File{}, ErrNoFile
		}

		return File{}, fmt.Errorf("%s: %w", path, err)
	}
	if info.Size() > maxSize {
		return File{}, fmt.Errorf("%s: larger than %d bytes, which is not a project file", path, maxSize)
	}

	b, err := os.ReadFile(path)
	if err != nil {
		return File{}, fmt.Errorf("%s: %w", path, err)
	}

	var f File
	if err := yaml.Unmarshal(b, &f); err != nil {
		return File{}, fmt.Errorf("%s: %w", path, err)
	}

	f.Project = strings.TrimSpace(f.Project)
	if f.Project != "" && !uuidPattern.MatchString(strings.ToLower(f.Project)) {
		return File{}, fmt.Errorf("%s: project must be a project id such as 0192f3c4-5d6e-7f80-9123-456789abcdef, got %q", path, f.Project)
	}
	f.Project = strings.ToLower(f.Project)

	return f, nil
}

// Valid refuses a projectID that is not an RFC 4122 id in either case.
func Valid(projectID string) error {
	if !uuidPattern.MatchString(strings.ToLower(projectID)) {
		return fmt.Errorf("project id must be a project id such as 0192f3c4-5d6e-7f80-9123-456789abcdef, got %q", projectID)
	}

	return nil
}

// Write makes .loupe.yaml in dir name projectID. With no file it writes a new
// one. With an existing file it sets the project key in place and keeps every
// other key, key order and comment. It refuses a file that does not parse, is
// larger than maxSize or has no mapping at the top, and leaves that file
// untouched. It writes through a temporary file and a rename, so an
// interrupted write leaves the previous file intact.
func Write(dir, projectID string) error {
	if err := Valid(projectID); err != nil {
		return err
	}
	id := strings.ToLower(projectID)
	path := filepath.Join(dir, Name)

	body, err := editedBody(path, id)
	if err != nil {
		return err
	}

	f, err := os.CreateTemp(dir, Name+".*")
	if err != nil {
		return fmt.Errorf("write %s: %w", Name, err)
	}
	tmp := f.Name()
	defer os.Remove(tmp)

	if _, err := f.Write(body); err != nil {
		f.Close()

		return fmt.Errorf("write %s: %w", Name, err)
	}
	if err := f.Close(); err != nil {
		return fmt.Errorf("write %s: %w", Name, err)
	}
	if err := os.Chmod(tmp, 0o644); err != nil {
		return fmt.Errorf("write %s: %w", Name, err)
	}
	if err := os.Rename(tmp, path); err != nil {
		return fmt.Errorf("write %s: %w", Name, err)
	}

	return nil
}

// editedBody returns the file content that names id: the existing file with
// its project key set, or the fresh file when none exists.
func editedBody(path, id string) ([]byte, error) {
	fresh := []byte("# The Loupe project this repository belongs to. `loupe mcp` sends it with\n" +
		"# every request, so an agent acts on this project and no other.\n" +
		"project: " + id + "\n")

	info, err := os.Stat(path)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return fresh, nil
		}

		return nil, fmt.Errorf("%s: %w", path, err)
	}
	if info.Size() > maxSize {
		return nil, fmt.Errorf("%s: larger than %d bytes, which is not a project file", path, maxSize)
	}

	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	if len(strings.TrimSpace(string(raw))) == 0 {
		return fresh, nil
	}

	var doc yaml.Node
	if err := yaml.Unmarshal(raw, &doc); err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	if doc.Kind != yaml.DocumentNode || len(doc.Content) != 1 || doc.Content[0].Kind != yaml.MappingNode {
		return nil, fmt.Errorf("%s: the top level is not a mapping, so the project key cannot be set", path)
	}

	root := doc.Content[0]
	value := &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!str", Value: id}
	set := false
	for i := 0; i+1 < len(root.Content); i += 2 {
		if root.Content[i].Value == "project" {
			old := root.Content[i+1]
			value.HeadComment, value.LineComment, value.FootComment = old.HeadComment, old.LineComment, old.FootComment
			root.Content[i+1] = value
			set = true

			break
		}
	}
	if !set {
		key := &yaml.Node{Kind: yaml.ScalarNode, Tag: "!!str", Value: "project"}
		root.Content = append(root.Content, key, value)
	}

	var out bytes.Buffer
	enc := yaml.NewEncoder(&out)
	enc.SetIndent(2)
	if err := enc.Encode(&doc); err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	if err := enc.Close(); err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}

	return out.Bytes(), nil
}
