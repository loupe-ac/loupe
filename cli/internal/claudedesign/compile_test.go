package claudedesign

import (
	"os"
	"regexp"
	"strings"
	"testing"
	"time"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/artifactcompile"
)

var markerRe = regexp.MustCompile(`<!-- loupe:gap .*? -->`)

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

	return compileIndex(t, "", files...)
}

func compileIndex(t *testing.T, index string, files ...api.ArtifactFile) artifactcompile.Result {
	t.Helper()
	res, err := compiler{}.Compile(files, artifactcompile.Options{Index: index})
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

// staticPart gives the page without the shipped script.
func staticPart(page string) string {
	if i := strings.Index(page, `<script id="loupe-runtime"`); i >= 0 {
		return page[:i]
	}

	return page
}

func assertClean(t *testing.T, page string) {
	t.Helper()
	if bad := forbiddenIn(page); bad != "" {
		t.Errorf("page holds %q:\n%s", bad, page)
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
		`<style>`, `fonts.googleapis.com`, `<h1>Hello</h1>`, `href="index.html#top"`,
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
	page := staticPart(one(t, res, "index.html"))
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
		"an event attribute": {scripted(script, "<p>a</p>\n<button onClick=\"go()\">x</button>"), "event attribute onclick", 4, `<!-- loupe:gap event attribute onclick: `},
		"a dc tag":           {wrap("<p>a</p>\n<dc-import src=\"Pricing.dc.html\"></dc-import>"), "<dc-import> (an embedded artboard)", 4, `<!-- loupe:gap <dc-import>`},
		"sc-else":            {wrap("<p>a</p>\n<sc-else><p>no</p></sc-else>"), "<sc-else>", 4, `<!-- loupe:gap <sc-else>`},
		"a blob path":        {wrap("<p>a</p>\n<img src=\"/_blob/abc.png\">"), "a blob file that is not in the push", 4, `<!-- loupe:gap a blob file that is not in the push`},
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

func TestSeveralArtboardsWithNoIndexAreOneGapAndKeepTheirNames(t *testing.T) {
	res := compile(t,
		canvas("B.dc.html", wrap(`<a href="A.dc.html">a</a>`)),
		canvas("A.dc.html", wrap("<p>a</p>")),
	)
	if len(res.Gaps) != 1 || res.Gaps[0].Feature != "more than one artboard with no --index" || res.Gaps[0].File != "A.dc.html" {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	if !strings.Contains(one(t, res, "A.html"), "loupe:gap more than one artboard with no - -index: run again with - -index") || !strings.Contains(one(t, res, "B.html"), `href="A.html"`) {
		t.Fatal("pages not marked or linked")
	}
}

func TestACanvasInAFolderBecomesTheRootIndex(t *testing.T) {
	res := compile(t, canvas("site/Main.dc.html", wrap("<p>a</p>")))
	one(t, res, "index.html")
}

func TestAnIndexFromAFolderKeepsItsRelativeLinks(t *testing.T) {
	res := compileIndex(t, "site/Home",
		canvas("site/Home.dc.html", "<html><head><link rel=\"stylesheet\" href=\"app.css\"></head><body>\n<x-dc>\n"+
			`<a href="Pricing.dc.html">p</a><a href="../Top.dc.html">t</a><a href="Home.dc.html#x">self</a>`+
			`<a href="#frag">f</a><a href="https://example.com/a">e</a><a href="mailto:a@b.c">m</a>`+
			`<img src="hero.png" srcset="hero.png 1x, big/hero.png 2x"><img src="/_blob/abc">`+
			`<div style="background: url('bg.png')"></div><style>.a { background: url(img/a.png) }</style>`+
			"\n</x-dc>\n</body></html>"),
		canvas("site/Pricing.dc.html", wrap(`<a href="Home.dc.html">h</a>`)),
		canvas("Top.dc.html", wrap(`<a href="site/Home.dc.html">h</a>`)),
		api.ArtifactFile{Path: "site/app.css", Contents: []byte("a{}")},
		blobFile("_blob/abc.png"),
	)
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	index := one(t, res, "index.html")
	for _, want := range []string{
		`href="site/app.css"`, `href="site/Pricing.html"`, `href="Top.html"`, `href="index.html#x"`, `href="#frag"`,
		`href="https://example.com/a"`, `href="mailto:a@b.c"`, `src="site/hero.png"`,
		`srcset="site/hero.png 1x, site/big/hero.png 2x"`, `src="_blob/abc.png"`, `url(&#39;site/bg.png&#39;)`, `url(site/img/a.png)`,
	} {
		if !strings.Contains(index, want) {
			t.Errorf("index.html lacks %s:\n%s", want, index)
		}
	}
	if !strings.Contains(one(t, res, "site/Pricing.html"), `href="../index.html"`) || !strings.Contains(one(t, res, "Top.html"), `href="index.html"`) {
		t.Fatal("links to the index page do not reach index.html")
	}
}

func TestAnIndexFromAFolderKeepsEachSrcsetURL(t *testing.T) {
	cases := map[string]struct{ in, want string }{
		"data URL":             {`data:image/png;base64,AAAA 1x, big.png 2x`, `data:image/png;base64,AAAA 1x, site/big.png 2x`},
		"data URL last":        {`a.png 1x,data:image/png;base64,AAAA 2x`, `site/a.png 1x,data:image/png;base64,AAAA 2x`},
		"no descriptor":        {`a.png, b.png 2x`, `site/a.png, site/b.png 2x`},
		"comma in a file name": {`a,b.png 1x`, `site/a,b.png 1x`},
		"width descriptors":    {` a.png 100w ,b.png 200w`, ` site/a.png 100w ,site/b.png 200w`},
	}
	r := rebaser{from: "site", to: "."}
	for name, c := range cases {
		if got := r.srcset(c.in); got != c.want {
			t.Errorf("%s: srcset(%q) = %q, want %q", name, c.in, got, c.want)
		}
	}

	res := compileIndex(t, "site/Home", canvas("site/Home.dc.html", wrap(`<img srcset="data:image/png;base64,AAAA 1x, big.png 2x">`)))
	if index := one(t, res, "index.html"); !strings.Contains(index, `srcset="data:image/png;base64,AAAA 1x, site/big.png 2x"`) {
		t.Fatalf("index.html lost a srcset URL:\n%s", index)
	}
}

func TestAPageThatWouldReplaceAFileIsAGapAndKeepsBoth(t *testing.T) {
	cases := map[string]struct {
		taken []string
		page  string
	}{
		"index.html":                  {[]string{"index.html"}, "index.compiled.html"},
		"index.compiled.html as well": {[]string{"index.html", "index.compiled.html"}, "index.compiled-2.html"},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			files := []api.ArtifactFile{canvas("Main.dc.html", wrap("<p>a</p>"))}
			for _, p := range c.taken {
				files = append(files, api.ArtifactFile{Path: p, Contents: []byte("old " + p)})
			}
			res := compile(t, files...)
			if len(res.Gaps) != 1 || !strings.Contains(res.Gaps[0].Feature, "index.html") || !strings.Contains(res.Gaps[0].Detail, c.page) {
				t.Fatalf("gaps = %+v", res.Gaps)
			}
			if len(res.Files) != len(c.taken)+1 {
				t.Fatalf("files = %+v", res.Files)
			}
			for _, p := range c.taken {
				if one(t, res, p) != "old "+p {
					t.Fatalf("%s was replaced", p)
				}
			}
			if page := one(t, res, c.page); !strings.HasPrefix(page, "<!-- loupe:gap an existing file named index.html") {
				t.Fatalf("page:\n%s", page)
			}
		})
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

func TestACanvasScriptInTheHeadIsRemoved(t *testing.T) {
	res := compile(t, canvas("A.dc.html", "<html><head>\n<script type=\"text/x-dc\" data-dc-script>\nclass Component extends DCLogic { renderVals() { return { t: 'Hi' }; } }\n</script>\n</head><body>\n<x-dc>\n<h1>{{ t }}</h1>\n<helmet><script data-dc-script>DCLogic</script></helmet>\n</x-dc>\n</body></html>"))
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	page := one(t, res, "index.html")
	assertClean(t, page)
	if !strings.Contains(page, "<h1>Hi</h1>") {
		t.Fatalf("page:\n%s", page)
	}
}

func TestAnUnsupportedFeatureInABranchThatDoesNotRenderIsAGap(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { no: false, none: [] }; } }`
	cases := map[string]struct{ markup, feature string }{
		"a false sc-if":     {"<sc-if value=\"{{ no }}\"><div><button onclick=\"go()\">x</button></div></sc-if>", "event attribute onclick"},
		"an empty sc-for":   {"<sc-for list=\"{{ none }}\" as=\"i\"><dc-import src=\"B.dc.html\"></dc-import></sc-for>", "<dc-import> (an embedded artboard)"},
		"a nested sc-else":  {"<sc-if value=\"{{ no }}\"><sc-else><p>x</p></sc-else></sc-if>", "<sc-else>"},
		"a blob path":       {"<sc-if value=\"{{ no }}\"><img src=\"/_blob/x.png\"></sc-if>", "a blob file that is not in the push"},
		"a blob in a style": {"<sc-for list=\"{{ none }}\" as=\"i\"><style>a { background: url(/_blob/x.png) }</style></sc-for>", "a blob file that is not in the push"},
		"an expression":     {"<sc-if value=\"{{ no }}\"><p>{{ a + b }}</p></sc-if>", "a hole that is not a plain path"},
		"a nested sc-if":    {"<sc-if value=\"{{ no }}\"><sc-if value=\"{{ a || b }}\"><p>x</p></sc-if></sc-if>", "<sc-if> without a plain value"},
		"an inner html":     {"<sc-if value=\"{{ no }}\"><div dangerouslySetInnerHTML=\"{{ a + b }}\"></div></sc-if>", "dangerouslySetInnerHTML without a plain path"},
		"a missing link":    {"<sc-if value=\"{{ no }}\"><a href=\"Other.dc.html\">o</a></sc-if>", "a link to an artboard that is not in the push"},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			res := compile(t, canvas("A.dc.html", scripted(script, "<p>a</p>\n"+c.markup)))
			if len(res.Gaps) != 1 || res.Gaps[0].Feature != c.feature || res.Gaps[0].Line != 4 {
				t.Fatalf("gaps = %+v", res.Gaps)
			}
			if !strings.Contains(one(t, res, "index.html"), "<!-- loupe:gap "+c.feature) {
				t.Fatalf("no marker in\n%s", one(t, res, "index.html"))
			}
		})
	}
}

func TestAValueInABranchThatDoesNotRenderIsChecked(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { no: false, none: [], target: "Other.dc.html", img: "/_blob/x.png", m: { __html: "<button onclick=\"go()\">x</button>" } }; } }`
	cases := map[string]struct{ markup, feature string }{
		"a missing link":   {`<sc-if value="{{ no }}"><a href="{{ target }}">o</a></sc-if>`, "a link to an artboard that is not in the push"},
		"a blob in text":   {`<sc-if value="{{ no }}"><p>{{ img }}</p></sc-if>`, "a /_blob/ path"},
		"a blob attribute": {`<sc-for list="{{ none }}" as="i"><img src="{{ img }}"></sc-for>`, "a /_blob/ path"},
		"inner html":       {`<sc-if value="{{ no }}"><div dangerouslySetInnerHTML="{{ m }}"></div></sc-if>`, "event attribute onclick"},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			res := compile(t, canvas("A.dc.html", scripted(script, "<p>a</p>\n"+c.markup)))
			if len(res.Gaps) != 1 || res.Gaps[0].Feature != c.feature || res.Gaps[0].Line != 4 {
				t.Fatalf("gaps = %+v", res.Gaps)
			}
			if !strings.Contains(one(t, res, "index.html"), "<!-- loupe:gap "+c.feature) {
				t.Fatalf("no marker in\n%s", one(t, res, "index.html"))
			}
		})
	}
}

