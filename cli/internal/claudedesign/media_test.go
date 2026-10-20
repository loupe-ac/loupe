package claudedesign

import "testing"

func TestResolveWidthMedia(t *testing.T) {
	cases := []struct {
		name  string
		width int
		css   string
		want  string
	}{
		{"min-width true", 1280, "a{b:c}@media (min-width:64rem){.x{y:z}}", "a{b:c}@media all{.x{y:z}}"},
		{"min-width false", 390, "a{b:c}@media (min-width:64rem){.x{y:z}}d{e:f}", "a{b:c}d{e:f}"},
		{"min-width edge", 1024, "@media (min-width:64rem){.x{y:z}}", "@media all{.x{y:z}}"},
		{"max-width true", 390, "@media (max-width:48rem){.x{y:z}}", "@media all{.x{y:z}}"},
		{"max-width false", 1280, "@media (max-width:48rem){.x{y:z}}", ""},
		{"px unit", 500, "@media (min-width: 480px){.x{y:z}}", "@media all{.x{y:z}}"},
		{"em unit", 500, "@media (min-width:40em){.x{y:z}}", ""},
		{"range ge", 1280, "@media (width >= 64rem){.x{y:z}}", "@media all{.x{y:z}}"},
		{"range le false", 1280, "@media (width <= 48rem){.x{y:z}}", ""},
		{"range value first", 1280, "@media (40rem <= width){.x{y:z}}", "@media all{.x{y:z}}"},
		{"range between", 800, "@media (40rem <= width <= 64rem){.x{y:z}}", "@media all{.x{y:z}}"},
		{"range between false", 1280, "@media (40rem <= width < 64rem){.x{y:z}}", ""},
		{"and both true", 800, "@media (min-width:40rem) and (max-width:64rem){.x{y:z}}", "@media all{.x{y:z}}"},
		{"and one false", 1280, "@media (min-width:40rem) and (max-width:64rem){.x{y:z}}", ""},
		{"screen type", 1280, "@media screen and (min-width:40rem){.x{y:z}}", "@media all{.x{y:z}}"},
		{"not query", 390, "@media not all and (min-width:48rem){.x{y:z}}", "@media all{.x{y:z}}"},
		{"not query false", 1280, "@media not all and (min-width:48rem){.x{y:z}}", ""},
		{"not group", 390, "@media (not (max-width:48.75rem)) and (max-width:75rem){.x{y:z}}", ""},
		{"not group true", 800, "@media (not (max-width:48.75rem)) and (max-width:75rem){.x{y:z}}", "@media all{.x{y:z}}"},
		{"list true", 1280, "@media (min-width:40rem), print{.x{y:z}}", "@media all{.x{y:z}}"},
		{"list keeps unknown", 390, "@media (min-width:40rem), print{.x{y:z}}", "@media print{.x{y:z}}"},
		{"hover untouched", 390, "@media (hover:hover){.x{y:z}}", "@media (hover:hover){.x{y:z}}"},
		{"prefers untouched", 390, "@media(prefers-reduced-motion:no-preference){.x{y:z}}", "@media(prefers-reduced-motion:no-preference){.x{y:z}}"},
		{"print untouched", 390, "@media print{.x{y:z}}", "@media print{.x{y:z}}"},
		{"mixed true drops width", 1280, "@media (min-width:40rem) and (hover:hover){.x{y:z}}", "@media (hover:hover){.x{y:z}}"},
		{"mixed max true drops width", 390, "@media (max-width:48rem) and (hover:hover){.x{y:z}}", "@media (hover:hover){.x{y:z}}"},
		{"mixed screen keeps type", 1280, "@media only screen and (min-width:40rem) and (hover:hover){.x{y:z}}", "@media (hover:hover){.x{y:z}}"},
		{"mixed print keeps type", 1280, "@media print and (min-width:40rem){.x{y:z}}", "@media print{.x{y:z}}"},
		{"mixed only print", 1280, "@media only print and (min-width:40rem){.x{y:z}}", "@media only print{.x{y:z}}"},
		{"mixed not drops width", 1280, "@media not all and (min-width:40rem) and (hover:hover){.x{y:z}}", "@media not all and (hover:hover){.x{y:z}}"},
		{"mixed not print", 1280, "@media not print and (min-width:40rem){.x{y:z}}", "@media not print{.x{y:z}}"},
		{"mixed nested group", 1280, "@media ((min-width:40rem) and (hover:hover)){.x{y:z}}", "@media (hover:hover){.x{y:z}}"},
		{"mixed nested group false", 390, "@media ((min-width:40rem) and (hover:hover)){.x{y:z}}", ""},
		{"nested or group stays", 1280, "@media ((min-width:40rem) or (hover:hover)){.x{y:z}}", "@media ((min-width:40rem) or (hover:hover)){.x{y:z}}"},
		{"mixed in list", 1280, "@media (max-width:20rem), (min-width:40rem) and (hover:hover){.x{y:z}}", "@media (hover:hover){.x{y:z}}"},
		{"mixed false drops", 390, "@media (min-width:40rem) and (hover:hover){.x{y:z}}", ""},
		{"unknown unit stays", 390, "@media (min-width:50vw){.x{y:z}}", "@media (min-width:50vw){.x{y:z}}"},
		{"nested in supports", 390, "@supports (a:b){@media (min-width:64rem){.x{y:z}}.k{l:m}}", "@supports (a:b){.k{l:m}}"},
		{"nested in layer", 1280, "@layer c{@media (min-width:64rem){.x{y:z}}}", "@layer c{@media all{.x{y:z}}}"},
		{"nested media", 1280, "@media (min-width:40rem){@media (min-width:64rem){.x{y:z}}}", "@media all{@media all{.x{y:z}}}"},
		{"nested in rule", 390, ".a{color:red;@media (min-width:64rem){color:blue}}", ".a{color:red;}"},
		{"brace in string", 390, `.a::before{content:"}@media (min-width:1px){"}@media (min-width:64rem){.x{y:z}}`, `.a::before{content:"}@media (min-width:1px){"}`},
		{"brace in comment", 390, "/* } */@media (min-width:64rem){.x{y:z}}.k{l:m}", ".k{l:m}"},
		{"brace in url", 390, "a{b:url(data:image/svg+xml;utf8,<svg>{</svg>)}@media (min-width:64rem){.x{y:z}}", "a{b:url(data:image/svg+xml;utf8,<svg>{</svg>)}"},
		{"other at-rules stay", 390, "@font-face{font-family:x;src:url(a.woff2)}@property --a{syntax:'*'}", "@font-face{font-family:x;src:url(a.woff2)}@property --a{syntax:'*'}"},
		{"statements stay", 390, "@charset \"utf-8\";@import url(x.css);a{b:c}", "@charset \"utf-8\";@import url(x.css);a{b:c}"},
		{"unclosed block", 390, "@media (min-width:1px){.x{y:z}", "@media all{.x{y:z}}"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := resolveWidthMedia(c.css, c.width); got != c.want {
				t.Errorf("resolveWidthMedia(%q, %d)\n got %q\nwant %q", c.css, c.width, got, c.want)
			}
		})
	}
}
