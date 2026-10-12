package claudedesign

import (
	"os"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/artifactcompile"
)

func fixture(t *testing.T, name string) []byte {
	t.Helper()
	b, err := os.ReadFile("testdata/" + name)
	if err != nil {
		t.Fatal(err)
	}

	return b
}

func compile(t *testing.T, files ...api.ArtifactFile) artifactcompile.Result {
	t.Helper()
	res, err := compiler{}.Compile(files, artifactcompile.Options{})
	if err != nil {
		t.Fatal(err)
	}

	return res
}

func canvas(path string, body string) api.ArtifactFile {
	return api.ArtifactFile{Path: path, Contents: []byte(body)}
}

func one(t *testing.T, res artifactcompile.Result, path string) string {
	t.Helper()
	for _, f := range res.Files {
		if f.Path == path {
			return string(f.Contents)
		}
	}
	t.Fatalf("no %s in %v", path, res.Files)

	return ""
}

func wrap(inner string) string {
	return "<html><head></head><body>\n<x-dc>\n" + inner + "\n</x-dc>\n</body></html>"
}

func scripted(script, markup string) string {
	return "<html><head></head><body>\n<x-dc>\n" + markup + "\n</x-dc>\n<script type=\"text/x-dc\" data-dc-script>\n" + script + "\n</script>\n</body></html>"
}

func assertClean(t *testing.T, page string) {
	t.Helper()
	for _, bad := range forbidden {
		if strings.Contains(page, bad) {
			t.Errorf("page holds %q:\n%s", bad, page)
		}
	}
}

func TestAStaticCanvasBecomesIndexHTML(t *testing.T) {
	res := compile(t,
		api.ArtifactFile{Path: "support.js", Contents: []byte("x")},
		api.ArtifactFile{Path: "logo.png", Contents: []byte("png")},
		canvas("Main.dc.html", string(fixture(t, "static.dc.html"))),
	)
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	var paths []string
	for _, f := range res.Files {
		paths = append(paths, f.Path)
	}
	if strings.Join(paths, ",") != "index.html,logo.png" {
		t.Fatalf("paths = %v", paths)
	}
	page := one(t, res, "index.html")
	assertClean(t, page)
	for _, want := range []string{
		`<meta name="viewport" content="width=device-width, initial-scale=1"/>`,
		`<style>`, `fonts.googleapis.com`, `<h1>Hello</h1>`, `href="Other.html#top"`,
	} {
		if !strings.Contains(page, want) {
			t.Errorf("missing %q in\n%s", want, page)
		}
	}
	head := page[:strings.Index(page, "</head>")]
	if !strings.Contains(head, "<style>") || strings.Contains(page, "hint-placeholder") || strings.Contains(page, "<helmet") || strings.Contains(page, lineAttr) {
		t.Errorf("bad page:\n%s", page)
	}
	if strings.Count(page, "viewport") != 1 {
		t.Errorf("viewport count in\n%s", page)
	}
}

func TestAScriptedCanvasIsFilled(t *testing.T) {
	res := compile(t, canvas("A.dc.html", string(fixture(t, "scripted.dc.html"))))
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	page := one(t, res, "index.html")
	assertClean(t, page)
	for _, want := range []string{
		"<h1>Plan &amp; &#34;go&#34;</h1>",
		`class="calm"`,
		`title="a Plan &amp; &#34;go&#34; b"`,
		"3 of ",
		"<span>One &lt;b&gt;</span>",
		"<b>done</b>",
		"<i>x</i>",
		"<i>y</i>",
		"<span>Two</span>",
		"<div><em>raw</em></div>",
	} {
		if !strings.Contains(page, want) {
			t.Errorf("missing %q in\n%s", want, page)
		}
	}
	if strings.Contains(page, "never") || strings.Count(page, `class="card"`) != 2 || strings.Count(page, "<b>done</b>") != 1 {
		t.Errorf("bad page:\n%s", page)
	}
}

func TestATextValueIsEscaped(t *testing.T) {
	res := compile(t, canvas("A.dc.html", scripted(
		`class Component extends DCLogic { renderVals() { return { v: '<i onclick="x()">&amp;</i>' }; } }`,
		`<p title="{{ v }}">{{ v }}</p>`)))
	page := one(t, res, "index.html")
	if strings.Contains(page, "<i ") || !strings.Contains(page, "&lt;i onclick=&#34;x()&#34;&gt;&amp;amp;&lt;/i&gt;") {
		t.Fatalf("page:\n%s", page)
	}
}