func TestAnUnknownValueInABranchThatDoesNotRenderIsNoGap(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { none: [], i: "/_blob/x.png" }; } }`
	markup := `<sc-for list="{{ none }}" as="i"><a href="{{ missing }}">{{ i }}</a><sc-for list="{{ none }}" as="j"><p>{{ j }}</p></sc-for></sc-for>`
	res := compile(t, canvas("A.dc.html", scripted(script, markup)))
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
}

func TestABranchThatDoesNotRenderAndHoldsNoGapIsDropped(t *testing.T) {
	res := compile(t, canvas("A.dc.html", scripted(`class Component extends DCLogic { renderVals() { return { no: false }; } }`, `<sc-if value="{{ no }}"><p>hidden</p></sc-if>`)))
	if len(res.Gaps) != 0 || strings.Contains(one(t, res, "index.html"), "hidden") {
		t.Fatalf("gaps = %+v, page:\n%s", res.Gaps, one(t, res, "index.html"))
	}
}

func TestLeftoverCanvasTextIsMarkedBesideAnotherGap(t *testing.T) {
	res := compile(t, canvas("A.dc.html", wrap("<button onclick=\"go()\">x</button>\n<p>see Pricing.dc.html</p>")))
	features := map[string]bool{}
	for _, g := range res.Gaps {
		features[g.Feature] = true
	}
	if !features["event attribute onclick"] || !features["canvas text left in the output"] {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	page := one(t, res, "index.html")
	if strings.Count(page, "<!-- loupe:gap") != len(res.Gaps) {
		t.Fatalf("%d gaps, page:\n%s", len(res.Gaps), page)
	}
}

func TestAnEventAttributeStaysAfterItsMarker(t *testing.T) {
	res := compile(t, canvas("A.dc.html", wrap(`<button class="b" onclick="go()">x</button>`)))
	page := one(t, res, "index.html")
	i, j := strings.Index(page, "<!-- loupe:gap event attribute onclick"), strings.Index(page, `<button class="b" onclick="go()">x</button>`)
	if i < 0 || j < i {
		t.Fatalf("page:\n%s", page)
	}
}

func TestAMarkerKeepsTheSourceOfAnUnsupportedValue(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { a: 1, o: {x: 1} }; } }`
	cases := map[string]struct{ file, source string }{
		"an expression in text":         {scripted(script, "<p>{{ a + 1 }}</p>"), "{{ a + 1 }}"},
		"an object in text":             {scripted(script, "<p>{{ o }}</p>"), "{{ o }}"},
		"an expression in an attribute": {scripted(script, `<p title="{{ a + 1 }}">x</p>`), `title="{{ a + 1 }}"`},
		"an inner html expression":      {scripted(script, `<div dangerouslySetInnerHTML="{{ a + 1 }}"></div>`), `dangerouslysetinnerhtml="{{ a + 1 }}"`},
		"a failing script":              {scripted(`class Component extends DCLogic { renderVals() { throw new Error("boom"); } }`, "<p>a</p>"), `throw new Error("boom")`},
		"data-props that is not JSON":   {"<html><head></head><body>\n<x-dc><p>a</p></x-dc>\n<script type=\"text/x-dc\" data-dc-script data-props=\"{bad\">\n" + script + "\n</script>\n</body></html>", "renderVals"},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			res := compile(t, canvas("A.dc.html", c.file))
			page := one(t, res, "index.html")
			if len(res.Gaps) == 0 || strings.Count(page, "<!-- loupe:gap") != len(res.Gaps) {
				t.Fatalf("gaps = %+v, page:\n%s", res.Gaps, page)
			}
			i := strings.Index(page, "<!-- loupe:gap")
			if i < 0 || !strings.Contains(markerRe.ReplaceAllString(page[i:], ""), c.source) {
				t.Fatalf("no %q after the marker in\n%s", c.source, page)
			}
		})
	}
}

