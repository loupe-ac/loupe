package update_test

import (
	"errors"
	"os"
	"strings"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/update"
	"github.com/loupe-ac/loupe/cli/internal/update/signtest"
)

func readFile(t *testing.T, name string) []byte {
	t.Helper()
	data, err := os.ReadFile("testdata/" + name)
	if err != nil {
		t.Fatal(err)
	}

	return data
}

func fixtureKeys(t *testing.T) []update.TrustedKey {
	t.Helper()
	keys, err := update.ParseKeys(string(readFile(t, "test_key.pub")))
	if err != nil {
		t.Fatal(err)
	}

	return keys
}

// trustFixture makes the key of the ssh-keygen fixture the only trusted key.
func trustFixture(t *testing.T) string {
	t.Helper()
	old := update.TrustedKeys
	update.TrustedKeys = fixtureKeys(t)
	t.Cleanup(func() { update.TrustedKeys = old })

	return strings.TrimSpace(string(readFile(t, "test_key.fingerprint")))
}

func TestVerifySignatureAcceptsWhatSSHKeygenSigned(t *testing.T) {
	want := trustFixture(t)
	got, err := update.VerifySignature(readFile(t, "checksums.txt"), readFile(t, "checksums.txt.sig"), nil)
	if err != nil {
		t.Fatal(err)
	}
	if got != want {
		t.Fatalf("fingerprint = %s, want %s", got, want)
	}
}

func TestVerifySignatureRefusesATamperedFile(t *testing.T) {
	trustFixture(t)
	tampered := append(readFile(t, "checksums.txt"), "cccc  loupe_9.9.9_linux_amd64.tar.gz\n"...)
	_, err := update.VerifySignature(tampered, readFile(t, "checksums.txt.sig"), nil)
	if !errors.Is(err, update.ErrBadSignature) {
		t.Fatalf("err = %v, want ErrBadSignature", err)
	}
}

func TestVerifySignatureRefusesATruncatedSignature(t *testing.T) {
	trustFixture(t)
	sig := readFile(t, "checksums.txt.sig")
	for _, cut := range []int{0, 10, len(sig) / 2, len(sig) - 30} {
		if _, err := update.VerifySignature(readFile(t, "checksums.txt"), sig[:cut], nil); err == nil {
			t.Errorf("a signature cut at %d bytes verified", cut)
		}
	}
}

func TestVerifySignatureRefusesTrailingBytes(t *testing.T) {
	trustFixture(t)
	sig := readFile(t, "checksums.txt.sig")
	for name, extra := range map[string]string{"after the armor": "junk\n", "a second block": string(sig)} {
		if _, err := update.VerifySignature(readFile(t, "checksums.txt"), append(append([]byte{}, sig...), extra...), nil); err == nil {
			t.Errorf("a signature with %s verified", name)
		}
	}
}

func TestVerifySignatureRefusesAnUnknownKey(t *testing.T) {
	other := signtest.New(t)
	data := []byte("sums\n")
	_, err := update.VerifySignature(data, other.Sign(data), nil)
	if !errors.Is(err, update.ErrUntrustedKey) {
		t.Fatalf("err = %v, want ErrUntrustedKey", err)
	}
}

func TestVerifySignatureRefusesARevokedKey(t *testing.T) {
	key := signtest.New(t)
	key.Trust(t)
	data := []byte("sums\n")
	if _, err := update.VerifySignature(data, key.Sign(data), nil); err != nil {
		t.Fatal(err)
	}
	_, err := update.VerifySignature(data, key.Sign(data), []string{"SHA256:other", key.Fingerprint})
	if !errors.Is(err, update.ErrKeyRevoked) {
		t.Fatalf("err = %v, want ErrKeyRevoked", err)
	}
}

func TestVerifySignatureChecksNamespaceAndHash(t *testing.T) {
	key := signtest.New(t)
	key.Trust(t)
	data := []byte("sums\n")
	if _, err := update.VerifySignature(data, key.SignIn(data, "git", "sha512"), nil); !errors.Is(err, update.ErrBadSignature) {
		t.Errorf("another namespace: err = %v", err)
	}
	if _, err := update.VerifySignature(data, key.SignIn(data, "loupe-release", "sha256"), nil); !errors.Is(err, update.ErrBadSignature) {
		t.Errorf("another hash: err = %v", err)
	}
}

func TestVerifySignatureRefusesWrongMagicAndVersion(t *testing.T) {
	key := signtest.New(t)
	key.Trust(t)
	data := []byte("sums\n")
	good := string(key.Sign(data))
	for name, mutate := range map[string]func(string) string{
		"not armored": func(string) string { return "plain text" },
		"wrong type":  func(s string) string { return strings.ReplaceAll(s, "SSH SIGNATURE", "PGP SIGNATURE") },
		"empty":       func(string) string { return "" },
	} {
		if _, err := update.VerifySignature(data, []byte(mutate(good)), nil); err == nil {
			t.Errorf("%s verified", name)
		}
	}
}

func TestParseKeysReadsTheEmbeddedKeys(t *testing.T) {
	if len(update.TrustedKeys) != 2 {
		t.Fatalf("the CLI embeds %d release keys, want 2", len(update.TrustedKeys))
	}
	for _, k := range update.TrustedKeys {
		if !strings.HasPrefix(k.Fingerprint, "SHA256:") || strings.Contains(k.Fingerprint, "=") {
			t.Errorf("fingerprint %q is not in the ssh-keygen form", k.Fingerprint)
		}
	}
}

func TestParseKeysRefusesOtherKeyTypes(t *testing.T) {
	for _, text := range []string{"", "# only a comment\n", "loupe-release ssh-rsa AAAA\n", "loupe-release ssh-ed25519 !!!\n", "loupe-release ssh-ed25519 AAAA\n"} {
		if _, err := update.ParseKeys(text); err == nil {
			t.Errorf("ParseKeys(%q) accepted it", text)
		}
	}
}
