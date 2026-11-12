package cmd

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"os"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/spf13/cobra"
)

type fakeHoldClient struct {
	mu      sync.Mutex
	reports []api.InteractiveLaunchReport
	closes  []string
	failPut error
}

func (f *fakeHoldClient) ReportInteractiveLaunch(_ context.Context, handle, session string, r api.InteractiveLaunchReport) (bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.reports = append(f.reports, r)

	return true, f.failPut
}

func (f *fakeHoldClient) CloseInteractiveSession(_ context.Context, session string) (int, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.closes = append(f.closes, session)

	return 1, nil
}

func useBridgeID(t *testing.T, id string, err error) {
	t.Helper()
	old := initBridgeID
	initBridgeID = func() (string, error) { return id, err }
	t.Cleanup(func() { initBridgeID = old })
}

const holdBridgeID = "11111111-1111-4111-8111-111111111111"

func TestInitRunOpensWithTheBridgeIDAndClosesOnce(t *testing.T) {
	useBridgeID(t, holdBridgeID, nil)
	f := &fakeHoldClient{}
	var out bytes.Buffer

	run := startInitRun(context.Background(), &out, f, "proj-1")
	run.Close()
	run.Close()

	if len(f.reports) != 1 {
		t.Fatalf("reports = %d, want 1", len(f.reports))
	}
	r := f.reports[0]
	if r.SubjectType != "project" || r.SubjectID != "proj-1" || r.WorkKind != "init" || r.State != api.RunRunning || r.BridgeID != holdBridgeID || r.At.IsZero() || r.AwaitingInstall != nil {
		t.Errorf("launch = %+v", r)
	}
	if len(f.closes) != 1 || f.closes[0] != run.sessionID {
		t.Errorf("closes = %v, want one close of %s", f.closes, run.sessionID)
	}
	if out.Len() != 0 {
		t.Errorf("a held run prints %q", out.String())
	}
}

func TestInitRunRenewsWithTheWaitMark(t *testing.T) {
	useBridgeID(t, holdBridgeID, nil)
	old := initRenewEvery
	initRenewEvery = 10 * time.Millisecond
	t.Cleanup(func() { initRenewEvery = old })
	f := &fakeHoldClient{}

	run := startInitRun(context.Background(), &bytes.Buffer{}, f, "proj-1")
	if !run.setWaiting(context.Background(), true) {
		t.Fatal("the wait mark was refused")
	}
	waitFor(t, func() bool { return countAwaiting(f, true) >= 3 })
	run.setWaiting(context.Background(), false)
	f.mu.Lock()
	n := len(f.reports)
	f.mu.Unlock()
	waitFor(t, func() bool { f.mu.Lock(); defer f.mu.Unlock(); return len(f.reports) >= n+3 })
	run.Close()

	f.mu.Lock()
	defer f.mu.Unlock()
	falses := 0
	for _, r := range f.reports[n-1:] {
		if r.AwaitingInstall != nil && !*r.AwaitingInstall {
			falses++
		}
	}
	if falses != 1 {
		t.Errorf("reports after the wait carry awaitingInstall=false %d times, want 1", falses)
	}
}

func countAwaiting(f *fakeHoldClient, v bool) int {
	f.mu.Lock()
	defer f.mu.Unlock()
	n := 0
	for _, r := range f.reports {
		if r.AwaitingInstall != nil && *r.AwaitingInstall == v {
			n++
		}
	}

	return n
}

func waitFor(t *testing.T, ok func() bool) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for !ok() {
		if time.Now().After(deadline) {
			t.Fatal("timed out")
		}
		time.Sleep(5 * time.Millisecond)
	}
}

func TestInitRunGoesOnWithNoHoldWhenTheLaunchFails(t *testing.T) {
	useBridgeID(t, holdBridgeID, nil)
	f := &fakeHoldClient{failPut: errors.New("HTTP 404")}
	var out bytes.Buffer

	run := startInitRun(context.Background(), &out, f, "proj-1")
	ok := run.setWaiting(context.Background(), true)
	run.Close()

	if !strings.Contains(out.String(), "This Loupe server cannot hold the onboarding inbox items. They may appear while init runs.") {
		t.Errorf("output = %q", out.String())
	}
	if ok || len(f.reports) != 1 || len(f.closes) != 1 || f.closes[0] != run.sessionID {
		t.Errorf("ok = %v, reports = %d, closes = %d; want no hold and one close of the launch session", ok, len(f.reports), len(f.closes))
	}
}

