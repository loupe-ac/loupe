package update_test

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/update"
	"github.com/loupe-ac/loupe/cli/internal/update/signtest"
)

// signedFeed serves the checksums.txt and the signature of each version.
type signedFeed struct {
	server   *httptest.Server
	releases []update.Release
	requests []string
	bodies   map[string]string
}

func newSignedFeed(t *testing.T) *signedFeed {
	f := &signedFeed{bodies: map[string]string{}}
	f.server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.requests = append(f.requests, r.URL.Path)
		fmt.Fprint(w, f.bodies[r.URL.Path])
	}))
	t.Cleanup(f.server.Close)

	return f
}

// add publishes a version whose checksums.txt is signed by sign, with a sig
// body chosen by the caller.
func (f *signedFeed) add(version string, sig func(data []byte) []byte) {
	tag := "cli/v" + version
	sums := "aaaa  loupe_" + version + "_linux_amd64.tar.gz\n"
	f.bodies["/"+version+"/checksums.txt"] = sums
	f.bodies["/"+version+"/checksums.txt.sig"] = string(sig([]byte(sums)))
	f.releases = append(f.releases, update.Release{TagName: tag, Assets: []update.Asset{
		{Name: "loupe_" + version + "_linux_amd64.tar.gz", URL: f.server.URL + "/" + version + "/archive"},
		{Name: "checksums.txt", URL: f.server.URL + "/" + version + "/checksums.txt"},
		{Name: "checksums.txt.sig", URL: f.server.URL + "/" + version + "/checksums.txt.sig"},
	}})
}

func anyVersion(update.Version) bool { return true }

func TestPickSignedWalksDownToTheHighestVerifiedRelease(t *testing.T) {
	good := signtest.New(t)
	good.Trust(t)
	bad := signtest.New(t)
	f := newSignedFeed(t)
	f.add("1.1.0", good.Sign)
	f.add("1.2.0", bad.Sign)
	f.add("1.3.0", func([]byte) []byte { return []byte("garbage") })

	var rejected []string
	got, found, err := update.PickSigned(t.Context(), f.server.Client(), f.releases, "linux", "amd64", anyVersion, nil,
		func(c update.Candidate, _ error) { rejected = append(rejected, c.Version.String()) })
	if err != nil || !found {
		t.Fatalf("found = %v, err = %v", found, err)
	}
	if got.Version.String() != "1.1.0" || got.Signer != good.Fingerprint || got.Reads != 3 {
		t.Fatalf("pick = %s signer %s reads %d", got.Version, got.Signer, got.Reads)
	}
	if len(rejected) != 2 || rejected[0] != "1.3.0" || rejected[1] != "1.2.0" {
		t.Fatalf("rejected = %v", rejected)
	}
	if string(got.ChecksumsData) != f.bodies["/1.1.0/checksums.txt"] {
		t.Fatalf("checksums = %q", got.ChecksumsData)
	}
}

func TestPickSignedFindsNothingWhenNoReleaseVerifies(t *testing.T) {
	key := signtest.New(t)
	key.Trust(t)
	other := signtest.New(t)
	f := newSignedFeed(t)
	f.add("1.1.0", other.Sign)
	f.add("1.2.0", other.Sign)

	got, found, err := update.PickSigned(t.Context(), f.server.Client(), f.releases, "linux", "amd64", anyVersion, nil, nil)
	if err != nil || found {
		t.Fatalf("found = %v, err = %v", found, err)
	}
	if got.Reads != 2 {
		t.Fatalf("reads = %d", got.Reads)
	}
}

func TestPickSignedSkipsARevokedKeyAndFallsToTheBackupKey(t *testing.T) {
	stolen := signtest.New(t)
	backup := signtest.New(t)
	old := update.TrustedKeys
	update.TrustedKeys = []update.TrustedKey{update.NewTrustedKey(stolen.Public()), update.NewTrustedKey(backup.Public())}
	t.Cleanup(func() { update.TrustedKeys = old })
	f := newSignedFeed(t)
	f.add("1.1.0", backup.Sign)
	f.add("2.99.0", stolen.Sign)

	got, found, err := update.PickSigned(t.Context(), f.server.Client(), f.releases, "linux", "amd64", anyVersion, nil, nil)
	if err != nil || !found || got.Version.String() != "2.99.0" {
		t.Fatalf("before the revocation: %s found %v err %v", got.Version, found, err)
	}
	got, found, err = update.PickSigned(t.Context(), f.server.Client(), f.releases, "linux", "amd64", anyVersion, []string{stolen.Fingerprint}, nil)
	if err != nil || !found || got.Version.String() != "1.1.0" || got.Signer != backup.Fingerprint {
		t.Fatalf("after the revocation: %s found %v err %v", got.Version, found, err)
	}
}

func TestPickSignedReadsNothingForAReleaseWithNoSignatureAsset(t *testing.T) {
	key := signtest.New(t)
	key.Trust(t)
	f := newSignedFeed(t)
	f.add("1.1.0", key.Sign)
	f.releases = append(f.releases, update.Release{TagName: "cli/v1.2.0", Assets: []update.Asset{
		{Name: "loupe_1.2.0_linux_amd64.tar.gz", URL: f.server.URL + "/x"},
		{Name: "checksums.txt", URL: f.server.URL + "/1.2.0/checksums.txt"},
	}})

	got, found, err := update.PickSigned(t.Context(), f.server.Client(), f.releases, "linux", "amd64", anyVersion, nil, nil)
	if err != nil || !found || got.Version.String() != "1.1.0" || got.Reads != 1 {
		t.Fatalf("pick = %s found %v err %v reads %d", got.Version, found, err, got.Reads)
	}
	for _, p := range f.requests {
		if p == "/1.2.0/checksums.txt" {
			t.Fatal("the unsigned release was read")
		}
	}
}

func TestPickSignedFailsOnADownloadError(t *testing.T) {
	key := signtest.New(t)
	key.Trust(t)
	f := newSignedFeed(t)
	f.add("1.1.0", key.Sign)
	f.releases[0].Assets[2].URL = f.server.URL + "/missing"
	f.server.Config.Handler = http.NotFoundHandler()

	if _, _, err := update.PickSigned(t.Context(), f.server.Client(), f.releases, "linux", "amd64", anyVersion, nil, nil); err == nil {
		t.Fatal("a failed download passed")
	}
}
