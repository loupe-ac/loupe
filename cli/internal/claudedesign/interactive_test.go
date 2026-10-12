package claudedesign

import (
	"bytes"
	"encoding/json"
	"os"
	"regexp"
	"strings"
	"testing"

	"github.com/dop251/goja"
	"golang.org/x/net/html"

	"github.com/ubermuda/loupe/cli/internal/api"
	"github.com/ubermuda/loupe/cli/internal/artifactcompile"
)

var inlineHandlerRe = regexp.MustCompile(`(?i)\son[a-z]+=`)

// u003c is the escape of < in the shipped strings.
const u003c = "\\" + "u003c"

const clickScript = `class Component extends DCLogic { renderVals() { return { go: function () {}, a: 'x', list: [{ n: 'one', tags: ['t'] }, { n: 'two', tags: [] }] }; } }`

func interactivePage(t *testing.T, script, markup string) string {
	t.Helper()
	res := compile(t, canvas("A.dc.html", scripted(script, markup)))
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	page := one(t, res, "index.html")
	if !strings.Contains(page, `<script id="loupe-runtime">`) {
		t.Fatalf("not interactive:\n%s", page)
	}

	return page
}

func TestAnInteractivePageShipsItsScriptAndNoInlineHandler(t *testing.T) {
	page := interactivePage(t, clickScript, `<button class="b" onClick="{{ go }}">x</button><p>{{ a }}</p>`)
	assertClean(t, page)
	static := staticPart(page)
	if strings.Contains(strings.ToLower(static), "onclick") || !strings.Contains(static, `<button class="b">x</button><p>x</p>`) {
		t.Errorf("static part:\n%s", static)
	}
	for _, want := range []string{
		"LoupeView.mount(Component, render, {}, [\"click\"], {\"A.dc\\u002ehtml\":\"index.html\"}, null, {});",
		"class Component extends LoupeView",
		"class LoupeView",
		u003c + `button class=\"b\"`,
		` data-on-click=\"`,
		`h(R.g(s0,"go"))`,
	} {
		if !strings.Contains(page, want) {
			t.Errorf("missing %q", want)
		}
	}
	if n := strings.Count(page, "</script"); n != 1 || !strings.HasSuffix(strings.TrimSpace(page), "</script></body></html>") {
		t.Errorf("%d script ends", n)
	}
}

func TestATimerOrAMountHookMakesAPageInteractive(t *testing.T) {
	for _, hook := range []string{"componentDidMount() {}", "x() { setInterval(() => {}, 5); }", "y() { setTimeout(() => {}, 5); }"} {
		page := interactivePage(t, `class Component extends DCLogic { `+hook+` renderVals() { return { a: 'x' }; } }`, `<p>{{ a }}</p>`)
		if !strings.Contains(staticPart(page), "<p>x</p>") {
			t.Errorf("%s: static part:\n%s", hook, page)
		}
	}
}

func TestAScriptWithNoEventsAndNoTimersStaysStatic(t *testing.T) {
	res := compile(t, canvas("A.dc.html", scripted(`class Component extends DCLogic { renderVals() { return { a: 'x' }; } }`, `<p>{{ a }}</p>`)))
	page := one(t, res, "index.html")
	if len(res.Gaps) != 0 || strings.Contains(page, "<script") || strings.Contains(page, "LoupeView") {
		t.Fatalf("gaps = %+v, page:\n%s", res.Gaps, page)
	}
}

func TestAnEventThatIsNotAPlainHoleStaysAGap(t *testing.T) {
	for _, attr := range []string{`onclick="go()"`, `onclick="x{{ go }}"`, `onclick=""`, `onclick="{{ go + 1 }}"`} {
		res := compile(t, canvas("A.dc.html", scripted(clickScript, `<p>a</p><button `+attr+`>x</button><button onclick="{{ go }}">y</button>`)))
		page := one(t, res, "index.html")
		if len(res.Gaps) == 0 || res.Gaps[0].Feature != "event attribute onclick" || strings.Contains(page, "loupe-runtime") {
			t.Errorf("%s: gaps = %+v, page:\n%s", attr, res.Gaps, page)
		}
		if !strings.Contains(page, "LoupeView") && strings.Contains(page, "<script") && !strings.Contains(page, "DCLogic") {
			t.Errorf("%s: no source script:\n%s", attr, page)
		}
	}
}