func TestInitRunGoesOnWithNoHoldWhenTheBridgeIDFails(t *testing.T) {
	useBridgeID(t, "", errors.New("not logged in"))
	f := &fakeHoldClient{}
	var out bytes.Buffer

	run := startInitRun(context.Background(), &out, f, "proj-1")
	run.Close()

	if len(f.reports) != 0 || !strings.Contains(out.String(), "cannot hold") {
		t.Errorf("reports = %d, output = %q", len(f.reports), out.String())
	}
}

func TestWithInitValueAddsTheSessionToTheQuery(t *testing.T) {
	if got := withInitValue("https://l.test/projects/p/github/install?repository=a%2Fb", "s-1"); got != "https://l.test/projects/p/github/install?repository=a%2Fb&init=s-1" {
		t.Errorf("link = %q", got)
	}
	if got := withInitValue("https://l.test/x", "s-1"); got != "https://l.test/x?init=s-1" {
		t.Errorf("link = %q", got)
	}
}

func installedSite(id string, installed, rulesRead bool) api.Site {
	yes := installed
	var rr *string
	if rulesRead {
		ok := "ok"
		rr = &ok
	}

	return api.Site{ID: id, GitHub: &api.GitHubFacts{AppInstalled: installed, Repositories: []api.GitHubRepository{{Path: "acme/app", AppInstalled: &yes, RulesRead: rr}}}}
}

func fastWait(t *testing.T) {
	t.Helper()
	oldPoll, oldRules := installPollEvery, rulesReadWait
	installPollEvery, rulesReadWait = 5*time.Millisecond, 40*time.Millisecond
	t.Cleanup(func() { installPollEvery, rulesReadWait = oldPoll, oldRules })
}

func TestWaitForInstallPollsUntilTheRulesAreRead(t *testing.T) {
	fastWait(t)
	step := 0
	list := func(context.Context) ([]api.Site, error) {
		step++
		switch {
		case step == 1:
			return nil, errors.New("offline")
		case step == 2:
			return []api.Site{installedSite("p", false, false)}, nil
		case step == 3:
			return []api.Site{installedSite("p", true, false)}, nil
		}

		return []api.Site{installedSite("p", true, true)}, nil
	}

	site, ok := waitForInstall(context.Background(), list, api.Site{ID: "p"}, "Acme/App", nil, nil)

	if !ok || step != 4 || site.GitHub.Repositories[0].RulesRead == nil {
		t.Errorf("ok = %v, step = %d, site = %+v", ok, step, site)
	}
}

func TestWaitForInstallStopsWaitingForRulesAfterTheLimit(t *testing.T) {
	fastWait(t)
	list := func(context.Context) ([]api.Site, error) { return []api.Site{installedSite("p", true, false)}, nil }

	start := time.Now()
	_, ok := waitForInstall(context.Background(), list, api.Site{ID: "p"}, "acme/app", nil, nil)

	if !ok || time.Since(start) < rulesReadWait {
		t.Errorf("ok = %v after %v", ok, time.Since(start))
	}
}

func TestWaitForInstallEndsOnEnterAndOnASignal(t *testing.T) {
	fastWait(t)
	list := func(context.Context) ([]api.Site, error) { return []api.Site{installedSite("p", false, false)}, nil }
	enter := make(chan struct{})
	close(enter)
	if _, ok := waitForInstall(context.Background(), list, api.Site{ID: "p"}, "acme/app", enter, nil); ok {
		t.Error("Enter before the install reports an install")
	}

	sigs := make(chan os.Signal, 1)
	sigs <- os.Interrupt
	if _, ok := waitForInstall(context.Background(), list, api.Site{ID: "p"}, "acme/app", nil, sigs); ok {
		t.Error("Ctrl-C before the install reports an install")
	}
}

