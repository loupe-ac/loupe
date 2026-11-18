package update

import (
	"context"
	"net/http"
)

// Signed is a candidate whose checksums.txt a trusted key signed.
type Signed struct {
	Candidate
	// ChecksumsData is the verified checksums.txt.
	ChecksumsData []byte
	// Signer is the fingerprint of the key that signed it.
	Signer string
	// Reads counts the candidates whose signature the pick read.
	Reads int
}

// PickSigned walks the candidates that keep accepts, the highest first. For
// each it downloads checksums.txt and its signature and checks the signature
// against the trusted keys, with revoked keys refused. It returns the first
// candidate that verifies. rejected, when set, receives each candidate whose
// signature failed. A failed download ends the walk with an error, because a
// network failure says nothing about the signature. found is false when no
// candidate verifies.
func PickSigned(ctx context.Context, hc *http.Client, releases []Release, goos, goarch string, keep func(Version) bool, revoked []string, rejected func(Candidate, error)) (Signed, bool, error) {
	reads := 0
	for _, c := range CandidatesWhere(releases, goos, goarch, keep) {
		checksums, err := Download(ctx, hc, c.Checksums.URL)
		if err != nil {
			return Signed{Reads: reads}, false, err
		}
		sig, err := Download(ctx, hc, c.Signature.URL)
		if err != nil {
			return Signed{Reads: reads}, false, err
		}
		reads++
		signer, err := VerifySignature(checksums, sig, revoked)
		if err != nil {
			if rejected != nil {
				rejected(c, err)
			}

			continue
		}

		return Signed{Candidate: c, ChecksumsData: checksums, Signer: signer, Reads: reads}, true, nil
	}

	return Signed{Reads: reads}, false, nil
}

// LoadRevoked reads the revoked key fingerprints of update.json in dir, with
// the keys that this process revoked even when it could not save them. An
// unreadable file gives only the latter.
func LoadRevoked(dir string) []string {
	st, err := LoadState(dir)
	if err != nil {
		return heldKeys(dir)
	}

	return mergeKeys(st.Revoked, heldKeys(dir))
}
