// Package signtest makes release signatures for tests. Its keys are throwaway
// keys, and it never reads or holds a release key.
package signtest

import (
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha512"
	"encoding/base64"
	"encoding/binary"
	"encoding/pem"
	"testing"

	"github.com/loupe-ac/loupe/cli/internal/update"
)

// Key is a throwaway signing key.
type Key struct {
	private ed25519.PrivateKey
	public  ed25519.PublicKey
	// Fingerprint is the SHA256:... form of the public key.
	Fingerprint string
}

// New makes a key.
func New(t testing.TB) *Key {
	t.Helper()
	public, private, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}

	return &Key{private: private, public: public, Fingerprint: update.NewTrustedKey(public).Fingerprint}
}

// Public is the public key.
func (k *Key) Public() ed25519.PublicKey { return k.public }

// Line is the public key as a line of an allowed_signers file.
func (k *Key) Line() string {
	return "loupe-test ssh-ed25519 " + base64.StdEncoding.EncodeToString(wireKey(k.public))
}

// Trust makes k the only trusted release key until the test ends.
func (k *Key) Trust(t testing.TB) {
	t.Helper()
	old := update.TrustedKeys
	update.TrustedKeys = []update.TrustedKey{update.NewTrustedKey(k.public)}
	t.Cleanup(func() { update.TrustedKeys = old })
}

// Sign returns an armored SSH signature of data in the namespace loupe-release.
func (k *Key) Sign(data []byte) []byte {
	return k.SignIn(data, "loupe-release", "sha512")
}

// SignIn is Sign with a chosen namespace and hash name.
func (k *Key) SignIn(data []byte, namespace, hash string) []byte {
	digest := sha512.Sum512(data)
	signed := []byte("SSHSIG")
	signed = str(signed, []byte(namespace))
	signed = str(signed, nil)
	signed = str(signed, []byte(hash))
	signed = str(signed, digest[:])
	raw := ed25519.Sign(k.private, signed)

	pub := str(str(nil, []byte("ssh-ed25519")), k.public)
	sig := str(str(nil, []byte("ssh-ed25519")), raw)
	blob := []byte("SSHSIG")
	blob = binary.BigEndian.AppendUint32(blob, 1)
	blob = str(blob, pub)
	blob = str(blob, []byte(namespace))
	blob = str(blob, nil)
	blob = str(blob, []byte(hash))
	blob = str(blob, sig)

	return pem.EncodeToMemory(&pem.Block{Type: "SSH SIGNATURE", Bytes: blob})
}

func wireKey(public ed25519.PublicKey) []byte {
	return str(str(nil, []byte("ssh-ed25519")), public)
}

func str(out, s []byte) []byte {
	out = binary.BigEndian.AppendUint32(out, uint32(len(s)))

	return append(out, s...)
}
