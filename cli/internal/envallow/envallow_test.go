package envallow

import (
	"slices"
	"testing"
)

func TestFilter(t *testing.T) {
	environ := []string{
		"PATH=/bin", "HOME=/h", "LC_ALL=C", "LOUPE_API_KEY=s", "LOUPE_URL=u", "AWS_SECRET=x",
		"ANTHROPIC_API_KEY=k", "OPENAI_API_KEY=o", "HOMEBREW_GITHUB_API_TOKEN=t", "HOMEBREW_PREFIX=/opt",
		"GIT_CONFIG_COUNT=1", "GIT_CONFIG_KEY_0=a", "SSH_AUTH_SOCK=/s", FilteredMarker + "=1",
	}
	tests := []struct {
		name     string
		harness  string
		patterns []string
		want     []string
	}{
		{"claude", HarnessClaude, nil, []string{"PATH=/bin", "HOME=/h", "LC_ALL=C", "LOUPE_URL=u", "ANTHROPIC_API_KEY=k", "HOMEBREW_PREFIX=/opt", "SSH_AUTH_SOCK=/s"}},
		{"codex", HarnessCodex, nil, []string{"PATH=/bin", "HOME=/h", "LC_ALL=C", "LOUPE_URL=u", "OPENAI_API_KEY=o", "HOMEBREW_PREFIX=/opt", "SSH_AUTH_SOCK=/s"}},
		{"pass exact and prefix", HarnessClaude, []string{"AWS_SECRET", "GIT_CONFIG_*"}, []string{"PATH=/bin", "HOME=/h", "LC_ALL=C", "LOUPE_URL=u", "AWS_SECRET=x", "ANTHROPIC_API_KEY=k", "HOMEBREW_PREFIX=/opt", "GIT_CONFIG_COUNT=1", "GIT_CONFIG_KEY_0=a", "SSH_AUTH_SOCK=/s"}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := Filter(environ, append(Base(tt.harness), tt.patterns...))
			if !slices.Equal(got, tt.want) {
				t.Fatalf("got %v, want %v", got, tt.want)
			}
		})
	}
}

func TestDroppedNamesOnly(t *testing.T) {
	got := Dropped([]string{"PATH=/bin", "SECRET=value", "SECRET=again", "A_KEY=v"}, Base(HarnessClaude))
	if want := []string{"A_KEY", "SECRET"}; !slices.Equal(got, want) {
		t.Fatalf("got %v, want %v", got, want)
	}
}

func TestEnvironUsesPass(t *testing.T) {
	t.Setenv("MY_PASS_VAR", "1")
	t.Setenv("MY_OTHER_VAR", "1")
	SetPass([]string{"MY_PASS_VAR"})
	t.Cleanup(func() { SetPass(nil) })
	env := Environ(HarnessClaude)
	if !slices.Contains(env, "MY_PASS_VAR=1") || slices.Contains(env, "MY_OTHER_VAR=1") {
		t.Fatalf("unexpected env %v", env)
	}
}

func TestEnvironWithIgnoresTheActivePass(t *testing.T) {
	t.Setenv("ACTIVE_VAR", "1")
	t.Setenv("CANDIDATE_VAR", "1")
	SetPass([]string{"ACTIVE_VAR"})
	t.Cleanup(func() { SetPass(nil) })
	env := EnvironWith(HarnessClaude, []string{"CANDIDATE_VAR"})
	if !slices.Contains(env, "CANDIDATE_VAR=1") || slices.Contains(env, "ACTIVE_VAR=1") {
		t.Fatalf("unexpected env %v", env)
	}
}