func TestAnEventInMarkupFromAValueStaysAGap(t *testing.T) {
	script := `class Component extends DCLogic { componentDidMount() { setInterval(() => {}, 5); } renderVals() { return { m: { __html: "<button onclick=\"go()\">x</button>" } }; } }`
	res := compile(t, canvas("A.dc.html", scripted(script, "<p>a</p>\n<div dangerouslySetInnerHTML=\"{{ m }}\"></div>")))
	page := one(t, res, "index.html")
	if len(res.Gaps) != 1 || res.Gaps[0].Feature != "event attribute onclick" || res.Gaps[0].Line != 4 || strings.Contains(page, "loupe-runtime") {
		t.Fatalf("gaps = %+v, page:\n%s", res.Gaps, page)
	}
}

func TestAnInteractivePageWithAGapFallsBackToTheStaticPage(t *testing.T) {
	res := compile(t, canvas("A.dc.html", scripted(clickScript, "<button onClick=\"{{ go }}\">x</button>\n<sc-else><p>no</p></sc-else>")))
	page := one(t, res, "index.html")
	features := map[string]bool{}
	for _, g := range res.Gaps {
		features[g.Feature] = true
	}
	if !features["<sc-else>"] || !features["event attribute onclick"] || len(res.Gaps) != 2 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	if strings.Contains(page, "loupe-runtime") || strings.Contains(page, "LoupeView") || strings.Count(page, "<!-- loupe:gap") != 2 || !strings.Contains(page, `onclick="{{ go }}"`) {
		t.Fatalf("page:\n%s", page)
	}
}

func TestCanvasTextInTheScriptFallsBackToTheStaticPage(t *testing.T) {
	for _, bad := range []string{"Other.dc.html", "{{", "/_blob/x", "support.js", "<sc-x", "DCLogic2 DCLogic_"} {
		script := `class Component extends DCLogic { renderVals() { return { go: 1 }; } } // ` + bad
		res := compile(t, canvas("A.dc.html", scripted(script, `<button onClick="{{ go }}">x</button>`)))
		page := one(t, res, "index.html")
		if strings.Contains(page, "loupe-runtime") || len(res.Gaps) != 1 || res.Gaps[0].Feature != "event attribute onclick" {
			t.Errorf("%s: gaps = %+v, page:\n%s", bad, res.Gaps, page)
		}
	}
}

func TestAScriptThatClosesItsElementIsShippedSafely(t *testing.T) {
	src := "<html><head></head><body>\n<x-dc>\n<button onClick=\"{{ go }}\" title=\"{{ v }}\">{{ v }}</button>\n</x-dc>\n" +
		"<script type=\"text/x-dc\" data-dc-script data-props=\"{&quot;v&quot;:{&quot;default&quot;:&quot;&lt;/script&gt;&lt;!-- &amp;&quot;}}\">\n" +
		"class Component extends DCLogic { renderVals() { return { go: 1, v: this.props.v }; } }\nconst a = \"<!--\";\n</script>\n</body></html>"
	res := compile(t, canvas("A.dc.html", src))
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	page := one(t, res, "index.html")
	if n := strings.Count(strings.ToLower(page), "</script"); n != 1 {
		t.Fatalf("%d script ends in\n%s", n, page)
	}
	if strings.Contains(page, "<!--") {
		t.Fatalf("a comment start in\n%s", page)
	}
	if !strings.Contains(page, "\"<\\!--\"") || !strings.Contains(page, `{"v":"`+u003c+`/script`) {
		t.Fatalf("script text changed wrongly:\n%s", page)
	}
}

func TestTheLinksOfAPageNameEachCanvas(t *testing.T) {
	res := compile(t,
		canvas("site/A.dc.html", scripted(clickScript, `<button onClick="{{ go }}">x</button><a href="../B.dc.html">b</a>`)),
		canvas("B.dc.html", wrap(`<p>b</p>`)),
	)
	page := one(t, res, "site/A.html")
	for _, want := range []string{`href="../B.html"`, `{"..\/B.dc.html":"..\/B.html","A.dc.html":"A.html"}`, `{"../B.dc.html":"../B.html","A.dc.html":"A.html"}`} {
		if strings.Contains(page, want) {
			return
		}
	}
	t.Fatalf("page:\n%s", page)
}

