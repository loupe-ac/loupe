package update

import (
	"bytes"
	"crypto/ed25519"
	"crypto/sha256"
	"crypto/sha512"
	_ "embed"
	"encoding/base64"
	"encoding/binary"
	"encoding/pem"
	"errors"
	"fmt"
	"slices"
	"strings"
)

// Asset names and the signature rules of a release. The namespace and the hash
// match what `ssh-keygen -Y sign -n loupe-release` writes.
const (
	// SignatureName is the asset that holds the signature of checksums.txt.
	SignatureName = "checksums.txt.sig"

	sigNamespace = "loupe-release"
	sigHash      = "sha512"
	sigMagic     = "SSHSIG"
	sigPEMType   = "SSH SIGNATURE"
	keyType      = "ssh-ed25519"
	// maxSignature caps the size of a signature file before it is parsed.
	maxSignature = 8 << 10
)

var (
	// ErrBadSignature marks a signature file that is damaged, or that does not
	// cover the file.
	ErrBadSignature = errors.New("the signature is not valid")
	// ErrUntrustedKey marks a signature made by a key that is not a release key.
	ErrUntrustedKey = errors.New("the signature was made by a key that is not a release key")
	// ErrKeyRevoked marks a signature made by a key that the server revoked.
	ErrKeyRevoked = errors.New("the signature was made by a revoked release key")
)

//go:embed release_keys
var releaseKeys string

// TrustedKey is one release key.
type TrustedKey struct {
	key         ed25519.PublicKey
	Fingerprint string
}

// extraKeys holds more keys, in the same format. Only an end-to-end test sets
// it, through a linker flag when it builds a binary. A release build leaves it
// empty.
var extraKeys string

// TrustedKeys are the release keys the CLI trusts. A test replaces them.
var TrustedKeys = mustParseKeys(releaseKeys + "\n" + extraKeys)

func mustParseKeys(text string) []TrustedKey {
	keys, err := ParseKeys(text)
	if err != nil {
		panic("release_keys: " + err.Error())
	}

	return keys
}

// NewTrustedKey makes a trusted key from an ed25519 public key.
func NewTrustedKey(key ed25519.PublicKey) TrustedKey {
	return TrustedKey{key: key, Fingerprint: Fingerprint(wireKey(key))}
}

// ParseKeys reads keys in the allowed_signers format of ssh-keygen: one key for
// each line, as principal, key type and base64 key. Blank lines and lines that
// start with # are skipped. Only ed25519 keys are accepted.
func ParseKeys(text string) ([]TrustedKey, error) {
	var keys []TrustedKey
	for line := range strings.Lines(text) {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) < 3 || fields[1] != keyType {
			return nil, fmt.Errorf("unsupported key line %q", line)
		}
		blob, err := base64.StdEncoding.DecodeString(fields[2])
		if err != nil {
			return nil, fmt.Errorf("key line %q: %w", line, err)
		}
		key, err := readWireKey(blob)
		if err != nil {
			return nil, fmt.Errorf("key line %q: %w", line, err)
		}
		keys = append(keys, TrustedKey{key: key, Fingerprint: Fingerprint(blob)})
	}
	if len(keys) == 0 {
		return nil, errors.New("no key")
	}

	return keys, nil
}

// Fingerprint is the form `ssh-keygen -l` prints for a public key blob.
func Fingerprint(blob []byte) string {
	sum := sha256.Sum256(blob)

	return "SHA256:" + base64.RawStdEncoding.EncodeToString(sum[:])
}

// VerifySignature checks that sig, an SSH signature in the namespace
// loupe-release, covers checksums and was made by a trusted key that is not in
// revoked. It returns the fingerprint of that key.
func VerifySignature(checksums, sig []byte, revoked []string) (string, error) {
	return verifySignature(TrustedKeys, checksums, sig, revoked)
}

