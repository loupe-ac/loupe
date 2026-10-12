// Package artifactcompile turns the files of one artifact push into plain
// files. A compiler claims a file set it knows, such as a design canvas.
package artifactcompile

import "github.com/ubermuda/loupe/cli/internal/api"

// Compiler turns the files of one push into plain files.
type Compiler interface {
	Claims(files []api.ArtifactFile) bool
	Compile(files []api.ArtifactFile, opts Options) (Result, error)
}

// Options carries the choices of the caller. Index names the artboard that
// becomes index.html.
type Options struct{ Index string }

// Gap is one thing a compiler cannot compile. Line is a line of File.
type Gap struct {
	File    string
	Line    int
	Feature string
	Detail  string
}

// Result is the output of Run. Files carry a marker at each gap. Compiled is
// false when no compiler claimed the files, and Files is then the input.
type Result struct {
	Files    []api.ArtifactFile
	Gaps     []Gap
	Compiled bool
}

var compilers []Compiler

// Register adds a compiler. Run asks the compilers in the order they came.
func Register(c Compiler) { compilers = append(compilers, c) }

// Run hands the files to the first compiler that claims them. Files that no
// compiler claims come back unchanged.
func Run(files []api.ArtifactFile, opts Options) (Result, error) {
	for _, c := range compilers {
		if c.Claims(files) {
			res, err := c.Compile(files, opts)
			res.Compiled = true

			return res, err
		}
	}

	return Result{Files: files}, nil
}
