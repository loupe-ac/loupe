package cmd

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"mime/multipart"
	"net/http"
	"net/textproto"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"unicode"
	"unicode/utf8"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/artifactcompile"
	"github.com/loupe-ac/loupe/cli/internal/output"
	"github.com/loupe-ac/loupe/cli/internal/projectfile"
	"github.com/loupe-ac/loupe/cli/internal/publicapi"
	"github.com/spf13/cobra"
)

// uploadFlags are the flags of an artifact upload that the generic builder
// does not know.
type uploadFlags struct {
	publicFlags
	name, sourceLink, notes, card, index, out string
}

func newArtifactUploadLeaf(c publicapi.Command, op publicapi.Operation) *cobra.Command {
	var f uploadFlags
	creates := hasBodyField(op, "name")

	use := c.Path[len(c.Path)-1]
	for _, a := range op.PathArgs {
		use += " <" + a + ">"
	}
	use += " <path>"

	long := op.Summary + ".\n\n" +
		"Uploads a file, or every file of a folder. A file is stored under its base name. " +
		"A folder is stored with the path of each file relative to the folder, and the command " +
		"skips a .git folder. It refuses an empty folder, a file that is not a regular file, " +
		"and a push of more than 8 MiB.\n\n" +
		"A folder with a Design canvas, a .dc.html file from a Claude Design artifact or an older " +
		"Claude Design project, is compiled to plain pages before it is stored. One canvas becomes " +
		"index.html. With several canvases, --index names the one that becomes index.html, by its " +
		"path with or without .dc.html, or by its base name. The others become <name>.html. Blob " +
		"files stay in the folder as _blob/<id>.<ext>.\n\n" +
		"--card links the artifact to a card, by its number or its id. Any credential can store, " +
		"also the credential of a worker run.\n\n" + betaNote

	args := len(op.PathArgs) + 1
	cmd := &cobra.Command{
		Use:   use,
		Short: op.Summary,
		Long:  long,
		Args: func(cmd *cobra.Command, a []string) error {
			if err := cobra.ExactArgs(args)(cmd, a); err != nil {
				return &exitError{exitUsage, err}
			}

			return nil
		},
		RunE: func(cmd *cobra.Command, a []string) error {
			return runArtifactUpload(cmd, c, op, a, &f)
		},
	}
	cmd.SetFlagErrorFunc(usageFlagError)

	flags := cmd.Flags()
	flags.StringVar(&f.format, "format", "", "output format: table, tsv, json or toon (default table on a terminal, else tsv)")
	flags.StringSliceVar(&f.fields, "fields", nil, "the fields to print, as a comma list; `all` prints every field")
	flags.BoolVar(&f.retry, "retry", false, "wait for Retry-After on a 429 answer and send the request again, once")
	flags.StringVar(&f.project, "project", "", "project id or slug (else "+projectEnv+", the project link, or "+projectfile.Name+")")
	flags.StringVar(&f.idem, "idempotency-key", "", "a key of your choice; a retry with the same key and files answers as the first request did (else "+seedEnv+" gives one)")
	if creates {
		flags.StringVar(&f.name, "name", "", "the name of the new artifact")
		flags.StringVar(&f.sourceLink, "source-link", "", "where the files came from, such as a design canvas link")
	}
	flags.StringVar(&f.notes, "notes", "", "what changed in this version")
	flags.StringVar(&f.card, "card", "", "link the artifact to this card, by `number` or id")
	flags.StringVar(&f.index, "index", "", "the `artboard` that becomes index.html when the folder holds several canvases")
	flags.StringVar(&f.out, "out", "", "write the compiled files to this empty or new `folder` and store nothing")

	return cmd
}

var cardNumber = regexp.MustCompile(`^#?[0-9]+$`)

