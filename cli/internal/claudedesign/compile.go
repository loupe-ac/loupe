// Package claudedesign compiles a Claude Design canvas, a .dc.html file, to a
// plain HTML page. It registers itself with artifactcompile.
package claudedesign

import (
	"bytes"
	"fmt"
	"path"
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
func pagePath(canvas string, single bool) string {
	dir := path.Dir(canvas)
	name := "index.html"
	if !single {
		name = strings.TrimSuffix(path.Base(canvas), canvasSuffix) + ".html"
	}
	if dir == "." {
		return name
	}

	return dir + "/" + name
}

func (compiler) Compile(files []api.ArtifactFile, _ artifactcompile.Options) (artifactcompile.Result, error) {
	var boards []api.ArtifactFile
	var res artifactcompile.Result
	for _, f := range files {
		switch {
		case isCanvas(f.Path):
			boards = append(boards, f)
		case isSupportScript(f.Path):
		default:
			res.Files = append(res.Files, f)
		}
	}
	sort.Slice(boards, func(i, j int) bool { return boards[i].Path < boards[j].Path })

	single := len(boards) == 1
	taken := map[string]bool{}
	for _, f := range res.Files {
		taken[f.Path] = true
	}
	for i, b := range boards {
		out := pagePath(b.Path, single)
		page, gaps := compilePage(b.Path, b.Contents)
		if taken[out] {
			gaps = append(gaps, artifactcompile.Gap{File: b.Path, Line: 1, Feature: "an existing file named " + out, Detail: "the page would replace it"})
			page = markTop(page, gaps[len(gaps)-1])
		}
		if !single {
			g := artifactcompile.Gap{File: boards[0].Path, Line: 1, Feature: "more than one artboard", Detail: "pick the first page and rename it to index.html"}
			page = markTop(page, g)
			if i == 0 {
				gaps = append(gaps, g)
			}
		}
		taken[out] = true
		res.Gaps = append(res.Gaps, gaps...)
		res.Files = append(res.Files, api.ArtifactFile{Path: out, ContentType: "text/html; charset=utf-8", Contents: page})
	}
	sort.Slice(res.Files, func(i, j int) bool { return res.Files[i].Path < res.Files[j].Path })

	return res, nil
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

// forbidden is text a compiled page must not hold.
var forbidden = []string{canvasSuffix, "{{", "<sc-", "DCLogic", "support.js"}

// checkOutput finds the canvas text that is left in a compiled page, and
// writes a marker before each line that holds it.
func checkOutput(file string, page []byte) ([]byte, []artifactcompile.Gap) {
	lines := bytes.Split(page, []byte("\n"))
	var gaps []artifactcompile.Gap
	var out [][]byte
	for i, line := range lines {
		for _, bad := range forbidden {
			if bytes.Contains(line, []byte(bad)) {
				g := artifactcompile.Gap{File: file, Line: i + 1, Feature: "canvas text left in the output", Detail: fmt.Sprintf("%q is on line %d of the compiled page", bad, i+1)}
				gaps = append(gaps, g)
				out = append(out, []byte(Marker(g)))

				break
			}
		}
		out = append(out, line)
	}
	if len(gaps) == 0 {
		return page, nil
	}

	return bytes.Join(out, []byte("\n")), gaps
}