func TestTheInteractiveGolden(t *testing.T) {
	res := compile(t, canvas("Interactive.dc.html", string(fixture(t, "interactive.dc.html"))))
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	got := one(t, res, "index.html")
	assertClean(t, got)
	const golden = "testdata/interactive.compiled.html"
	if os.Getenv("UPDATE_GOLDEN") == "1" {
		if err := os.WriteFile(golden, []byte(got), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	if want := fixture(t, "interactive.compiled.html"); string(want) != got {
		t.Fatalf("the golden differs; run with UPDATE_GOLDEN=1 to write it")
	}
	static := staticPart(got)
	for _, want := range []string{`<h1>Fruit &lt;list&gt; &amp; more</h1>`, `<input id="q" type="text" value=""/>`, `<button id="inc">Count: 0</button>`, `href="index.html#top"`, `<i>red</i>`, `<em>3 shown</em>`} {
		if !strings.Contains(static, want) {
			t.Errorf("missing %q in\n%s", want, static)
		}
	}
	if inlineHandlerRe.MatchString(static) {
		t.Errorf("an inline handler in\n%s", static)
	}
	if !strings.Contains(got, `["change","click","input"]`) {
		t.Errorf("events missing")
	}
}

// Equivalence: the generated render function must draw what the build draws.

func fragmentOf(t *testing.T, s string) string {
	t.Helper()
	doc, err := html.Parse(strings.NewReader("<html><body>" + s + "</body></html>"))
	if err != nil {
		t.Fatal(err)
	}
	body := find(doc, isTag("body"))
	var strip func(*html.Node)
	strip = func(n *html.Node) {
		if n.Type == html.ElementNode {
			kept := n.Attr[:0]
			for _, a := range n.Attr {
				if !strings.HasPrefix(a.Key, "data-on-") {
					kept = append(kept, a)
				}
			}
			n.Attr = kept
		}
		for c := n.FirstChild; c != nil; c = c.NextSibling {
			strip(c)
		}
	}
	strip(body)
	var buf bytes.Buffer
	for c := body.FirstChild; c != nil; c = c.NextSibling {
		if err := html.Render(&buf, c); err != nil {
			t.Fatal(err)
		}
	}

	return buf.String()
}

func equivalent(t *testing.T, markup string, scopes ...string) {
	t.Helper()
	p := &page{file: "A.dc.html", links: map[string]string{"A.dc.html": "index.html", "Interactive.dc.html": "index.html"}, blobs: collectBlobs([]api.ArtifactFile{blobFile("_blob/abc.png"), blobFile("_blob/def.webp")}), kept: map[*html.Node]bool{}, keptSelf: map[*html.Node]bool{}}
	doc, err := html.Parse(bytes.NewReader(injectLines([]byte(wrap(markup)))))
	if err != nil {
		t.Fatal(err)
	}
	root := find(doc, isTag("x-dc"))
	render, _, blobs, err := p.genRender(root)
	if err != nil {
		t.Fatal(err)
	}
	blobJSON, _ := jsonJS(blobs)

	vm := goja.New()
	if _, err := vm.RunString("var LoupeView = (function () {\n" + runtimeJS + "\nreturn LoupeView; })();\n" + render +
		"\nvar R = LoupeView.helpers({'A.dc.html': 'index.html', 'Interactive.dc.html': 'index.html'}, null, " + blobJSON + ");\nfunction run(scope) { var n = 0; return render(scope, function () { return String(n++); }, R); }"); err != nil {
		t.Fatalf("%v\n%s", err, render)
	}
	run, _ := goja.AssertFunction(vm.Get("run"))
	for _, js := range scopes {
		var scope map[string]any
		if err := json.Unmarshal([]byte(js), &scope); err != nil {
			t.Fatal(err)
		}
		p.interactive = true
		body := &html.Node{Type: html.ElementNode, Data: "body"}
		for c := root.FirstChild; c != nil; c = c.NextSibling {
			if c.Type == html.ElementNode && c.Data == "helmet" {
				continue
			}
			for _, n := range p.expandNode(c, scope) {
				body.AppendChild(n)
			}
		}
		stripLines(body)
		if len(p.gaps) != 0 {
			t.Fatalf("gaps = %+v", p.gaps)
		}
		var want bytes.Buffer
		for c := body.FirstChild; c != nil; c = c.NextSibling {
			if err := html.Render(&want, c); err != nil {
				t.Fatal(err)
			}
		}
		val, err := vm.RunString("(" + js + ")")
		if err != nil {
			t.Fatal(err)
		}
		got, err := run(goja.Undefined(), val)
		if err != nil {
			t.Fatalf("%v\n%s", err, render)
		}
		if a, b := fragmentOf(t, want.String()), fragmentOf(t, got.String()); a != b {
			t.Errorf("scope %s\nbuild:  %s\nrender: %s", js, a, b)
		}
	}
}

func TestTheRenderFunctionDrawsWhatTheBuildDraws(t *testing.T) {
	cases := map[string]struct {
		markup string
		scopes []string
	}{
		"text and attributes": {
			`<h1 class="a {{ c }}" title="{{ t }}">{{ t }} and {{ n }}</h1><p hint-placeholder-x="y" id=z>{{ missing.key }}|{{ nul }}|{{ b }}</p>`,
			[]string{`{"c":"k","t":"A <b> & \"q\" 'x'","n":3,"nul":null,"b":true}`, `{}`, `{"t":"</script>&amp;","n":0}`},
		},
		"void elements and boolean attributes": {
			`<img src="{{ s }}" alt=""><br><input disabled value="{{ v }}"><hr/><p>after</p>`,
			[]string{`{"s":"a.png","v":"x\"y"}`},
		},
		"loops and nested scopes": {
			`<sc-for list="{{ rows }}" as="row"><div>{{ row.name }}<sc-for list="{{ row.tags }}" as="tag"><i>{{ row.name }}-{{ tag }}</i><sc-for list="{{ row.tags }}" as="row"><b>{{ row }}</b></sc-for></sc-for>{{ row.name }}</div></sc-for>`,
			[]string{`{"rows":[{"name":"a","tags":["x","y"]},{"name":"b","tags":[]}]}`, `{"rows":[]}`, `{"rows":"no"}`, `{}`},
		},
		"ifs": {
			`<sc-if value="{{ a }}"><p>a</p><sc-if value="{{ o.b }}"><p>b</p></sc-if></sc-if><sc-if value="{{ z }}">z</sc-if><sc-if value="{{ e }}">e</sc-if><sc-if value="{{ s }}">s</sc-if>`,
			[]string{`{"a":true,"o":{"b":1},"z":0,"e":[],"s":""}`, `{"a":false}`, `{"a":"x","o":{"b":0},"z":1,"e":{},"s":"y"}`},
		},
		"a loop with an if inside": {
			`<ul><sc-for list="{{ xs }}" as="x"><sc-if value="{{ x.on }}"><li class="{{ x.cls }}">{{ x.n }}</li></sc-if></sc-for></ul>`,
			[]string{`{"xs":[{"on":true,"cls":"c","n":1},{"on":false,"n":2},{"on":1,"cls":"d","n":3}]}`},
		},
		"a link with a hole": {
			`<a href="{{ h }}">x</a><a href="{{ h }}#frag">y</a><a href="A.dc.html#top">self</a><a href="https://e.org/?q={{ h }}">e</a>`,
			[]string{`{"h":"A.dc.html"}`, `{"h":"other.html"}`, `{"h":"A.dc.html?x=1#y"}`, `{}`},
		},
		"a blob path with a hole": {
			`<img src="/_blob/{{ id }}" alt="/_blob/abc {{ q }}"><img src="/_blob/abc?size={{ q }}"><a href="/_blob/{{ id }}#x">b</a>`,
			[]string{`{"id":"abc","q":"v"}`, `{"id":"def","q":"w"}`, `{"id":"def.webp","q":""}`},
		},
		"a blob path with a hole in a hidden branch": {
			`<sc-if value="{{ show }}"><img src="/_blob/{{ id }}"></sc-if><sc-for list="{{ xs }}" as="x"><img src="/_blob/{{ x }}"></sc-for>`,
			[]string{`{"show":false,"id":"abc","xs":[]}`, `{"show":true,"id":"abc","xs":["def"]}`, `{}`},
		},
		"inner html": {
			`<div class="m" dangerouslySetInnerHTML="{{ m }}"><b>dropped</b></div><p dangerouslySetInnerHTML="{{ s }}"></p><p dangerouslySetInnerHTML="{{ none }}"></p>`,
			[]string{`{"m":{"__html":"<em>raw</em> & <i>x</i>"},"s":"<u>t</u>"}`, `{}`},
		},
		"style, script and comments": {
			`<style>a > b { content: "<x>"; }</style><!-- note --><p>{{ a }}</p><script>var x = 1 < 2;</script><noscript>{{ a }}</noscript>`,
			[]string{`{"a":"<&>"}`},
		},
		"pre and textarea": {
			"<pre>\nline {{ a }}</pre><textarea>\nx</textarea>",
			[]string{`{"a":"b"}`},
		},
		"tables and svg": {
			`<table><tr><td>{{ a }}</td></tr></table><svg viewBox="0 0 1 1"><path d="M0 0" fill="{{ a }}"/><a xlink:href="u"></a></svg>`,
			[]string{`{"a":"red"}`},
		},
		"events": {
			`<button onClick="{{ f }}" class="b">x</button><sc-for list="{{ xs }}" as="x"><input onInput="{{ x.f }}" onchange="{{ f }}"></sc-for>`,
			[]string{`{"xs":[{},{}]}`},
		},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) { equivalent(t, c.markup, c.scopes...) })
	}
}

