package api

import (
	"encoding/json"
	"slices"
	"strings"
	"testing"
)

func TestSiteReadsItsSkillPackagesAndProfileNeed(t *testing.T) {
	var site Site
	if err := json.Unmarshal([]byte(`{"id":"p","slug":"s","skillPackages":["loupe"],"needsProfile":false}`), &site); err != nil {
		t.Fatal(err)
	}
	if !slices.Equal(site.PackagesIn([]string{"loupe", "loupe-lifecycle"}), []string{"loupe"}) || site.ProfileNeeded() {
		t.Fatalf("site = %+v", site)
	}
}

func TestSiteWithNullOrNoValuesTakesTheFallback(t *testing.T) {
	for _, body := range []string{`{"id":"p","skillPackages":null,"needsProfile":null}`, `{"id":"p"}`} {
		var site Site
		if err := json.Unmarshal([]byte(body), &site); err != nil {
			t.Fatal(err)
		}
		held := []string{"loupe", "loupe-lifecycle"}
		if !slices.Equal(site.PackagesIn(held), held) || !site.ProfileNeeded() {
			t.Fatalf("%s: site = %+v", body, site)
		}
	}
}

func TestSiteWithAnInvalidPackageListTakesTheFallback(t *testing.T) {
	bodies := map[string]string{
		"empty":    `{"skillPackages":[]}`,
		"repeated": `{"skillPackages":["loupe","loupe"]}`,
		"upper":    `{"skillPackages":["Loupe"]}`,
		"path":     `{"skillPackages":["../loupe"]}`,
		"blank":    `{"skillPackages":["loupe",""]}`,
		"newline":  `{"skillPackages":["loupe\n"]}`,
		"too long": `{"skillPackages":["` + strings.Repeat("a", 65) + `"]}`,
	}
	held := []string{"loupe", "loupe-lifecycle"}
	for name, body := range bodies {
		var site Site
		if err := json.Unmarshal([]byte(body), &site); err != nil {
			t.Fatal(err)
		}
		if got := site.PackagesIn(held); !slices.Equal(got, held) {
			t.Errorf("%s: packages = %v", name, got)
		}
	}
}

func TestSiteAcceptsTheLongestPackageName(t *testing.T) {
	name := strings.Repeat("a", 64)
	site := Site{SkillPackages: []string{name}}
	if got := site.PackagesIn([]string{"loupe"}); !slices.Equal(got, []string{name}) {
		t.Fatalf("packages = %v", got)
	}
}
