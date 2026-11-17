package cmd

import (
	"bytes"
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/skills"
	"github.com/loupe-ac/loupe/cli/internal/update"
)

func TestTheUpdaterRecordsTheSignerOfAStagedRelease(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())

	st, _ := update.LoadState(h.u.dir)
	if st.Signers["1.2.0"] != gh.key.Fingerprint {
		t.Fatalf("signers = %v, want %s", st.Signers, gh.key.Fingerprint)
	}
	if h.events("update_signatures_read") != 1 {
		t.Fatalf("signature reads were not logged: %s", h.log.String())
	}
}

func TestTheUpdaterFallsToTheNextLowerReleaseOnABadSignature(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.1.0", "cli/v1.2.0")
	gh.badSig["1.2.0"] = true
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())
	h.u.check(context.Background())

	if len(h.staged) != 2 || !strings.HasPrefix(h.staged[0], "1.1.0 ") {
		t.Fatalf("hook calls = %v, want 1.1.0 twice", h.staged)
	}
	if h.events("update_signature_rejected") != 1 {
		t.Fatalf("update_signature_rejected logged %d times, want once: %s", h.events("update_signature_rejected"), h.log.String())
	}
	if !strings.Contains(h.log.String(), `"version":"1.2.0"`) {
		t.Fatalf("the log names no version: %s", h.log.String())
	}
}

func TestTheUpdaterNeverDownloadsAnArchiveOfAReleaseWithABadSignature(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	gh.badSig["1.2.0"] = true
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())

	if len(h.staged) != 0 || h.events("update_download") != 0 {
		t.Fatalf("a release with a bad signature was downloaded: %s", h.log.String())
	}
}

func TestTheUpdaterIgnoresAReleaseWithNoSignatureAsset(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.1.0", "cli/v1.2.0")
	gh.unsigned["1.2.0"] = true
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")

	h.u.check(context.Background())

	if len(h.staged) != 1 || !strings.HasPrefix(h.staged[0], "1.1.0 ") {
		t.Fatalf("hook calls = %v", h.staged)
	}
	if h.events("update_signature_rejected") != 0 {
		t.Fatalf("an unsigned release is no candidate, so it has no rejection: %s", h.log.String())
	}
}

func TestARunningVersionOutsideTheRangeReachesOnlyASignedRelease(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v2.0.0")
	gh.unsigned["2.0.0"] = true
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^2.0")

	h.u.check(context.Background())

	if len(h.staged) != 0 || h.events("update_unavailable") != 1 {
		t.Fatalf("log = %s", h.log.String())
	}
}

func TestTheUpdaterRefusesARevokedKey(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.1.0", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")
	h.u.setRevoked([]string{gh.key.Fingerprint})

	h.u.check(context.Background())

	if len(h.staged) != 0 || h.events("update_signature_rejected") != 2 {
		t.Fatalf("staged = %v, log = %s", h.staged, h.log.String())
	}
}

func TestTheStoredRevokedSetOnlyGrows(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")

	h.u.setRevoked([]string{"SHA256:a"})
	h.u.setRevoked(nil)
	h.u.setRevoked([]string{"SHA256:b"})
	h.u.setRevoked([]string{})

	if got := update.LoadRevoked(h.u.dir); len(got) != 2 || got[0] != "SHA256:a" || got[1] != "SHA256:b" {
		t.Fatalf("revoked = %v", got)
	}
}

func TestANewRevokedKeyAsksForACheck(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")

	h.u.setRevoked([]string{"SHA256:a"})

	select {
	case <-h.u.kick:
	default:
		t.Fatal("a new revoked key asked for no check")
	}
}

func TestTheUpdaterDeletesAStagedBinaryWhoseKeyIsRevoked(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.1.0", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.outcome = stagedDeferred
	h.u.setRange("^1.0")
	h.u.check(context.Background())
	path := update.StagePath(h.u.dir, "1.2.0")
	if _, err := os.Stat(path); err != nil {
		t.Fatalf("nothing staged: %v", err)
	}

	h.u.setRevoked([]string{gh.key.Fingerprint})
	h.u.check(context.Background())

	if _, err := os.Stat(path); err == nil {
		t.Fatal("the staged binary of a revoked key is still there")
	}
	st, _ := update.LoadState(h.u.dir)
	if len(st.Staged) != 0 || len(st.Signers) != 0 {
		t.Fatalf("update.json = %+v", st)
	}
	if h.events("update_staged_deleted") != 1 {
		t.Fatalf("the deletion was not logged: %s", h.log.String())
	}
}

func TestTheUpdaterRestagesWhenTheSignerOfAStagedBinaryIsUnknown(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")
	st, _ := update.LoadState(h.u.dir)
	path := update.StagePath(h.u.dir, "1.2.0")
	if err := update.WriteFile(path, []byte("planted"), 0o755); err != nil {
		t.Fatal(err)
	}
	st.Staged["1.2.0"] = path
	if err := st.Save(h.u.dir); err != nil {
		t.Fatal(err)
	}

	h.u.check(context.Background())

	if data, _ := os.ReadFile(path); string(data) != "new binary" {
		t.Fatalf("the staged binary was kept: %q", data)
	}
}