func TestTheOriginalHeadIsCheckedLikeTheCanvas(t *testing.T) {
	cases := map[string]struct{ head, feature, source string }{
		"a blob link":     {`<link rel="icon" href="/_blob/logo.png">`, "a blob file that is not in the push", `href="/_blob/logo.png"`},
		"a blob in style": {`<style>a { background: url(/_blob/x.png) }</style>`, "a blob file that is not in the push", "url(/_blob/x.png)"},
		"an expression":   {`<title>{{ a + 1 }}</title>`, "a hole that is not a plain path", "{{ a + 1 }}"},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			res := compile(t, canvas("A.dc.html", "<html><head>\n"+c.head+"\n</head><body>\n<x-dc><p>a</p></x-dc>\n</body></html>"))
			if len(res.Gaps) != 1 || res.Gaps[0].Feature != c.feature || res.Gaps[0].Line != 2 {
				t.Fatalf("gaps = %+v", res.Gaps)
			}
			page := one(t, res, "index.html")
			i := strings.Index(page, "<!-- loupe:gap "+c.feature)
			if strings.Count(page, "<!-- loupe:gap") != 1 || i < 0 || !strings.Contains(markerRe.ReplaceAllString(page[i:], ""), c.source) {
				t.Fatalf("page:\n%s", page)
			}
		})
	}
}