func runArtifactUpload(cmd *cobra.Command, c publicapi.Command, op publicapi.Operation, args []string, f *uploadFlags) error {
	format := output.Default(isTerminalWriter(cmd.OutOrStdout()))
	if f.format != "" {
		parsed, err := output.Parse(f.format)
		if err != nil {
			return &exitError{exitUsage, err}
		}
		format = parsed
	}
	if err := checkFields(op, f.fields); err != nil {
		return err
	}
	if f.out == "" && hasBodyField(op, "name") && f.name == "" {
		return &exitError{exitUsage, errors.New("missing --name")}
	}
	if f.out != "" {
		if err := checkOutFolder(f.out); err != nil {
			return &exitError{exitUsage, err}
		}
	}

	files, err := collectArtifactFiles(args[len(args)-1])
	if err != nil {
		return err
	}
	compiled, err := artifactcompile.Run(files, artifactcompile.Options{Index: f.index})
	if err != nil {
		return err
	}
	files = compiled.Files
	if f.out != "" {
		return writeCompiled(cmd, f.out, compiled)
	}
	if len(compiled.Gaps) > 0 {
		return gapError(compiled.Gaps)
	}
	if name := fileWithGapMarker(files); name != "" {
		return fmt.Errorf("%s still holds a loupe:gap comment. Finish that gap and remove the comment, then store again", name)
	}

	ctx := commandContext(cmd)
	s, err := publicClient(cmd)
	if err != nil {
		return err
	}
	so := sendOptions{retry: f.retry, jsonErrors: format == output.JSON}

	project, err := publicProject(ctx, s, f.project, so)
	if err != nil {
		return err
	}
	base := "/api/v1/projects/" + url.PathEscape(project)

	card := strings.TrimSpace(f.card)
	if cardNumber.MatchString(card) {
		card, err = cardIDOf(cmd, s, base, strings.TrimPrefix(card, "#"), so)
		if err != nil {
			return err
		}
	}

	path := op.Path
	path = strings.ReplaceAll(path, "{project}", url.PathEscape(project))
	for i, name := range op.PathArgs {
		path = strings.ReplaceAll(path, "{"+name+"}", url.PathEscape(args[i]))
	}

	fields := map[string]string{"name": f.name, "sourceLink": f.sourceLink, "notes": f.notes, "card": card}
	body, contentType, canonical, err := buildUpload(fields, files)
	if err != nil {
		return &exitError{1, err}
	}

	req := api.PublicRequest{Method: op.Method, Path: path, Body: body, Header: http.Header{"Content-Type": {contentType}}}
	if key := retryKey(f.idem, os.Getenv(seedEnv), op.Method, path, canonical); key != "" {
		req.Header.Set(idempotencyKey, key)
	}
	resp, err := s.send(ctx, req, so)
	if err != nil {
		return err
	}

	return printResult(cmd, c, op, &f.publicFlags, format, resp.Body)
}

// cardIDOf turns a card number into the card id that the upload takes.
func cardIDOf(cmd *cobra.Command, s *publicSession, base, number string, so sendOptions) (string, error) {
	resp, err := s.send(commandContext(cmd), api.PublicRequest{
		Method: http.MethodGet,
		Path:   base + "/cards/" + url.PathEscape(number),
		Query:  url.Values{"fields": {"cardId"}},
	}, so)
	if err != nil {
		return "", err
	}
	var card struct {
		CardID string `json:"cardId"`
	}
	if json.Unmarshal(resp.Body, &card) != nil || card.CardID == "" {
		return "", &exitError{1, fmt.Errorf("card %s: the answer holds no cardId", number)}
	}

	return card.CardID, nil
}

// buildUpload makes the multipart body of an upload. The same fields and files
// always give the same bytes, and canonical is the text that stands for them:
// the fields, then the path, the type and the SHA-256 of each file. The retry
// key hashes canonical, so a compile of the same folder gives the same key.
func buildUpload(fields map[string]string, files []api.ArtifactFile) (body []byte, contentType string, canonical []byte, err error) {
	type digest struct {
		Path, Type, SHA256 string
	}
	set := map[string]string{}
	for k, v := range fields {
		if v != "" {
			set[k] = v
		}
	}
	digests := make([]digest, 0, len(files))
	for _, f := range files {
		sum := sha256.Sum256(f.Contents)
		digests = append(digests, digest{f.Path, f.ContentType, hex.EncodeToString(sum[:])})
	}
	canonical, err = json.Marshal([]any{set, digests})
	if err != nil {
		return nil, "", nil, err
	}

	var buf bytes.Buffer
	form := multipart.NewWriter(&buf)
	sum := sha256.Sum256(canonical)
	if err := form.SetBoundary("loupe-" + hex.EncodeToString(sum[:16])); err != nil {
		return nil, "", nil, err
	}
	names := make([]string, 0, len(set))
	for k := range set {
		names = append(names, k)
	}
	slices.Sort(names)
	for _, k := range names {
		if err := form.WriteField(k, set[k]); err != nil {
			return nil, "", nil, err
		}
	}
	for _, f := range files {
		if err := form.WriteField("paths[]", f.Path); err != nil {
			return nil, "", nil, err
		}
	}
	for i, f := range files {
		header := textproto.MIMEHeader{}
		header.Set("Content-Disposition", fmt.Sprintf(`form-data; name="files[]"; filename="file-%d"`, i))
		header.Set("Content-Type", f.ContentType)
		part, err := form.CreatePart(header)
		if err != nil {
			return nil, "", nil, err
		}
		if _, err := part.Write(f.Contents); err != nil {
			return nil, "", nil, err
		}
	}
	if err := form.Close(); err != nil {
		return nil, "", nil, err
	}

	return buf.Bytes(), form.FormDataContentType(), canonical, nil
}

// readFlags are the flags of `artifact read`.
type readFlags struct {
	project, output string
	version         int
	clobber, retry  bool
}