func verifySignature(trusted []TrustedKey, checksums, sig []byte, revoked []string) (string, error) {
	if len(sig) == 0 || len(sig) > maxSignature {
		return "", fmt.Errorf("%w: unexpected size", ErrBadSignature)
	}
	block, rest := pem.Decode(sig)
	if block == nil || block.Type != sigPEMType || len(block.Headers) != 0 || len(bytes.TrimSpace(rest)) != 0 {
		return "", fmt.Errorf("%w: not one SSH signature", ErrBadSignature)
	}
	blob := block.Bytes
	if !bytes.HasPrefix(blob, []byte(sigMagic)) {
		return "", fmt.Errorf("%w: wrong magic", ErrBadSignature)
	}
	r := &reader{b: blob[len(sigMagic):]}
	version, ok := r.uint32()
	if !ok || version != 1 {
		return "", fmt.Errorf("%w: unsupported version", ErrBadSignature)
	}
	pub, ok1 := r.string()
	namespace, ok2 := r.string()
	reserved, ok3 := r.string()
	hash, ok4 := r.string()
	sigBlob, ok5 := r.string()
	if !ok1 || !ok2 || !ok3 || !ok4 || !ok5 || len(r.b) != 0 {
		return "", fmt.Errorf("%w: damaged or trailing data", ErrBadSignature)
	}
	if string(namespace) != sigNamespace {
		return "", fmt.Errorf("%w: namespace %q", ErrBadSignature, namespace)
	}
	if string(hash) != sigHash {
		return "", fmt.Errorf("%w: hash %q", ErrBadSignature, hash)
	}
	key, err := readWireKey(pub)
	if err != nil {
		return "", fmt.Errorf("%w: %v", ErrBadSignature, err)
	}
	inner := &reader{b: sigBlob}
	algo, ok1 := inner.string()
	raw, ok2 := inner.string()
	if !ok1 || !ok2 || len(inner.b) != 0 || string(algo) != keyType || len(raw) != ed25519.SignatureSize {
		return "", fmt.Errorf("%w: damaged signature", ErrBadSignature)
	}

	fingerprint := Fingerprint(pub)
	if !slices.ContainsFunc(trusted, func(t TrustedKey) bool { return t.key.Equal(key) }) {
		return "", fmt.Errorf("%w: %s", ErrUntrustedKey, fingerprint)
	}
	if slices.Contains(revoked, fingerprint) {
		return "", fmt.Errorf("%w: %s", ErrKeyRevoked, fingerprint)
	}
	if !ed25519.Verify(key, signedData(checksums, namespace, reserved, hash), raw) {
		return "", fmt.Errorf("%w: it does not cover the file", ErrBadSignature)
	}

	return fingerprint, nil
}

// signedData is the message that the key signs: the preamble and the hash of
// the file.
func signedData(file, namespace, reserved, hash []byte) []byte {
	digest := sha512.Sum512(file)
	var out []byte
	out = append(out, sigMagic...)
	out = appendString(out, namespace)
	out = appendString(out, reserved)
	out = appendString(out, hash)

	return appendString(out, digest[:])
}

func wireKey(key ed25519.PublicKey) []byte {
	return appendString(appendString(nil, []byte(keyType)), key)
}

func readWireKey(blob []byte) (ed25519.PublicKey, error) {
	r := &reader{b: blob}
	algo, ok1 := r.string()
	key, ok2 := r.string()
	if !ok1 || !ok2 || len(r.b) != 0 || string(algo) != keyType || len(key) != ed25519.PublicKeySize {
		return nil, errors.New("not an ed25519 key")
	}

	return ed25519.PublicKey(key), nil
}

func appendString(out, s []byte) []byte {
	out = binary.BigEndian.AppendUint32(out, uint32(len(s)))

	return append(out, s...)
}

// reader reads the length-prefixed fields of the SSH wire format.
type reader struct{ b []byte }

func (r *reader) uint32() (uint32, bool) {
	if len(r.b) < 4 {
		return 0, false
	}
	v := binary.BigEndian.Uint32(r.b)
	r.b = r.b[4:]

	return v, true
}

func (r *reader) string() ([]byte, bool) {
	n, ok := r.uint32()
	if !ok || uint64(n) > uint64(len(r.b)) {
		return nil, false
	}
	s := r.b[:n]
	r.b = r.b[n:]

	return s, true
}