func TestABlobPathFromAValueIsAGap(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { img: "/_blob/x.png", h: '<img src="/_blob/x.png">' }; } }`
	cases := map[string]struct{ markup, source string }{
		"in text":         {"<p>{{ img }}</p>", "{{ img }}"},
		"in an attribute": {`<img src="{{ img }}">`, `src="{{ img }}"`},
		"in inner html":   {`<div dangerouslySetInnerHTML="{{ h }}"></div>`, `dangerouslysetinnerhtml="{{ h }}"`},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			res := compile(t, canvas("A.dc.html", scripted(script, c.markup)))
			if len(res.Gaps) != 1 || res.Gaps[0].Feature != "a /_blob/ path" {
				t.Fatalf("gaps = %+v", res.Gaps)
			}
			page := one(t, res, "index.html")
			rest := markerRe.ReplaceAllString(page, "")
			if strings.Count(page, "<!-- loupe:gap") != 1 || strings.Contains(rest, "/_blob/") || !strings.Contains(rest, c.source) {
				t.Fatalf("page:\n%s", page)
			}
		})
	}
}

func TestMarkupFromAValueIsCheckedLikeTheCanvas(t *testing.T) {
	cases := map[string]struct{ markup, feature, kept string }{
		"an event attribute":   {`<button onclick=\"go()\">x</button>`, "event attribute onclick", `onclick="go()"`},
		"an embedded artboard": {`<dc-import src=\"P.dc.html\"></dc-import>`, "<dc-import> (an embedded artboard)", `<dc-import`},
		"a nested inner HTML":  {`<div dangerouslySetInnerHTML=\"{{ x }}\"></div>`, "dangerouslySetInnerHTML inside markup from a value", `dangerouslysetinnerhtml`},
		"a missing link":       {`<a href=\"Other.dc.html\">o</a>`, "a link to an artboard that is not in the push", `href="Other.dc.html"`},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			script := `class Component extends DCLogic { renderVals() { return { m: { __html: "` + c.markup + `" } }; } }`
			res := compile(t, canvas("A.dc.html", scripted(script, "<p>a</p>\n<div dangerouslySetInnerHTML=\"{{ m }}\"></div>")))
			if len(res.Gaps) != 1 || res.Gaps[0].Feature != c.feature || res.Gaps[0].Line != 4 {
				t.Fatalf("gaps = %+v", res.Gaps)
			}
			page := one(t, res, "index.html")
			if i, j := strings.Index(page, "loupe:gap"), strings.Index(page, c.kept); i < 0 || j < i {
				t.Fatalf("page:\n%s", page)
			}
		})
	}
}