func TestSelfUpdateRefusesARevokedKeyWithTheServerUnreachable(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.1.0", "cli/v1.2.0")
	self, exe := selfUpdateAgainst(t, gh, "1.0.0")
	dir := mustConfigDir(t)
	if _, err := update.AddRevoked(dir, []string{gh.key.Fingerprint}); err != nil {
		t.Fatal(err)
	}

	out, err := updateCmd(t, self)

	if err == nil || !strings.Contains(out, "no valid signature") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	if data, _ := os.ReadFile(exe); string(data) != "old" {
		t.Fatalf("binary = %q", data)
	}
}

func TestSelfUpdateStoresTheRevokedKeysOfTheServer(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	self, exe := selfUpdateAgainst(t, gh, "1.0.0")
	self.serverPolicy = func(context.Context) (serverPolicy, error) {
		return serverPolicy{cliRange: "^1.0", revoked: []string{gh.key.Fingerprint}}, nil
	}

	out, err := updateCmd(t, self)

	if err != nil || !strings.Contains(out, "current at 1.0.0") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
	if data, _ := os.ReadFile(exe); string(data) != "old" {
		t.Fatalf("binary = %q", data)
	}
	if got := update.LoadRevoked(mustConfigDir(t)); len(got) != 1 || got[0] != gh.key.Fingerprint {
		t.Fatalf("stored revoked = %v", got)
	}
}

func TestSelfUpdateFallsToTheBackupSignedRelease(t *testing.T) {
	shortConfigHome(t)
	gh := newFakeGitHub(t, "new binary", "cli/v1.1.0", "cli/v1.2.0")
	gh.badSig["1.2.0"] = true
	self, exe := selfUpdateAgainst(t, gh, "1.0.0")
	self = withServerRange(self, "^1.0")

	out, err := updateCmd(t, self)

	if err != nil || !strings.Contains(out, "installed 1.1.0 over "+exe) || !strings.Contains(out, "1.2.0 has no valid signature") {
		t.Fatalf("err = %v, out = %q", err, out)
	}
}

func TestCheckStartupStoresTheRevokedKeysOfTheServer(t *testing.T) {
	srv := versionsServer(t, `{"versions":[{"version":"v1","status":"beta"}],"cliRange":"^2.0","revokedReleaseKeys":["SHA256:a","SHA256:b"]}`)
	var got []string
	gate := startupGate{version: "2.0.1", onRevoked: func(f []string) { got = f }}

	if err := checkStartup(context.Background(), api.New(srv.URL, "token", srv.Client()), gate); err != nil {
		t.Fatal(err)
	}
	if len(got) != 2 || got[0] != "SHA256:a" || got[1] != "SHA256:b" {
		t.Fatalf("revoked = %v", got)
	}
}

func TestAResumeTakesTheCurrentVersionWhenItsRecordedKeyIsRevoked(t *testing.T) {
	r, dir := packageStore(t)
	old := filepath.Join(dir, "skills", "loupe", "0.9.0")
	if err := os.MkdirAll(old, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(old, ".signer"), []byte("SHA256:stolen\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := skills.WriteRecord(dir, "s", []skills.Pick{{Package: "loupe", Version: "0.9.0"}}); err != nil {
		t.Fatal(err)
	}
	spec := workerSpec{resume: true, sessionID: "s"}
	if err := r.givePackages(&spec, pending{project: "x"}); err != nil || spec.packages[0].Version != "0.9.0" {
		t.Fatalf("before the revocation: %+v, %v", spec.packages, err)
	}

	if _, err := update.AddRevoked(dir, []string{"SHA256:stolen"}); err != nil {
		t.Fatal(err)
	}
	var buf bytes.Buffer
	r.log = debugLogger(&buf)
	spec = workerSpec{resume: true, sessionID: "s"}
	if err := r.givePackages(&spec, pending{project: "x"}); err != nil {
		t.Fatal(err)
	}
	for _, p := range spec.packages {
		if p.Version == "0.9.0" {
			t.Fatalf("the resume kept the version of a revoked key: %+v", spec.packages)
		}
	}
	if !strings.Contains(buf.String(), "skill_packages_current_on_resume") {
		t.Fatalf("log = %s", buf.String())
	}
}

func versionsServer(t *testing.T, body string) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)

	return srv
}

func TestTheUpdaterRefusesAKeyThatItCouldNotSave(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")
	if err := os.Mkdir(filepath.Join(h.u.dir, "update.json"), 0o700); err != nil {
		t.Fatal(err)
	}

	h.u.setRevoked([]string{gh.key.Fingerprint})
	h.u.check(context.Background())

	if len(h.staged) != 0 || h.events("update_revoked_unsaved") != 1 {
		t.Fatalf("staged = %v, log = %s", h.staged, h.log.String())
	}
}

func TestTheUpdaterDoesNotHandOverAKeyRevokedDuringTheDownload(t *testing.T) {
	gh := newFakeGitHub(t, "new binary", "cli/v1.2.0")
	h := newTestUpdater(t, gh, "1.0.0")
	h.u.setRange("^1.0")
	gh.onArchive = func() { h.u.setRevoked([]string{gh.key.Fingerprint}) }

	h.u.check(context.Background())

	if len(h.staged) != 0 {
		t.Fatalf("staged = %v", h.staged)
	}
	if _, err := os.Stat(update.StagePath(h.u.dir, "1.2.0")); err == nil {
		t.Fatal("the staged binary of a revoked key is still there")
	}
}
