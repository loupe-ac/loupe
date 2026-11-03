package api

import (
	"encoding/json"
	"slices"
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
