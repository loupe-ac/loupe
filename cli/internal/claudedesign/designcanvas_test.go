package claudedesign

import (
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/artifactcompile"
)

const canvasFixture = "testdata/design-canvas"

// loadCanvasFixture reads the fixture folder as `loupe artifact push` does.
func loadCanvasFixture(t *testing.T) []api.ArtifactFile {
	t.Helper()
	var files []api.ArtifactFile
	err := filepath.WalkDir(canvasFixture, func(p string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return err
		}
		b, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(canvasFixture, p)
		files = append(files, api.ArtifactFile{Path: filepath.ToSlash(rel), Contents: b})

		return nil
	})
	if err != nil {
		t.Fatal(err)
	}

	return files
}

func compileCanvasFixture(t *testing.T) artifactcompile.Result {
	t.Helper()
	res, err := compiler{}.Compile(loadCanvasFixture(t), artifactcompile.Options{Index: "Main"})
	if err != nil {
		t.Fatal(err)
	}

	return res
}

func TestDesignCanvasFixtureHasNoGaps(t *testing.T) {
	res := compileCanvasFixture(t)
	for _, g := range res.Gaps {
		t.Errorf("gap: %+v", g)
	}
}

const dsDir = "project/ds/loupedesignsystem_686f56/"

func filesByPath(res artifactcompile.Result) map[string]string {
	out := map[string]string{}
	for _, f := range res.Files {
		out[f.Path] = string(f.Contents)
	}

	return out
}

func TestDesignCanvasPagesHoldNoCanvasText(t *testing.T) {
	res := compileCanvasFixture(t)
	pages := 0
	for _, f := range res.Files {
		if !strings.HasSuffix(f.Path, ".html") {
			continue
		}
		pages++
		for _, bad := range []string{"{{", "<x-import", "support.js", ".dc.html", "DCLogic", "<sc-"} {
			if strings.Contains(string(f.Contents), bad) {
				t.Errorf("%s holds %q", f.Path, bad)
			}
		}
	}
	if pages != 11 {
		t.Errorf("pages = %d, want 11", pages)
	}
}

func TestDesignCanvasKeepsOnlyWhatPagesNeed(t *testing.T) {
	res := compileCanvasFixture(t)
	got := filesByPath(res)
	want := []string{
		"_runtime/react.production.min.js", "_runtime/react-dom.production.min.js",
		dsDir + "components/bundle.js", dsDir + "components/bundle.w390.css", dsDir + "components/bundle.w1280.css",
		dsDir + "tokens.css",
		dsDir + "fonts/Geist-Variable.woff2", dsDir + "fonts/GeistMono-Variable.woff2",
		dsDir + "fonts/Manrope-latin.woff2", dsDir + "fonts/Manrope-latin-ext.woff2",
		dsDir + "fonts/DMSans-latin.woff2", dsDir + "fonts/DMSans-latin-ext.woff2",
	}
	for _, p := range want {
		if _, ok := got[p]; !ok {
			t.Errorf("missing %s", p)
		}
	}
	for _, p := range []string{dsDir + "components/bundle.css", dsDir + "tokens.json", "project/canvas.json"} {
		if _, ok := got[p]; ok {
			t.Errorf("%s should not ship", p)
		}
	}
	if len(res.Files) != 23 {
		t.Errorf("files = %d, want 23", len(res.Files))
	}
}

func TestDesignCanvasStylesheetsFollowTheArtboardWidth(t *testing.T) {
	got := filesByPath(compileCanvasFixture(t))
	phone := got[dsDir+"components/bundle.w390.css"]
	desktop := got[dsDir+"components/bundle.w1280.css"]
	const rule = ".lp-topbar__menu{display:none}"
	if strings.Contains(phone, "min-width:64rem") || strings.Contains(phone, rule) {
		t.Error("the phone sheet keeps a rule that holds only on a wide screen")
	}
	if !strings.Contains(desktop, "@media all{"+rule) {
		t.Error("the desktop sheet does not apply the wide screen rule")
	}
	if !strings.Contains(phone, "(hover:hover)") || !strings.Contains(desktop, "(prefers-reduced-motion:no-preference)") {
		t.Error("a query that is not about width changed")
	}
	if !strings.Contains(got["project/MainMobile.html"], `href="ds/loupedesignsystem_686f56/components/bundle.w390.css"`) {
		t.Error("the phone page does not link the phone sheet")
	}
	if !strings.Contains(got["index.html"], `href="project/ds/loupedesignsystem_686f56/components/bundle.w1280.css"`) {
		t.Error("the index page does not link the desktop sheet")
	}
}