func TestTheGoldenRenderFunctionDrawsWhatTheBuildDraws(t *testing.T) {
	src := string(fixture(t, "interactive.dc.html"))
	start, end := strings.Index(src, "<x-dc>")+len("<x-dc>"), strings.Index(src, "</x-dc>")
	equivalent(t, src[start:end],
		`{"title":"T <x> & \"q\"","query":"ap","count":3,"agreed":true,"notAgreed":false,"status":"Thanks","items":[{"name":"Apple","tags":["red","sweet"],"cls":"todo"},{"name":"Fig","tags":[],"cls":"done"}],"empty":false,"tick":4,"next":"#top","note":{"__html":"<em>2 shown</em>"}}`,
		`{"title":"","query":"zz","count":0,"agreed":false,"notAgreed":true,"status":"Please agree","items":[],"empty":true,"tick":0,"next":"A.dc.html#x","note":"<b>none</b>"}`,
		`{}`,
	)
}

func TestTheCompilerLeavesNoCanvasTextInTheGolden(t *testing.T) {
	res := compile(t, api.ArtifactFile{Path: "support.js", Contents: []byte("x")}, canvas("Interactive.dc.html", string(fixture(t, "interactive.dc.html"))))
	assertClean(t, one(t, res, "index.html"))
}

func TestTheRedrawOfAPageFromAFolderKeepsItsRebasedLinks(t *testing.T) {
	markup := `<button onClick="{{ go }}">x</button><a href="Pricing.dc.html">p</a><img src="hero.png" srcset="hero.png 1x">` +
		`<img src="/_blob/abc"><div style="background: url('bg.png')"></div><style>.a { background: url(img/a.png) }</style>`
	res := compileIndex(t, "site/Home",
		canvas("site/Home.dc.html", scripted(clickScript, markup)),
		canvas("site/Pricing.dc.html", wrap(`<p>p</p>`)),
		blobFile("_blob/abc.png"),
	)
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	page := one(t, res, "index.html")
	i := strings.Index(page, `<script id="loupe-runtime"`)
	if i < 0 {
		t.Fatalf("no runtime:\n%s", page)
	}
	shipped := page[i:]
	for _, want := range []string{
		`href=\"site/Pricing.html\"`, `src=\"site/hero.png\"`, `srcset=\"site/hero.png 1x\"`, `src=\"_blob/abc.png\"`,
		`url(\u0026#39;site/bg.png\u0026#39;)`, `url(site/img/a.png)`,
	} {
		if !strings.Contains(shipped, want) {
			t.Errorf("the shipped render lacks %s:\n%s", want, shipped)
		}
	}
}