func TestALinkToAnArtboardThatIsNotPushedIsAGap(t *testing.T) {
	for href, kept := range map[string]string{"Other.dc.html": "site/Other.dc.html", "../A.dc.html#top": "A.dc.html#top"} {
		res := compile(t, canvas("site/A.dc.html", wrap(`<a href="`+href+`">o</a>`)))
		if len(res.Gaps) != 1 || res.Gaps[0].Feature != "a link to an artboard that is not in the push" {
			t.Fatalf("%s: gaps = %+v", href, res.Gaps)
		}
		page := one(t, res, "index.html")
		if i, j := strings.Index(page, "loupe:gap"), strings.Index(page, `href="`+kept+`"`); i < 0 || j < i {
			t.Fatalf("page:\n%s", page)
		}
	}
}

func TestALinkFollowsTheArtboardToItsPage(t *testing.T) {
	res := compile(t,
		canvas("site/A.dc.html", wrap(`<a href="A.dc.html?x=1">self</a>`)),
		canvas("B.dc.html", wrap(`<a href="site/A.dc.html">a</a>`)),
	)
	if !strings.Contains(one(t, res, "site/A.html"), `href="A.html?x=1"`) || !strings.Contains(one(t, res, "B.html"), `href="site/A.html"`) {
		t.Fatalf("files = %+v", res.Files)
	}
}