func TestDesignCanvasPageDrawsComponentsInTheViewer(t *testing.T) {
	page := filesByPath(compileCanvasFixture(t))["project/WorkshopGuide.html"]
	for _, want := range []string{
		`<loupe-mount data-component="LoupeDesignSystem_686f56.AppShell"`,
		`&#34;crumbs&#34;:[&#34;Acme&#34;,&#34;Workshop&#34;]`,
		`&#34;projectName&#34;:&#34;Acme&#34;`,
		`&#34;LoupeDesignSystem_686f56.Button&#34;`,
		`<script src="../_runtime/react.production.min.js">`,
	} {
		if !strings.Contains(page, want) {
			t.Errorf("page lacks %s", want)
		}
	}
	react := strings.Index(page, "react.production.min.js")
	reactDOM := strings.Index(page, "react-dom.production.min.js")
	bundle := strings.Index(page, "components/bundle.js")
	mount := strings.Index(page, "loupe-mount'")
	if !(react < reactDOM && reactDOM < bundle && bundle < mount) {
		t.Errorf("script order is wrong: %d %d %d %d", react, reactDOM, bundle, mount)
	}
	head := page[:strings.Index(page, "</head>")]
	if strings.Contains(head, "bundle.js") {
		t.Error("the component script loads in the head, before React")
	}
}

func TestMountPointEscapesItsProps(t *testing.T) {
	src := `<html><head></head><body><x-dc>
<x-import component-from-global-scope="NS_abcdef.Box" title="{{evil}}" items="{{list}}" html-for="a" class="c"><b>kid</b></x-import>
</x-dc>
<script type="text/x-dc" data-dc-script>
class Component extends DCLogic { renderVals() { return { evil: '"><\/script><!-- x', list: [1, { a: '<\/script>' }] }; } }
</script></body></html>`
	res := compile(t, canvas("A.dc.html", src))
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	page := one(t, res, "index.html")
	if strings.Contains(page, "</script><!--") || strings.Contains(strings.SplitN(page, "<script", 2)[0], `"></script>`) {
		t.Error("a prop closed an element")
	}
	for _, want := range []string{`data-component="NS_abcdef.Box"`, `&#34;htmlFor&#34;:&#34;a&#34;`, `&#34;className&#34;:&#34;c&#34;`, `&#34;items&#34;:[1,{&#34;a&#34;:&#34;\u003c/script\u003e&#34;}]`, "<b>kid</b>"} {
		if !strings.Contains(page, want) {
			t.Errorf("page lacks %s\n%s", want, page)
		}
	}
}

func TestComponentWithoutANameIsAGap(t *testing.T) {
	res := compile(t, canvas("A.dc.html", wrap(`<x-import title="x">kid</x-import>`)))
	if len(res.Gaps) == 0 || !strings.Contains(res.Gaps[0].Feature, "x-import") {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	if strings.Contains(one(t, res, "index.html"), "<x-import ") && !strings.Contains(one(t, res, "index.html"), "loupe:gap") {
		t.Error("no marker")
	}
}

func TestScriptThatReadsWindowDoesNotFail(t *testing.T) {
	src := `<html><head></head><body><x-dc><p>{{ v }}</p></x-dc>
<script type="text/x-dc" data-dc-script>
class Component extends DCLogic { renderVals() { return { v: String(typeof window.localStorage) + String(window.Foo_abc123 !== undefined) }; } }
</script></body></html>`
	res := compile(t, canvas("A.dc.html", src))
	if len(res.Gaps) != 0 || !strings.Contains(one(t, res, "index.html"), "<p>undefinedtrue</p>") {
		t.Fatalf("gaps = %+v\n%s", res.Gaps, one(t, res, "index.html"))
	}
}

func TestMountScriptHoldsNoForbiddenText(t *testing.T) {
	if bad := forbiddenIn(mountJS); bad != "" {
		t.Errorf("mount script holds %q", bad)
	}
	if strings.Contains(strings.ToLower(mountJS), "</script") {
		t.Error("mount script closes a script element")
	}
}