func TestALinkFromAValueOnAPageFromAFolderIsRebasedAtEachRedraw(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { go: 1, p: 'hero.png', c: 'Pricing', bg: 'bg.png' }; } }`
	markup := `<button onClick="{{ go }}">x</button><img src="{{ p }}" srcset="{{ p }} 1x" alt="{{ p }}"><a href="{{ c }}.dc.html#top">p</a>` +
		`<div style="background: url('{{ bg }}')"></div>`
	res := compileIndex(t, "site/Home",
		canvas("site/Home.dc.html", scripted(script, markup)),
		canvas("site/Pricing.dc.html", wrap(`<p>p</p>`)),
	)
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	page := one(t, res, "index.html")
	i := strings.Index(page, `<script id="loupe-runtime"`)
	if i < 0 {
		t.Fatalf("no runtime:\n%s", page)
	}
	shipped := page[i:]
	for _, want := range []string{
		`R.q(R.rb("src", "" + t(R.g(s0,"p"))))`,
		`R.q(R.rb("srcset", "" + t(R.g(s0,"p")) + " 1x"))`,
		`R.q(R.rb("href", R.lk("" + t(R.g(s0,"c")) + ".dc\u002ehtml#top")))`,
		`R.q(R.rb("style", `,
		`R.q(t(R.g(s0,"p")))`,
		`{"from":"site","to":"."}, {});`,
	} {
		if !strings.Contains(shipped, want) {
			t.Errorf("the shipped render lacks %s:\n%s", want, shipped)
		}
	}
}

func TestARedrawPointsTheBlobPathsOfAnAttributeWithAHoleAtThePushedFile(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { go: 1, size: 2, q: 'v' }; } }`
	markup := `<button onClick="{{ go }}">x</button><img src="/_blob/abc?size={{ size }}" alt="/_blob/abc {{ q }}"><a href="/_blob/abc?q={{ q }}">d</a>`
	blob := blobFile("_blob/abc.png")
	for name, c := range map[string]struct {
		res  artifactcompile.Result
		want []string
	}{
		"a page in its own folder": {
			compile(t, canvas("A.dc.html", scripted(script, markup)), blob),
			[]string{`R.q(R.bl("" + "\/_blob\/abc?size=" + t(`, `R.lk(R.bl("" + "\/_blob\/abc?q=" + t(`, `{"abc":"_blob/abc.png","abc.png":"_blob/abc.png"});`},
		},
		"a page from a folder": {
			compileIndex(t, "site/Home", canvas("site/Home.dc.html", scripted(script, markup)), blob),
			[]string{`R.rb("src", R.bl("" + "\/_blob\/abc?size=" + t(`, `R.rb("href", R.lk(R.bl("" + "\/_blob\/abc?q=" + t(`, `{"abc":"../_blob/abc.png","abc.png":"../_blob/abc.png"});`},
		},
	} {
		t.Run(name, func(t *testing.T) {
			if len(c.res.Gaps) != 0 {
				t.Fatalf("gaps = %+v", c.res.Gaps)
			}
			page := one(t, c.res, "index.html")
			i := strings.Index(page, `<script id="loupe-runtime"`)
			if i < 0 {
				t.Fatalf("no runtime:\n%s", page)
			}
			shipped := page[i:]
			if blobRe.MatchString(shipped) {
				t.Errorf("the shipped render keeps a raw blob path:\n%s", shipped)
			}
			for _, want := range c.want {
				if !strings.Contains(shipped, want) {
					t.Errorf("the shipped render lacks %s:\n%s", want, shipped)
				}
			}
		})
	}
}