func TestALoopingScriptIsInterrupted(t *testing.T) {
	old := scriptTimeout
	scriptTimeout = 100 * time.Millisecond
	t.Cleanup(func() { scriptTimeout = old })

	start := time.Now()
	res := compile(t, canvas("A.dc.html", scripted(`class Component extends DCLogic { renderVals() { while (true) {} } }`, `<p>x</p>`)))
	if time.Since(start) > 3*time.Second {
		t.Fatal("the script was not interrupted")
	}
	if len(res.Gaps) != 1 || res.Gaps[0].Feature != "a script that fails" || !strings.Contains(res.Gaps[0].Detail, "ran for more than") {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	if !strings.Contains(one(t, res, "index.html"), "<!-- loupe:gap a script that fails") {
		t.Fatal("no marker")
	}
}

func TestAScriptWithNoComponentIsAGap(t *testing.T) {
	res := compile(t, canvas("A.dc.html", scripted(`var x = 1;`, `<p>x</p>`)))
	if len(res.Gaps) != 1 || !strings.Contains(res.Gaps[0].Detail, "no Component") {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
}

func TestEachGapKindIsNamedWithItsLine(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { go: 1, o: {a: 1} }; } }`
	cases := map[string]struct {
		file, feature string
		line          int
		marker        string
	}{
		"an event attribute": {scripted(script, "<p>a</p>\n<button onClick=\"{{ go }}\">x</button>"), "event attribute onclick", 4, `<!-- loupe:gap event attribute onclick: `},
		"a dc tag":           {wrap("<p>a</p>\n<dc-import src=\"Pricing.dc.html\"></dc-import>"), "<dc-import> (an embedded artboard)", 4, `<!-- loupe:gap <dc-import>`},
		"sc-else":            {wrap("<p>a</p>\n<sc-else><p>no</p></sc-else>"), "<sc-else>", 4, `<!-- loupe:gap <sc-else>`},
		"a blob path":        {wrap("<p>a</p>\n<img src=\"/_blob/abc.png\">"), "a /_blob/ path", 4, `<!-- loupe:gap a /_blob/ path`},
		"an expression":      {scripted(script, "<p>a</p>\n<p>{{ go + 1 }}</p>"), "a hole that is not a plain path", 4, `<!-- loupe:gap a hole that is not a plain path`},
		"an object":          {scripted(script, "<p>a</p>\n<p>{{ o }}</p>"), "a hole whose value is not text", 4, `<!-- loupe:gap a hole whose value`},
		"leftover text":      {wrap("<p>a</p>\n<p>see Pricing.dc.html</p>"), "canvas text left in the output", 0, `<!-- loupe:gap canvas text left in the output`},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			res := compile(t, canvas("A.dc.html", c.file))
			if len(res.Gaps) == 0 {
				t.Fatalf("no gap, page:\n%s", one(t, res, "index.html"))
			}
			g := res.Gaps[0]
			if g.Feature != c.feature || g.File != "A.dc.html" || (c.line != 0 && g.Line != c.line) {
				t.Fatalf("gap = %+v", g)
			}
			page := one(t, res, "index.html")
			if !strings.Contains(page, c.marker) {
				t.Fatalf("no marker %q in\n%s", c.marker, page)
			}
			if strings.Contains(page, "--> -->") || strings.Contains(page, lineAttr) {
				t.Fatalf("bad page:\n%s", page)
			}
		})
	}
}

func TestAMarkerKeepsTheSourceFragmentAfterIt(t *testing.T) {
	res := compile(t, canvas("A.dc.html", wrap("<sc-else><p>keep me</p></sc-else>")))
	page := one(t, res, "index.html")
	if i, j := strings.Index(page, "loupe:gap"), strings.Index(page, "keep me"); i < 0 || j < i {
		t.Fatalf("page:\n%s", page)
	}
}

func TestAMarkerHoldsNoDoubleDash(t *testing.T) {
	m := Marker(artifactcompile.Gap{Feature: "a--b", Detail: "x -- y"})
	if strings.Contains(strings.TrimSuffix(strings.TrimPrefix(m, "<!--"), "-->"), "--") {
		t.Fatalf("marker = %q", m)
	}
}

func TestSeveralArtboardsAreOneGapAndKeepTheirNames(t *testing.T) {
	res := compile(t,
		canvas("B.dc.html", wrap(`<a href="A.dc.html">a</a>`)),
		canvas("A.dc.html", wrap("<p>a</p>")),
	)
	if len(res.Gaps) != 1 || res.Gaps[0].Feature != "more than one artboard" || res.Gaps[0].File != "A.dc.html" {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	if !strings.Contains(one(t, res, "A.html"), "loupe:gap more than one artboard") || !strings.Contains(one(t, res, "B.html"), `href="A.html"`) {
		t.Fatal("pages not marked or linked")
	}
}

func TestACanvasInAFolderBecomesItsIndex(t *testing.T) {
	res := compile(t, canvas("site/Main.dc.html", wrap("<p>a</p>")))
	one(t, res, "site/index.html")
}

func TestAPageThatWouldReplaceAFileIsAGap(t *testing.T) {
	res := compile(t, canvas("Main.dc.html", wrap("<p>a</p>")), api.ArtifactFile{Path: "index.html", Contents: []byte("old")})
	if len(res.Gaps) != 1 || !strings.Contains(res.Gaps[0].Feature, "index.html") {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
}

func TestOnlyACanvasIsClaimed(t *testing.T) {
	if (compiler{}).Claims([]api.ArtifactFile{{Path: "index.html"}, {Path: "a/b.css"}}) {
		t.Fatal("claimed a plain folder")
	}
	if !(compiler{}).Claims([]api.ArtifactFile{{Path: "index.html"}, {Path: "a/Main.dc.html"}}) {
		t.Fatal("did not claim a canvas")
	}
}

func TestTheCompilerIsRegistered(t *testing.T) {
	res, err := artifactcompile.Run([]api.ArtifactFile{canvas("A.dc.html", wrap("<p>a</p>"))}, artifactcompile.Options{})
	if err != nil || !res.Compiled || len(res.Files) != 1 || res.Files[0].Path != "index.html" {
		t.Fatalf("res = %+v, err = %v", res, err)
	}
}