func TestALinkInMarkupFromAValueFollowsTheArtboard(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { m: { __html: "<a href=\"A.dc.html#x\">a</a>" } }; } }`
	res := compile(t, canvas("A.dc.html", scripted(script, `<div dangerouslySetInnerHTML="{{ m }}"></div>`)))
	if len(res.Gaps) != 0 || !strings.Contains(one(t, res, "index.html"), `href="index.html#x"`) {
		t.Fatalf("gaps = %+v, page:\n%s", res.Gaps, one(t, res, "index.html"))
	}
}

func TestACompanionFileIsCheckedForCanvasText(t *testing.T) {
	res := compile(t,
		canvas("A.dc.html", wrap("<p>a</p>")),
		api.ArtifactFile{Path: "notes.html", Contents: []byte("<p>ok</p>\n<h1>{{ title }}</h1>")},
		api.ArtifactFile{Path: "style.css", Contents: []byte("body { background: url(/_blob/x); }")},
		api.ArtifactFile{Path: "clean.js", Contents: []byte("const a = 1;")},
		api.ArtifactFile{Path: "logo.png", Contents: []byte("\x89PNG\x00{{")},
	)
	if len(res.Gaps) != 2 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	got := map[string]int{}
	for _, g := range res.Gaps {
		got[g.File] = g.Line
	}
	if got["notes.html"] != 2 || got["style.css"] != 1 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
}

func TestACompanionGapIsMarkedInTheCommentSyntaxOfItsFile(t *testing.T) {
	res := compile(t,
		canvas("A.dc.html", wrap("<p>a</p>")),
		api.ArtifactFile{Path: "notes.html", Contents: []byte("<p>ok</p>\n<h1>{{ title }}</h1>")},
		api.ArtifactFile{Path: "style.css", Contents: []byte("a {}\nbody { background: url(/_blob/x); }")},
		api.ArtifactFile{Path: "data.json", Contents: []byte(`{"a": "{{ b }}"}`)},
		api.ArtifactFile{Path: "clean.js", Contents: []byte("const a = 1;")},
	)
	want := map[string]string{
		"notes.html": "<p>ok</p>\n<!-- loupe:gap canvas text left in the output: \"{{\" is left in the file -->\n<h1>{{ title }}</h1>",
		"style.css":  "a {}\n/* loupe:gap canvas text left in the output: \"/_blob/\" is left in the file */\nbody { background: url(/_blob/x); }",
		"data.json":  "loupe:gap canvas text left in the output: \"{{\" is left in the file\n{\"a\": \"{{ b }}\"}",
		"clean.js":   "const a = 1;",
	}
	for name, body := range want {
		if got := one(t, res, name); got != body {
			t.Fatalf("%s:\n%s", name, got)
		}
	}
}

func TestACssMarkerCannotCloseItsComment(t *testing.T) {
	got := companionMarker("a.css", artifactcompile.Gap{Feature: "x */ y", Detail: "z"})
	if got != "/* loupe:gap x * / y: z */" {
		t.Fatalf("got = %q", got)
	}
}

func blobFile(name string) api.ArtifactFile {
	return api.ArtifactFile{Path: name, ContentType: "image/png", Contents: []byte("\x89PNG\x00")}
}

func TestIndexChoosesThePageThatBecomesIndexHTML(t *testing.T) {
	files := func() []api.ArtifactFile {
		return []api.ArtifactFile{
			canvas("site/Home.dc.html", wrap(`<a href="Pricing.dc.html">p</a><a href="../Top.dc.html">t</a>`)),
			canvas("site/Pricing.dc.html", wrap(`<a href="Home.dc.html">h</a>`)),
			canvas("Top.dc.html", wrap(`<a href="site/Home.dc.html">h</a>`)),
		}
	}
	cases := map[string]struct {
		index string
		paths []string
	}{
		"a full path":                 {"site/Home.dc.html", []string{"Top.html", "index.html", "site/Pricing.html"}},
		"a path with no suffix":       {"site/Pricing", []string{"Top.html", "index.html", "site/Home.html"}},
		"a unique base name":          {"Top", []string{"index.html", "site/Home.html", "site/Pricing.html"}},
		"a base name with its suffix": {"Home.dc.html", []string{"Top.html", "index.html", "site/Pricing.html"}},
		"a dotted path":               {"./Top.dc.html", []string{"index.html", "site/Home.html", "site/Pricing.html"}},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			res := compileIndex(t, c.index, files()...)
			if len(res.Gaps) != 0 {
				t.Fatalf("gaps = %+v", res.Gaps)
			}
			var paths []string
			for _, f := range res.Files {
				paths = append(paths, f.Path)
			}
			if strings.Join(paths, ",") != strings.Join(c.paths, ",") {
				t.Fatalf("paths = %v", paths)
			}
		})
	}
}

func TestALinkToTheIndexArtboardPointsAtIndexHTML(t *testing.T) {
	res := compileIndex(t, "Home",
		canvas("Pricing.dc.html", wrap(`<a href="Home.dc.html#top">h</a>`)),
		canvas("Home.dc.html", wrap(`<a href="Pricing.dc.html">p</a>`)),
		canvas("sub/Deep.dc.html", wrap(`<a href="../Home.dc.html">h</a>`)),
	)
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	for page, want := range map[string]string{
		"Pricing.html":  `href="index.html#top"`,
		"index.html":    `href="Pricing.html"`,
		"sub/Deep.html": `href="../index.html"`,
	} {
		if !strings.Contains(one(t, res, page), want) {
			t.Errorf("%s lacks %s:\n%s", page, want, one(t, res, page))
		}
	}
}

func TestAnIndexNeedsAnArtboardItNames(t *testing.T) {
	two := []api.ArtifactFile{canvas("a/Main.dc.html", wrap("x")), canvas("b/Main.dc.html", wrap("x")), canvas("Other.dc.html", wrap("x"))}
	cases := map[string]struct {
		index string
		files []api.ArtifactFile
		want  string
	}{
		"an unknown name":            {"Nope", two, `--index Nope names no artboard. The push holds: Other.dc.html, a/Main.dc.html, b/Main.dc.html`},
		"a name with a wrong path":   {"c/Main", two, `--index c/Main names no artboard`},
		"an ambiguous base name":     {"Main", two, `--index Main names more than one artboard: a/Main.dc.html, b/Main.dc.html`},
		"one canvas and a wrong one": {"Other", []api.ArtifactFile{canvas("Main.dc.html", wrap("x"))}, `--index Other names no artboard. The push holds: Main.dc.html`},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			_, err := compiler{}.Compile(c.files, artifactcompile.Options{Index: c.index})
			if err == nil || !strings.Contains(err.Error(), c.want) {
				t.Fatalf("err = %v", err)
			}
		})
	}
}

func TestOneCanvasWithItsIndexNeedsNoGap(t *testing.T) {
	res := compileIndex(t, "Main.dc.html", canvas("Main.dc.html", wrap("<p>a</p>")))
	if len(res.Gaps) != 0 || len(res.Files) != 1 || res.Files[0].Path != "index.html" {
		t.Fatalf("res = %+v", res)
	}
}

func TestTheIndexKeepsItsNameWhenAnotherArtboardWouldTakeIt(t *testing.T) {
	res := compileIndex(t, "Main", canvas("Main.dc.html", wrap("<p>main</p>")), canvas("index.dc.html", wrap("<p>other</p>")))
	if !strings.Contains(one(t, res, "index.html"), "<p>main</p>") {
		t.Fatalf("files = %+v", res.Files)
	}
	if len(res.Gaps) != 1 || res.Gaps[0].File != "index.dc.html" || !strings.Contains(one(t, res, "index.compiled.html"), "<p>other</p>") {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
}

func TestABlobPathBecomesALinkToTheBlobFile(t *testing.T) {
	cases := map[string]struct{ markup, want string }{
		"in src":               {`<img src="/_blob/abc">`, `<img src="_blob/abc.png"/>`},
		"in src with its ext":  {`<img src="/_blob/abc.png">`, `<img src="_blob/abc.png"/>`},
		"in href":              {`<a href="/_blob/doc.pdf">d</a>`, `<a href="_blob/doc.pdf">d</a>`},
		"in poster":            {`<video poster="/_blob/abc"></video>`, `poster="_blob/abc.png"`},
		"in srcset":            {`<img srcset="/_blob/abc 1x, /_blob/abc 2x">`, `srcset="_blob/abc.png 1x, _blob/abc.png 2x"`},
		"in a style attribute": {`<div style="background: url(/_blob/abc) no-repeat">x</div>`, `style="background: url(_blob/abc.png) no-repeat"`},
		"with a quoted url":    {`<div style="background: url(&#39;/_blob/abc&#39;)">x</div>`, `url(&#39;_blob/abc.png&#39;)`},
		"with a query":         {`<img src="/_blob/abc?v=1">`, `src="_blob/abc.png?v=1"`},
		"in a style block":     {`<style>a { background: url(/_blob/abc) } b { background: url("/_blob/abc") }</style>`, `a { background: url(_blob/abc.png) } b { background: url("_blob/abc.png") }`},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			res := compile(t, canvas("A.dc.html", wrap(c.markup)), blobFile("_blob/abc.png"), blobFile("_blob/doc.pdf"))
			if len(res.Gaps) != 0 {
				t.Fatalf("gaps = %+v", res.Gaps)
			}
			page := one(t, res, "index.html")
			assertClean(t, page)
			if !strings.Contains(page, c.want) {
				t.Fatalf("no %q in\n%s", c.want, page)
			}
			one(t, res, "_blob/abc.png")
		})
	}
}

