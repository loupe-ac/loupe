// Package claudedesign compiles a Claude Design canvas, a .dc.html file, to a
// plain HTML page. It registers itself with artifactcompile.
package claudedesign

import (
	"bytes"
	"fmt"
	"path"
	"path/filepath"
	"slices"
	"sort"
	"strings"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/artifactcompile"
)

const canvasSuffix = ".dc.html"

func init() { artifactcompile.Register(compiler{}) }

type compiler struct{}

func (compiler) Claims(files []api.ArtifactFile) bool {
	for _, f := range files {
		if isCanvas(f.Path) {
			return true
		}
	}

	return false
}

func isCanvas(p string) bool { return strings.HasSuffix(p, canvasSuffix) }

func isSupportScript(p string) bool { return path.Base(p) == "support.js" }

// pagePath is the output path of an artboard.
func pagePath(canvas string, index bool) string {
	dir := path.Dir(canvas)
	name := "index.html"
	if !index {
		name = strings.TrimSuffix(path.Base(canvas), canvasSuffix) + ".html"
	}
	if dir == "." {
		return name
	}

	return dir + "/" + name
}

func (compiler) Compile(files []api.ArtifactFile, opts artifactcompile.Options) (artifactcompile.Result, error) {
	var boards []api.ArtifactFile
	var res artifactcompile.Result
	for _, f := range files {
		switch {
		case isCanvas(f.Path):
			boards = append(boards, f)
		case isSupportScript(f.Path):
		default:
			gaps := companionGaps(f)
			if len(gaps) > 0 {
				f.Contents = markCompanion(f, gaps)
			}
			res.Files = append(res.Files, f)
			res.Gaps = append(res.Gaps, gaps...)
		}
	}
	sort.Slice(boards, func(i, j int) bool { return boards[i].Path < boards[j].Path })

	indexAt, err := chooseIndex(boards, opts.Index)
	if err != nil {
		return artifactcompile.Result{}, err
	}
	blobs := collectBlobs(files)
	taken := map[string]bool{}
	for _, f := range res.Files {
		taken[f.Path] = true
	}
	outs := make([]string, len(boards))
	collided := make([]string, len(boards))
	links := map[string]string{}
	order := make([]int, 0, len(boards))
	if indexAt >= 0 {
		order = append(order, indexAt)
	}
	for i := range boards {
		if i != indexAt {
			order = append(order, i)
		}
	}
	for _, i := range order {
		b := boards[i]
		outs[i] = pagePath(b.Path, i == indexAt)
		if taken[outs[i]] {
			collided[i] = outs[i]
			outs[i] = freePath(outs[i], taken)
		}
		taken[outs[i]] = true
		links[b.Path] = outs[i]
	}
	for i, b := range boards {
		page, gaps := compilePage(b.Path, b.Contents, links, blobs)
		if collided[i] != "" {
			gaps = append(gaps, artifactcompile.Gap{File: b.Path, Line: 1, Feature: "an existing file named " + collided[i], Detail: "the page is written to " + outs[i] + " instead; move one of them"})
			page = markTop(page, gaps[len(gaps)-1])
		}
		if indexAt < 0 {
			g := artifactcompile.Gap{File: boards[0].Path, Line: 1, Feature: "more than one artboard with no --index", Detail: "run again with --index <artboard> to choose the page that becomes index.html"}
			page = markTop(page, g)
			if i == 0 {
				gaps = append(gaps, g)
			}
		}
		res.Gaps = append(res.Gaps, gaps...)
		res.Files = append(res.Files, api.ArtifactFile{Path: outs[i], ContentType: "text/html; charset=utf-8", Contents: page})
	}
	sort.Slice(res.Files, func(i, j int) bool { return res.Files[i].Path < res.Files[j].Path })

	return res, nil
}

// chooseIndex gives the position of the artboard that becomes index.html. It is
// -1 when several artboards are pushed and the caller names none. A name
// matches the path of an artboard with or without its suffix, else the base
// name when the name has no folder and one artboard has that base name.
func chooseIndex(boards []api.ArtifactFile, name string) (int, error) {
	if name == "" {
		if len(boards) == 1 {
			return 0, nil
		}

		return -1, nil
	}
	want := strings.TrimSuffix(path.Clean(filepath.ToSlash(name)), canvasSuffix)
	var found []int
	for i, b := range boards {
		if strings.TrimSuffix(b.Path, canvasSuffix) == want {
			found = append(found, i)
		}
	}
	if len(found) == 0 && !strings.Contains(want, "/") {
		for i, b := range boards {
			if strings.TrimSuffix(path.Base(b.Path), canvasSuffix) == want {
				found = append(found, i)
			}
		}
	}
	switch len(found) {
	case 1:
		return found[0], nil
	case 0:
		return 0, fmt.Errorf("--index %s names no artboard. The push holds: %s", name, boardList(boards, nil))
	}

	return 0, fmt.Errorf("--index %s names more than one artboard: %s. Use the full path of one", name, boardList(boards, found))
}