func TestInitWaitForAppMarksTheWaitAndOpensTheLinkWithTheInitValue(t *testing.T) {
	fastWait(t)
	useBridgeID(t, holdBridgeID, nil)
	f := &fakeHoldClient{}
	var out bytes.Buffer
	run := startInitRun(context.Background(), &out, f, "p")
	defer run.Close()
	var opened string
	old := initOpenBrowser
	initOpenBrowser = func(link string) error { opened = link; return nil }
	t.Cleanup(func() { initOpenBrowser = old })
	list := func(context.Context) ([]api.Site, error) { return []api.Site{installedSite("p", true, true)}, nil }
	cmd := &cobra.Command{}
	cmd.SetContext(context.Background())
	cmd.SetOut(&out)
	cmd.SetIn(bufio.NewReader(strings.NewReader("\n")))
	app := appCheck{need: appInstall, repository: "acme/app", link: "https://l.test/projects/p/github/install?repository=acme%2Fapp"}

	site, ok := initWaitForApp(cmd, list, run, api.Site{ID: "p"}, app)

	if !ok || site.GitHub == nil {
		t.Errorf("ok = %v, site = %+v", ok, site)
	}
	if want := app.link + "&init=" + run.sessionID; opened != want {
		t.Errorf("opened = %q, want %q", opened, want)
	}
	for _, want := range []string{"Install the Loupe GitHub App on acme/app now? [Y/n]", "Waiting for the install… Press Enter to stop waiting.", opened} {
		if !strings.Contains(out.String(), want) {
			t.Errorf("output lacks %q:\n%s", want, out.String())
		}
	}
	if countAwaiting(f, true) != 1 || countAwaiting(f, false) != 1 {
		t.Errorf("wait marks: true %d, false %d; want 1 and 1", countAwaiting(f, true), countAwaiting(f, false))
	}
}

func TestInitWaitForAppAsksNothingWhenTheAppCoversTheRepository(t *testing.T) {
	var out bytes.Buffer
	cmd := &cobra.Command{}
	cmd.SetContext(context.Background())
	cmd.SetOut(&out)
	cmd.SetIn(strings.NewReader("y\n"))

	_, ok := initWaitForApp(cmd, nil, &initRun{}, api.Site{}, appCheck{need: appCovered, repository: "acme/app"})

	if ok || out.Len() != 0 {
		t.Errorf("ok = %v, output = %q", ok, out.String())
	}
}

func TestInitWaitForAppWaitsNotWhenTheOwnerSaysNo(t *testing.T) {
	var out bytes.Buffer
	cmd := &cobra.Command{}
	cmd.SetContext(context.Background())
	cmd.SetOut(&out)
	cmd.SetIn(bufio.NewReader(strings.NewReader("n\n")))

	_, ok := initWaitForApp(cmd, nil, &initRun{}, api.Site{}, appCheck{need: appPickRepository, repository: "acme/app"})

	if ok || strings.Contains(out.String(), "Waiting") {
		t.Errorf("ok = %v, output = %q", ok, out.String())
	}
}

func TestInitHoldsTheInboxFromTheProjectStepToTheEnd(t *testing.T) {
	w := newInitWorld(t, devSite())
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	w.launchMu.Lock()
	defer w.launchMu.Unlock()
	if len(w.launches) < 1 || w.closes != 1 {
		t.Fatalf("launches = %d, closes = %d; want an open and one close", len(w.launches), w.closes)
	}
	first := w.launches[0]
	if first["subjectType"] != "project" || first["subjectId"] != firstProject || first["workKind"] != "init" || first["state"] != "running" || first["bridgeId"] == "" || first["at"] == nil {
		t.Errorf("first launch = %v", first)
	}
	if strings.Contains(out, "cannot hold") {
		t.Errorf("a server that takes the launch prints the old-server note:\n%s", out)
	}
}

func TestInitGoesOnWhenTheServerRefusesTheLaunch(t *testing.T) {
	w := newInitWorld(t, devSite())
	w.launchStatus = 404
	inRepo(t, w.server.URL)

	out, err := runInitCmd(t, "1\nn\n", "--no-mcp")
	if err != nil {
		t.Fatalf("init: %v\n%s", err, out)
	}

	if !strings.Contains(out, "This Loupe server cannot hold the onboarding inbox items. They may appear while init runs.") {
		t.Errorf("output lacks the old-server note:\n%s", out)
	}
	w.launchMu.Lock()
	defer w.launchMu.Unlock()
	if w.closes != 1 {
		t.Errorf("closes = %d, want one best-effort close of the refused launch", w.closes)
	}
}