func TestABlobIdFromAValueKeepsThePageInteractive(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { go: 1, id: 'abc' }; } }`
	res := compileIndex(t, "site/Home", canvas("site/Home.dc.html", scripted(script, `<button onClick="{{ go }}">x</button><img src="/_blob/{{ id }}">`)), blobFile("_blob/abc.png"))
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	page := one(t, res, "index.html")
	for _, want := range []string{`<img src="_blob/abc.png"/>`, `R.rb("src", R.bl("" + "\/_blob\/" + t(R.g(s0,"id"))))`, `{"abc":"../_blob/abc.png","abc.png":"../_blob/abc.png"});`} {
		if !strings.Contains(page, want) {
			t.Errorf("the page lacks %s:\n%s", want, page)
		}
	}
}

func TestABlobIdInAHiddenBranchKeepsThePageInteractive(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { go: 1, show: false, id: 'abc' }; } }`
	res := compile(t, canvas("A.dc.html", scripted(script, `<button onClick="{{ go }}">x</button><sc-if value="{{ show }}"><img src="/_blob/{{ id }}"></sc-if>`)), blobFile("_blob/abc.png"))
	if len(res.Gaps) != 0 {
		t.Fatalf("gaps = %+v", res.Gaps)
	}
	if page := one(t, res, "index.html"); !strings.Contains(page, `R.bl("" + "\/_blob\/" + t(R.g(s0,"id")))`) {
		t.Errorf("the redraw cannot reveal the image:\n%s", page)
	}
}

