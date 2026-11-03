package api

// ArtifactFile is one file of a version to store. Path is relative, with
// slashes, and ContentType is the type the server keeps for it.
type ArtifactFile struct {
	Path        string
	ContentType string
	Contents    []byte
}
