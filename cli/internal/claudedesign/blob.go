package claudedesign

import (
	"path"
	"path/filepath"
	"regexp"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/api"
)

const blobDir = "_blob/"

// blobRe finds a raw /_blob/ path. A path that follows a dot or a word
// character, such as ../_blob/x.png, is a relative link and does not match.
var blobRe = regexp.MustCompile(`(^|[^.\w])/_blob/([^\s"'()<>,;\\?#]*)`)

// blobFiles maps the id and the file name of each pushed file under _blob/ to
// its path.
type blobFiles map[string][]string

func collectBlobs(files []api.ArtifactFile) blobFiles {
	out := blobFiles{}
	for _, f := range files {
		name, ok := strings.CutPrefix(f.Path, blobDir)
		if !ok || name == "" || strings.Contains(name, "/") {
			continue
		}
		id := strings.TrimSuffix(name, path.Ext(name))
		out[id] = append(out[id], f.Path)
		if id != name {
			out[name] = append(out[name], f.Path)
		}
	}

	return out
}

// rewriteBlobs points each /_blob/ path of s at the pushed blob file, as a link
// relative to the folder of the page. A path that cannot resolve is a problem,
// and then s comes back unchanged.
func (p *page) rewriteBlobs(s string) (string, []problem) {
	if !strings.Contains(s, "/_blob/") {
		return s, nil
	}
	var problems []problem
	var out strings.Builder
	last := 0
	for _, m := range blobRe.FindAllStringSubmatchIndex(s, -1) {
		lead, ref := s[m[2]:m[3]], s[m[4]:m[5]]
		var target string
		switch files := p.blobs[ref]; {
		case ref == "" || strings.Contains(ref, "..") || strings.Contains(ref, "/"):
			problems = append(problems, problem{"a /_blob/ path that is not a plain id", "/_blob/" + ref})
		case len(files) == 0:
			problems = append(problems, problem{"a blob file that is not in the push", "/_blob/" + ref + " has no file " + blobDir + ref + " or " + blobDir + ref + ".<ext> in the push"})
		case len(files) > 1:
			problems = append(problems, problem{"a blob id that more than one file has", "/_blob/" + ref + " matches " + strings.Join(files, ", ")})
		default:
			target = files[0]
		}
		out.WriteString(s[last:m[2]])
		out.WriteString(lead)
		if target == "" {
			out.WriteString(s[m[2]+len(lead) : m[1]])
		} else {
			out.WriteString(relTo(path.Dir(p.file), target))
		}
		last = m[1]
	}
	if len(problems) > 0 {
		return s, problems
	}
	out.WriteString(s[last:])

	return out.String(), nil
}

// blobMap maps each blob id that resolves to one file to its link from the
// folder of the page, as rewriteBlobs points it.
func (p *page) blobMap() map[string]string {
	out := map[string]string{}
	for id, files := range p.blobs {
		if len(files) == 1 && !strings.Contains(id, "..") {
			out[id] = relTo(path.Dir(p.file), files[0])
		}
	}

	return out
}

// relTo gives the link from a folder to a path of the push.
func relTo(dir, target string) string {
	rel, err := filepath.Rel(filepath.FromSlash(dir), filepath.FromSlash(target))
	if err != nil {
		return target
	}

	return filepath.ToSlash(rel)
}