// boardList names the artboards at the positions, or all of them for nil.
func boardList(boards []api.ArtifactFile, at []int) string {
	var names []string
	for i, b := range boards {
		if at == nil || slices.Contains(at, i) {
			names = append(names, b.Path)
		}
	}

	return strings.Join(names, ", ")
}

// freePath gives a path beside out that no other file takes.
func freePath(out string, taken map[string]bool) string {
	stem := strings.TrimSuffix(out, ".html") + ".compiled"
	free := stem + ".html"
	for i := 2; taken[free]; i++ {
		free = fmt.Sprintf("%s-%d.html", stem, i)
	}

	return free
}

// markTop writes a gap marker at the start of a page.
func markTop(page []byte, g artifactcompile.Gap) []byte {
	return append([]byte(Marker(g)+"\n"), page...)
}

// Marker is the comment that flags a gap in a written page.
func Marker(g artifactcompile.Gap) string {
	text := strings.NewReplacer("\n", " ", "\r", " ").Replace(g.Feature + ": " + g.Detail)
	for strings.Contains(text, "--") {
		text = strings.ReplaceAll(text, "--", "- -")
	}

	return "<!-- loupe:gap " + strings.TrimSuffix(strings.TrimSpace(text), ":") + " -->"
}

// forbidden is text a compiled page must not hold, besides a raw /_blob/ path.
var forbidden = []string{canvasSuffix, "{{", "<sc-", "DCLogic", "support.js"}

// forbiddenIn gives the first text of s that a compiled page must not hold. A
// relative link to a blob file, such as ../_blob/x.png, is not a raw path.
func forbiddenIn(s string) string {
	for _, bad := range forbidden {
		if strings.Contains(s, bad) {
			return bad
		}
	}
	if blobRe.MatchString(s) {
		return "/_blob/"
	}

	return ""
}

// companionGaps checks a text file pushed beside the canvas for canvas text,
// because the compiler copies it unchanged.
func companionGaps(f api.ArtifactFile) []artifactcompile.Gap {
	if bytes.IndexByte(f.Contents[:min(len(f.Contents), 8000)], 0) >= 0 {
		return nil
	}
	var gaps []artifactcompile.Gap
	for i, line := range strings.Split(string(f.Contents), "\n") {
		if bad := forbiddenIn(line); bad != "" {
			gaps = append(gaps, artifactcompile.Gap{File: f.Path, Line: i + 1, Feature: "canvas text left in the output", Detail: fmt.Sprintf("%q is left in the file", bad)})
		}
	}

	return gaps
}

// markCompanion writes a gap marker before each gap line of a companion file,
// in the comment syntax of its type, so the file stays valid.
func markCompanion(f api.ArtifactFile, gaps []artifactcompile.Gap) []byte {
	at := map[int][]string{}
	for _, g := range gaps {
		at[g.Line] = append(at[g.Line], companionMarker(f.Path, g))
	}
	lines := strings.Split(string(f.Contents), "\n")
	var out []string
	for i, line := range lines {
		out = append(out, at[i+1]...)
		out = append(out, line)
	}

	return []byte(strings.Join(out, "\n"))
}

// companionMarker is the gap marker in the comment syntax of a file type. A
// type with no known comment syntax gets the marker as a plain line.
func companionMarker(p string, g artifactcompile.Gap) string {
	switch strings.ToLower(path.Ext(p)) {
	case ".css", ".js", ".mjs", ".cjs", ".ts":
		text := strings.TrimSuffix(strings.TrimPrefix(Marker(g), "<!-- "), " -->")

		return "/* " + strings.ReplaceAll(text, "*/", "* /") + " */"
	case ".html", ".htm", ".svg", ".xml", ".md":
		return Marker(g)
	default:
		return strings.TrimSuffix(strings.TrimPrefix(Marker(g), "<!-- "), " -->")
	}
}