func TestABlobIdInAHiddenBranchIsCheckedAfterItsHoleIsFilled(t *testing.T) {
	for markup, want := range map[string]int{
		`<sc-if value="{{ no }}"><img src="/_blob/{{ id }}"></sc-if>`:           1,
		`<sc-if value="{{ no }}"><img src="/_blob/{{ unknown }}"></sc-if>`:      0,
		`<sc-if value="{{ no }}"><img alt="/_blob/gone {{ unknown }}"></sc-if>`: 1,
	} {
		script := `class Component extends DCLogic { renderVals() { return { no: false, id: 'gone' }; } }`
		res := compile(t, canvas("A.dc.html", scripted(script, markup)), blobFile("_blob/abc.png"))
		if len(res.Gaps) != want || (want == 1 && res.Gaps[0].Feature != "a blob file that is not in the push") {
			t.Errorf("%s: gaps = %+v", markup, res.Gaps)
		}
	}
}

func TestAPageInItsOwnFolderShipsNoRebase(t *testing.T) {
	script := `class Component extends DCLogic { renderVals() { return { go: 1, p: 'hero.png' }; } }`
	res := compile(t, canvas("A.dc.html", scripted(script, `<button onClick="{{ go }}">x</button><img src="{{ p }}">`)))
	page := one(t, res, "index.html")
	if strings.Contains(page, "R.rb(") || !strings.Contains(page, ", null, {});\n})();") {
		t.Fatalf("page:\n%s", page)
	}
}

func TestTheRedrawRebasesALinkAsTheBuildDoes(t *testing.T) {
	vm := goja.New()
	if _, err := vm.RunString(runtimeJS); err != nil {
		t.Fatal(err)
	}
	inputs := map[string][]string{
		"href":   {"..", "a.png", "img/a.png?x=1#y", "../a.png", "../../a.png", "#top", "/abs.png", "https://x.test/a.png", "mailto:a@b.test", "", "./a.png", "Pricing.html"},
		"src":    {"hero.png", "deep/../hero.png"},
		"srcset": {"a.png 1x, b.png 2x", "data:image/png;base64,AAAA 1x, big.png 2x", " a.png 100w ,b.png 200w", "a,b.png 1x"},
		"style":  {"background: url('bg.png')", `background: url("x/y.png"), url(/z.png)`, "color: red"},
	}
	for _, base := range []rebaser{{"site", "."}, {"site/deep", "site"}, {".", "out"}, {"a/b", "c/d"}} {
		value, err := vm.RunString(`LoupeView.helpers({}, ` + `{from: "` + base.from + `", to: "` + base.to + `"}).rb`)
		if err != nil {
			t.Fatal(err)
		}
		rb, _ := goja.AssertFunction(value)
		for key, vals := range inputs {
			for _, v := range vals {
				got, err := rb(goja.Undefined(), vm.ToValue(key), vm.ToValue(v))
				if err != nil {
					t.Fatal(err)
				}
				if want := base.attr(key, v); got.String() != want {
					t.Errorf("%+v %s=%q: redraw gives %q, build gives %q", base, key, v, got.String(), want)
				}
			}
		}
	}
}