func TestInitRunHandsCtrlCToARunningWait(t *testing.T) {
	r := &initRun{stop: make(chan struct{}), done: make(chan struct{}), notify: make(chan os.Signal, 1), waitSigs: make(chan os.Signal, 1)}
	r.inWait.Store(true)
	go r.loop(time.Hour)

	r.notify <- os.Interrupt

	select {
	case <-r.waitSigs:
	case <-time.After(2 * time.Second):
		t.Fatal("the wait did not get the interrupt")
	}
	close(r.stop)
	<-r.done
}

func TestInitRunKeepsCtrlCQueuedAtTheEndOfTheWaitForTheLoop(t *testing.T) {
	r := &initRun{stop: make(chan struct{}), done: make(chan struct{}), notify: make(chan os.Signal, 1), waitSigs: make(chan os.Signal, 1)}
	r.beginWait()
	r.notify <- os.Interrupt
	r.endWait()

	if len(r.notify) != 1 {
		t.Fatal("the end of the wait discarded an interrupt that init must act on")
	}
	if r.keepsInWait() {
		t.Error("an interrupt read after the wait ended must end init")
	}
}

func TestInitRunHandsCtrlCToTheWaitWhileARenewalBlocks(t *testing.T) {
	useBridgeID(t, holdBridgeID, nil)
	f := &blockingHoldClient{release: make(chan struct{})}
	r := &initRun{client: f, stop: make(chan struct{}), done: make(chan struct{}), notify: make(chan os.Signal, 1), waitSigs: make(chan os.Signal, 1)}
	r.beginWait()
	go r.loop(10 * time.Millisecond)
	waitFor(t, func() bool { return r.renewing.Load() })

	r.notify <- os.Interrupt

	select {
	case <-r.waitSigs:
	case <-time.After(2 * time.Second):
		t.Fatal("a blocked renewal kept Ctrl-C from the wait")
	}
	close(f.release)
	close(r.stop)
	<-r.done
}

type blockingHoldClient struct{ release chan struct{} }

func (b *blockingHoldClient) ReportInteractiveLaunch(context.Context, string, string, api.InteractiveLaunchReport) (bool, error) {
	<-b.release

	return true, nil
}

func (b *blockingHoldClient) CloseInteractiveSession(context.Context, string) (int, error) {
	return 0, nil
}

func TestInitRunSerializesRenewalAndClear(t *testing.T) {
	useBridgeID(t, holdBridgeID, nil)
	f := &fakeHoldClient{}
	run := startInitRun(context.Background(), &bytes.Buffer{}, f, "proj-1")
	run.setWaiting(context.Background(), true)
	var wg sync.WaitGroup
	for i := 0; i < 20; i++ {
		wg.Add(1)
		go func() { defer wg.Done(); run.renew() }()
	}
	run.setWaiting(context.Background(), false)
	wg.Wait()
	run.renew()
	run.Close()

	f.mu.Lock()
	defer f.mu.Unlock()
	cleared := false
	for _, r := range f.reports {
		if r.AwaitingInstall != nil && !*r.AwaitingInstall {
			cleared = true
		} else if cleared && r.AwaitingInstall != nil && *r.AwaitingInstall {
			t.Fatal("a renewal marked the run after the clear")
		}
	}
}

func TestWaitForInstallStopsWhenPollingFailsAfterTheInstall(t *testing.T) {
	oldPoll, oldRules := installPollEvery, rulesReadWait
	installPollEvery, rulesReadWait = 5*time.Millisecond, 40*time.Millisecond
	t.Cleanup(func() { installPollEvery, rulesReadWait = oldPoll, oldRules })
	calls := 0
	list := func(context.Context) ([]api.Site, error) {
		calls++
		if calls > 1 {
			return nil, errors.New("down")
		}

		return []api.Site{installedSite("p", true, false)}, nil
	}
	done := make(chan bool, 1)
	go func() {
		_, ok := waitForInstall(context.Background(), list, api.Site{ID: "p"}, "acme/app", nil, nil)
		done <- ok
	}()
	select {
	case ok := <-done:
		if !ok {
			t.Error("the wait saw the install, want true")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("the wait outlived the rules limit while polling failed")
	}
}