func TestABlobPathInTheHeadBecomesALink(t *testing.T) {
	res := compile(t,
		canvas("A.dc.html", "<html><head>\n<link rel=\"icon\" href=\"/_blob/abc\">\n<style>a { background: url(/_blob/abc) }</style>\n</head><body>\n<x-dc><p>a</p></x-dc>\n</body></html>"),
		blobFile("_blob/abc.png"),
	)
	page := one(t, res, "index.html")
	if len(res.Gaps) != 0 || !strings.Contains(page, `href="_blob/abc.png"`) || !strings.Contains(page, "url(_blob/abc.png)") {
		t.Fatalf("gaps = %+v, page:\n%s", res.Gaps, page)
	}
}

func TestABlobLinkIsRelativeToTheFolderOfThePage(t *testing.T) {
	res := compileIndex(t, "Home",
		canvas("Home.dc.html", wrap("<p>h</p>")),
		canvas("site/deep/A.dc.html", wrap(`<img src="/_blob/abc"><style>a { background: url(/_blob/abc) }</style>`)),
		blobFile("_blob/abc.png"),
	)
	page := one(t, res, "site/deep/A.html")
	if len(res.Gaps) != 0 || strings.Count(page, "../../_blob/abc.png") != 2 {
		t.Fatalf("gaps = %+v, page:\n%s", res.Gaps, page)
	}
	assertClean(t, page)
}

func TestABlobFileStaysInTheOutput(t *testing.T) {
	res := compile(t, canvas("A.dc.html", wrap(`<img src="/_blob/abc">`)), blobFile("_blob/abc.png"), blobFile("_blob/unused.png"))
	if one(t, res, "_blob/abc.png") == "" || one(t, res, "_blob/unused.png") == "" {
		t.Fatal("blob file left out")
	}
}

