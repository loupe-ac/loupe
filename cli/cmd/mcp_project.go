package cmd

import (
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"

	"github.com/loupe-ac/loupe/cli/internal/config"
	"github.com/loupe-ac/loupe/cli/internal/projectfile"
)

// mcpProject gives the project each request of `loupe mcp` names. The flag
// wins and the file is never read. Otherwise the project file in the working
// directory decides, and a malformed file at start is an error.
func mcpProject(flag string, notes io.Writer) (func() string, error) {
	project, _, err := mcpProjectSource(flag, notes)

	return project, err
}

// mcpProjectSource is mcpProject, and also gives the words that name where the
// current project came from. In a setup other than the default, the link of
// the setup decides first, and the project file is the fallback.
func mcpProjectSource(flag string, notes io.Writer) (project, source func() string, err error) {
	if flag != "" {
		if err := projectfile.Valid(flag); err != nil {
			return nil, nil, err
		}
		id := strings.ToLower(flag)

		return func() string { return id }, func() string { return "the --project flag" }, nil
	}

	dir, err := os.Getwd()
	if err != nil {
		return nil, nil, err
	}
	src := &projectSource{dir: dir, notes: notes}
	setup := config.Current()
	link := ""
	if setup.Source != config.SourceDefault {
		link, _ = projectfile.LoadLink(setup.Dir, projectfile.LinkKey(dir))
	}
	if err := src.start(); err != nil {
		if link == "" {
			return nil, nil, fmt.Errorf("%w\n\nWrite the file with `loupe project link`", err)
		}
		fmt.Fprintf(notes, "loupe mcp: %v; the link of the setup %s decides\n", err, setup.Dir)
	}
	if setup.Source == config.SourceDefault {
		return src.project, func() string { return projectfile.Name }, nil
	}
	linked := &linkedSource{setup: setup, key: projectfile.LinkKey(dir), file: src, notes: notes}

	return linked.project, linked.source, nil
}

// linkedSource gives the project that the link of a setup names for this
// repository, else the project file of the folder.
type linkedSource struct {
	setup config.Setup
	key   string
	file  *projectSource
	notes io.Writer

	mu     sync.Mutex
	last   string
	failed string
	used   string
}

func (l *linkedSource) project() string {
	l.mu.Lock()
	defer l.mu.Unlock()

	link, err := projectfile.LoadLink(l.setup.Dir, l.key)
	if err != nil {
		if err.Error() != l.failed {
			l.failed = err.Error()
			fmt.Fprintf(l.notes, "loupe mcp: %v\n", err)
		}
		link = l.last
	} else {
		l.failed = ""
	}
	if link != l.last {
		fmt.Fprintf(l.notes, "loupe mcp: the link of the setup %s now names project %s (was %s)\n", l.setup.Dir, orNone(link), orNone(l.last))
		l.last = link
	}
	if link != "" {
		l.used = l.linkWords()

		return link
	}
	l.used = projectfile.Name

	return l.file.project()
}

func (l *linkedSource) linkWords() string {
	return "the project link of the setup " + l.setup.Dir
}

func (l *linkedSource) source() string {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.used == "" {
		return projectfile.Name
	}

	return l.used
}

// setupLink gives the project that the setup links to the repository of dir.
// The default setup has no links, so it gives "".
func setupLink(dir string) (string, error) {
	setup := config.Current()
	if setup.Source == config.SourceDefault {
		return "", nil
	}

	return projectfile.LoadLink(setup.Dir, projectfile.LinkKey(dir))
}

// projectSource follows the project file in dir. It runs os.Stat on each call,
// and reads the file again only when the stat changed.
type projectSource struct {
	dir   string
	notes io.Writer

	mu     sync.Mutex
	seen   os.FileInfo
	last   string
	failed string
	// broken holds the error of the last failed load. A fix such as a new file
	// mode can leave the stat unchanged, so a broken file is read on each call.
	broken string
}

func (p *projectSource) start() error {
	p.seen, _ = os.Stat(filepath.Join(p.dir, projectfile.Name))
	file, err := projectfile.Load(p.dir)
	if errors.Is(err, projectfile.ErrNoFile) {
		return nil
	}
	if err != nil {
		p.seen = nil

		return err
	}
	p.last = file.Project

	return nil
}

// project gives the last good project. A file that fails to load keeps it, and
// a removed file names none. Each change writes one note.
func (p *projectSource) project() string {
	p.mu.Lock()
	defer p.mu.Unlock()

	info, err := os.Stat(filepath.Join(p.dir, projectfile.Name))
	if errors.Is(err, os.ErrNotExist) {
		p.broken = ""
		if p.seen != nil {
			p.seen = nil
			fmt.Fprintf(p.notes, "loupe mcp: %s is gone, so no project is sent (was %s)\n", projectfile.Name, orNone(p.last))
			p.last = ""
		}

		return p.last
	}
	if err != nil {
		if err.Error() != p.failed {
			p.failed = err.Error()
			fmt.Fprintf(p.notes, "loupe mcp: %v; the project stays %s\n", err, orNone(p.last))
		}

		return p.last
	}
	p.failed = ""
	if p.broken == "" && unchanged(p.seen, info) {
		return p.last
	}
	p.seen = info

	file, err := projectfile.Load(p.dir)
	if err != nil {
		if err.Error() != p.broken {
			p.broken = err.Error()
			fmt.Fprintf(p.notes, "loupe mcp: %v; the project stays %s\n", err, orNone(p.last))
		}

		return p.last
	}
	p.broken = ""
	if file.Project != p.last {
		fmt.Fprintf(p.notes, "loupe mcp: %s now names project %s (was %s)\n", projectfile.Name, orNone(file.Project), orNone(p.last))
		p.last = file.Project
	}

	return p.last
}

// unchanged reports whether two stats show the same file with the same
// contents. A rename replaces the file, which the time and size can miss.
func unchanged(before, after os.FileInfo) bool {
	return before != nil && os.SameFile(before, after) &&
		before.ModTime().Equal(after.ModTime()) && before.Size() == after.Size()
}

func orNone(project string) string {
	if project == "" {
		return "none"
	}

	return project
}

// setupFolder is the folder of the current setup, or "" for the default.
func setupFolder() string {
	if setup := config.Current(); setup.Source != config.SourceDefault {
		return setup.Dir
	}

	return ""
}