func newArtifactReadLeaf(c publicapi.Command, op publicapi.Operation) *cobra.Command {
	var f readFlags

	use := c.Path[len(c.Path)-1]
	for _, a := range op.PathArgs {
		use += " <" + a + ">"
	}
	cmd := &cobra.Command{
		Use:   use,
		Short: op.Summary,
		Long: op.Summary + ". The answer is the file itself, with no table and no JSON.\n\n" +
			"On a terminal the command prints a text file and refuses any other file: a file is " +
			"text when it is valid UTF-8 and holds no control character except tab, newline and " +
			"carriage return. Name a file with --output to store any file. --output - prints to " +
			"the terminal anyway. In a pipe the command prints the exact bytes.\n\n" + betaNote,
		Args: func(cmd *cobra.Command, a []string) error {
			if err := cobra.ExactArgs(len(op.PathArgs))(cmd, a); err != nil {
				return &exitError{exitUsage, err}
			}

			return nil
		},
		RunE: func(cmd *cobra.Command, a []string) error {
			return runArtifactRead(cmd, op, a, &f)
		},
	}
	cmd.SetFlagErrorFunc(usageFlagError)

	flags := cmd.Flags()
	flags.StringVar(&f.project, "project", "", "project id or slug (else "+projectEnv+", the project link, or "+projectfile.Name+")")
	flags.IntVar(&f.version, "version-number", 0, "the artifact version to read (default the latest)")
	flags.StringVarP(&f.output, "output", "o", "", "write the file to this `path`, or - for stdout")
	flags.BoolVar(&f.clobber, "clobber", false, "let --output replace a file that exists")
	flags.BoolVar(&f.retry, "retry", false, "wait for Retry-After on a 429 answer and send the request again, once")

	return cmd
}

func runArtifactRead(cmd *cobra.Command, op publicapi.Operation, args []string, f *readFlags) error {
	if f.clobber && (f.output == "" || f.output == "-") {
		return &exitError{exitUsage, errors.New("--clobber needs --output with a file path")}
	}
	if f.output != "" && f.output != "-" {
		if info, err := os.Stat(f.output); err == nil && info.IsDir() {
			return &exitError{exitUsage, fmt.Errorf("--output %s is a folder: name the file", f.output)}
		}
	}

	ctx := commandContext(cmd)
	s, err := publicClient(cmd)
	if err != nil {
		return err
	}
	so := sendOptions{retry: f.retry}
	project, err := publicProject(ctx, s, f.project, so)
	if err != nil {
		return err
	}

	path := strings.ReplaceAll(op.Path, "{project}", url.PathEscape(project))
	for i, name := range op.PathArgs {
		path = strings.ReplaceAll(path, "{"+name+"}", url.PathEscape(args[i]))
	}
	query := url.Values{}
	if cmd.Flags().Changed("version-number") {
		query.Set("versionNumber", fmt.Sprint(f.version))
	}
	resp, err := s.send(ctx, api.PublicRequest{Method: op.Method, Path: path, Query: query}, so)
	if err != nil {
		return err
	}

	out := cmd.OutOrStdout()
	switch {
	case f.output == "-":
		_, err = out.Write(resp.Body)
	case f.output != "":
		if err = writeFileOnce(f.output, resp.Body, f.clobber); err == nil {
			fmt.Fprintf(cmd.ErrOrStderr(), "Saved %s.\n", f.output)
		}
	case isTerminalWriter(out) && !isPlainText(resp.Body):
		return &exitError{exitUsage, errors.New("the file is not plain text, and a terminal would show it as it is: write it with --output <path>, or print it anyway with --output -")}
	default:
		_, err = out.Write(resp.Body)
	}
	var coded *exitError
	switch {
	case errors.As(err, &coded):
		return coded
	case err != nil:
		return &exitError{1, err}
	}

	return nil
}

// isPlainText is true for valid UTF-8 with no control character other than
// tab, newline and carriage return.
func isPlainText(data []byte) bool {
	if !utf8.Valid(data) {
		return false
	}
	for _, r := range string(data) {
		if unicode.IsControl(r) && r != '\t' && r != '\n' && r != '\r' {
			return false
		}
	}

	return true
}

// writeFileOnce writes data to target through a temporary file in the same
// folder. Without clobber, a hard link makes the target and fails when it
// exists, so two runs cannot both win. With clobber, a rename replaces it.
// Each failure removes the temporary file and leaves the target as it was.
func writeFileOnce(target string, data []byte, clobber bool) error {
	dir := filepath.Dir(target)
	tmp, err := createTemp(dir)
	if err != nil {
		return err
	}
	name := tmp.Name()
	defer os.Remove(name)

	if _, err := tmp.Write(data); err != nil {
		tmp.Close()

		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}

	if clobber {
		return os.Rename(name, target)
	}
	if err := os.Link(name, target); err != nil {
		if errors.Is(err, fs.ErrExist) {
			return &exitError{exitUsage, fmt.Errorf("%s exists: pass --clobber to replace it", target)}
		}

		return err
	}

	return nil
}

// createTemp makes a new file in dir with the normal mode for a new file, so
// the umask applies.
func createTemp(dir string) (*os.File, error) {
	for range 10 {
		var b [8]byte
		if _, err := rand.Read(b[:]); err != nil {
			return nil, err
		}
		f, err := os.OpenFile(filepath.Join(dir, ".loupe-"+hex.EncodeToString(b[:])+".tmp"), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o666)
		if errors.Is(err, fs.ErrExist) {
			continue
		}

		return f, err
	}

	return nil, errors.New("cannot make a temporary file")
}

func commandContext(cmd *cobra.Command) context.Context {
	if ctx := cmd.Context(); ctx != nil {
		return ctx
	}

	return context.Background()
}
