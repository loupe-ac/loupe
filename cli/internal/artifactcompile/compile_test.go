package artifactcompile

import (
	"errors"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
)

type fake struct {
	claim bool
	err   error
}

func (f fake) Claims([]api.ArtifactFile) bool { return f.claim }
func (f fake) Compile(files []api.ArtifactFile, _ Options) (Result, error) {
	return Result{Files: files[:1], Gaps: []Gap{{File: "a", Line: 1}}}, f.err
}

func reset(t *testing.T) {
	t.Helper()
	old := compilers
	compilers = nil
	t.Cleanup(func() { compilers = old })
}

func TestFilesNoCompilerClaimsPassThrough(t *testing.T) {
	reset(t)
	Register(fake{claim: false})
	in := []api.ArtifactFile{{Path: "a.html"}, {Path: "b.css"}}
	res, err := Run(in, Options{})
	if err != nil || res.Compiled || len(res.Files) != 2 || len(res.Gaps) != 0 {
		t.Fatalf("res = %+v, err = %v", res, err)
	}
}

func TestTheFirstCompilerThatClaimsCompiles(t *testing.T) {
	reset(t)
	Register(fake{claim: false})
	Register(fake{claim: true})
	res, err := Run([]api.ArtifactFile{{Path: "a"}, {Path: "b"}}, Options{})
	if err != nil || !res.Compiled || len(res.Files) != 1 || len(res.Gaps) != 1 {
		t.Fatalf("res = %+v, err = %v", res, err)
	}
}

func TestACompilerErrorComesBack(t *testing.T) {
	reset(t)
	want := errors.New("boom")
	Register(fake{claim: true, err: want})
	if _, err := Run([]api.ArtifactFile{{Path: "a"}}, Options{}); !errors.Is(err, want) {
		t.Fatalf("err = %v", err)
	}
}