func TestABlobPathThatCannotResolveIsAGapAndKeepsItsSource(t *testing.T) {
	cases := map[string]struct {
		markup, feature, source string
		files                   []api.ArtifactFile
	}{
		"a missing file":                  {`<img src="/_blob/gone">`, "a blob file that is not in the push", `src="/_blob/gone"`, []api.ArtifactFile{blobFile("_blob/abc.png")}},
		"no blob folder":                  {`<img src="/_blob/abc">`, "a blob file that is not in the push", `src="/_blob/abc"`, nil},
		"a nested file":                   {`<img src="/_blob/abc">`, "a blob file that is not in the push", `src="/_blob/abc"`, []api.ArtifactFile{blobFile("_blob/sub/abc.png")}},
		"a blob beside the canvas":        {`<img src="/_blob/abc">`, "a blob file that is not in the push", `src="/_blob/abc"`, []api.ArtifactFile{blobFile("abc.png")}},
		"a traversal":                     {`<img src="/_blob/../secret.png">`, "a /_blob/ path that is not a plain id", `src="/_blob/../secret.png"`, []api.ArtifactFile{blobFile("secret.png")}},
		"a hidden traversal":              {`<img src="/_blob/a..b">`, "a /_blob/ path that is not a plain id", `src="/_blob/a..b"`, nil},
		"an absolute path":                {`<img src="/_blob//etc/passwd">`, "a /_blob/ path that is not a plain id", `src="/_blob//etc/passwd"`, nil},
		"a nested id":                     {`<img src="/_blob/sub/abc">`, "a /_blob/ path that is not a plain id", `src="/_blob/sub/abc"`, []api.ArtifactFile{blobFile("_blob/sub/abc.png")}},
		"an empty id":                     {`<img src="/_blob/">`, "a /_blob/ path that is not a plain id", `src="/_blob/"`, nil},
		"two files, one id":               {`<img src="/_blob/abc">`, "a blob id that more than one file has", `src="/_blob/abc"`, []api.ArtifactFile{blobFile("_blob/abc.png"), blobFile("_blob/abc.jpg")}},
		"one good, one missing":           {`<img srcset="/_blob/abc 1x, /_blob/gone 2x">`, "a blob file that is not in the push", `srcset="/_blob/abc 1x, /_blob/gone 2x"`, []api.ArtifactFile{blobFile("_blob/abc.png")}},
		"a missing file in a style block": {`<style>a { background: url(/_blob/gone) }</style>`, "a blob file that is not in the push", `url(/_blob/gone)`, nil},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			res := compile(t, append([]api.ArtifactFile{canvas("A.dc.html", wrap("<p>a</p>\n"+c.markup))}, c.files...)...)
			if len(res.Gaps) != 1 || res.Gaps[0].Feature != c.feature || res.Gaps[0].File != "A.dc.html" || res.Gaps[0].Line != 4 {
				t.Fatalf("gaps = %+v", res.Gaps)
			}
			page := one(t, res, "index.html")
			i, j := strings.Index(page, "<!-- loupe:gap "+c.feature), strings.Index(page, c.source)
			if strings.Count(page, "<!-- loupe:gap") != 1 || i < 0 || j < i {
				t.Fatalf("page:\n%s", page)
			}
		})
	}
}

func TestABlobPathInTextIsStillAGap(t *testing.T) {
	res := compile(t, canvas("A.dc.html", wrap("<p>see /_blob/abc</p>")), blobFile("_blob/abc.png"))
	if len(res.Gaps) != 1 || res.Gaps[0].Feature != "a /_blob/ path" {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
}

func TestABlobPathInAScriptTagIsStillAGap(t *testing.T) {
	res := compile(t, canvas("A.dc.html", "<html><head>\n<script>var a = '/_blob/abc';</script>\n</head><body>\n<x-dc><p>a</p></x-dc>\n</body></html>"), blobFile("_blob/abc.png"))
	if len(res.Gaps) != 1 || res.Gaps[0].Feature != "a /_blob/ path" {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
}

func TestABlobPathFromAValueStaysAGapEvenWhenTheFileIsPushed(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { img: "/_blob/abc" }; } }`
	res := compile(t, canvas("A.dc.html", scripted(script, `<img src="{{ img }}">`)), blobFile("_blob/abc.png"))
	if len(res.Gaps) != 1 || res.Gaps[0].Feature != "a /_blob/ path" {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
}

func TestABlobPathInABranchThatDoesNotRenderIsChecked(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { no: false }; } }`
	markup := `<sc-if value="{{ no }}"><img src="/_blob/abc"></sc-if>`
	res := compile(t, canvas("A.dc.html", scripted(script, markup)), blobFile("_blob/abc.png"))
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
}

func TestAnArtboardInAFolderUsesTheBlobsOfThePushRoot(t *testing.T) {
	res := compileIndex(t, "site/Home",
		canvas("site/Home.dc.html", wrap(`<img src="/_blob/abc">`)),
		canvas("site/About.dc.html", wrap(`<img src="/_blob/abc">`)),
		blobFile("_blob/abc.png"),
		blobFile("site/_blob/abc.png"),
	)
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	for page, want := range map[string]string{"index.html": `src="_blob/abc.png"`, "site/About.html": `src="../_blob/abc.png"`} {
		if !strings.Contains(one(t, res, page), want) {
			t.Fatalf("%s:\n%s", page, one(t, res, page))
		}
	}
}

func TestOnlyARawBlobPathIsForbidden(t *testing.T) {
	cases := map[string]bool{
		`<img src="/_blob/x">`:      true,
		`url(/_blob/x)`:             true,
		`"/_blob/x"`:                true,
		`//_blob/x`:                 true,
		`/_blob/x`:                  true,
		`../_blob/x.png`:            false,
		`_blob/x.png`:               false,
		`<img src="../../_blob/x">`: false,
		`url(../_blob/x.png)`:       false,
		`site/_blob/x.png`:          false,
	}
	for in, bad := range cases {
		if got := forbiddenIn(in) == "/_blob/"; got != bad {
			t.Errorf("%q: forbidden = %v", in, got)
		}
	}
}

func TestACompanionFileWithARelativeBlobLinkIsNoGap(t *testing.T) {
	res := compile(t,
		canvas("A.dc.html", wrap("<p>a</p>")),
		api.ArtifactFile{Path: "style.css", Contents: []byte("a { background: url(_blob/x.png) }\nb { background: url(../_blob/x.png) }")},
	)
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
}
