package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"slices"
	"strconv"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/api"
	"github.com/loupe-ac/loupe/cli/internal/output"
	"github.com/loupe-ac/loupe/cli/internal/publicapi"
	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
	"golang.org/x/term"
)

// groupShort is the help line of each command group the builder makes.
var groupShort = map[string]string{
	"project":  "Read and change the projects of an account",
	"column":   "Read and change the columns of a board",
	"card":     "Read and change the cards of a project",
	"feedback": "Read and answer the feedback of a project",
	"document": "Read and change the documents of a project",
	"version":  "Read the versions of a document",
	"artifact": "Read and store the artifacts of a project",
}

const betaNote = "This command is in beta. Its name, its flags and its output can change in a minor release."

// addPublicCommands adds one command for each entry of publicapi.Commands. A
// group that exists already, such as `project`, keeps its own commands.
func addPublicCommands(root *cobra.Command) {
	for _, c := range publicapi.Commands {
		op, ok := publicapi.Lookup(c.Op)
		if !ok {
			continue
		}
		parent := root
		for _, name := range c.Path[:len(c.Path)-1] {
			parent = publicGroup(parent, name)
		}
		parent.AddCommand(newPublicLeaf(c, op))
	}
}

// publicGroup finds the group called name under parent, or makes it. A group
// answers an unknown word with exit 2. Without a RunE, Cobra prints the help
// and exits 0.
func publicGroup(parent *cobra.Command, name string) *cobra.Command {
	var group *cobra.Command
	for _, sub := range parent.Commands() {
		if sub.Name() == name {
			group = sub

			break
		}
	}
	if group == nil {
		short := groupShort[name]
		if short == "" {
			short = "Work with " + name
		}
		group = &cobra.Command{Use: name, Short: short + " (beta)"}
		parent.AddCommand(group)
	}
	if group.Long == "" {
		group.Long = group.Short + "\n\n" + betaNote
	}
	if group.RunE == nil && group.Run == nil {
		group.Args = cobra.ArbitraryArgs
		group.RunE = func(cmd *cobra.Command, args []string) error {
			if len(args) > 0 {
				return &exitError{exitUsage, fmt.Errorf("unknown command %q for %q", args[0], cmd.CommandPath())}
			}

			return cmd.Help()
		}
	}
	group.SetFlagErrorFunc(usageFlagError)

	return group
}

func usageFlagError(_ *cobra.Command, err error) error {
	return &exitError{exitUsage, err}
}

// publicFlags holds the shared flags of one command.
type publicFlags struct {
	format  string
	fields  []string
	retry   bool
	project string
	query   map[string]func() (string, bool)
	// write reads the body of a write. It is nil for a read.
	write *writeFlags
	idem  string
	yes   bool
}

func newPublicLeaf(c publicapi.Command, op publicapi.Operation) *cobra.Command {
	var f publicFlags
	f.query = map[string]func() (string, bool){}

	use := c.Path[len(c.Path)-1]
	for _, a := range op.PathArgs {
		use += " <" + a + ">"
	}
	long := op.Summary + "."
	if op.Description != "" {
		long += "\n\n" + op.Description
	}
	long += "\n\n" + betaNote

	cmd := &cobra.Command{
		Use:   use,
		Short: op.Summary,
		Long:  long,
		Args: func(cmd *cobra.Command, args []string) error {
			if err := cobra.ExactArgs(len(op.PathArgs))(cmd, args); err != nil {
				return &exitError{exitUsage, err}
			}

			return nil
		},
		RunE: func(cmd *cobra.Command, args []string) error {
			return runPublic(cmd, c, op, args, &f)
		},
	}
	cmd.SetFlagErrorFunc(usageFlagError)

	flags := cmd.Flags()
	flags.StringVar(&f.format, "format", "", "output format: table, tsv, json or toon (default table on a terminal, else tsv)")
	flags.BoolVar(&f.retry, "retry", false, "wait for Retry-After on a 429 answer and send the request again, once")
	if hasAnswerFields(op) {
		flags.StringSliceVar(&f.fields, "fields", nil, "the fields to print, as a comma list; `all` prints every field")
	}
	if op.HasProject && !slices.Contains(op.PathArgs, "project") {
		flags.StringVar(&f.project, "project", "", "project id or slug (else "+projectEnv+", the project link, or "+".loupe.yaml)")
	}
	for _, p := range op.Query {
		addQueryFlag(flags, &f, p)
	}
	if len(op.Body) > 0 && !op.Multipart {
		f.write = addWriteFlags(flags, c, op)
	}
	if op.Idempotent {
		flags.StringVar(&f.idem, "idempotency-key", "", "a key of your choice; a retry with the same key and body answers as the first request did (else "+seedEnv+" gives one)")
	}
	if c.Confirm {
		flags.BoolVar(&f.yes, "yes", false, "send without asking for a confirmation")
	}

	return cmd
}

func hasAnswerFields(op publicapi.Operation) bool {
	return op.TakesFields || len(op.AnswerFields) > 0
}

func flagName(s string) string {
	var b strings.Builder
	for i, r := range s {
		if r >= 'A' && r <= 'Z' {
			if i > 0 {
				b.WriteByte('-')
			}
			r += 'a' - 'A'
		}
		b.WriteRune(r)
	}

	return b.String()
}

func addQueryFlag(flags *pflag.FlagSet, f *publicFlags, p publicapi.Param) {
	name := flagName(p.Name)
	usage := strings.TrimSpace(p.Description)
	if len(p.Enum) > 0 {
		usage += " One of: " + strings.Join(p.Enum, ", ") + "."
	}
	if p.Name == "cursor" {
		usage = "the cursor of the next page, or the `next` address of the last page"
	}

	switch {
	case p.Type == "integer":
		v := flags.Int(name, 0, usage)
		f.query[p.Name] = func() (string, bool) { return strconv.Itoa(*v), flags.Changed(name) }
	case p.Type == "number":
		v := flags.Float64(name, 0, usage)
		f.query[p.Name] = func() (string, bool) { return strconv.FormatFloat(*v, 'f', -1, 64), flags.Changed(name) }
	case p.Type == "boolean":
		v := flags.Bool(name, false, usage)
		f.query[p.Name] = func() (string, bool) { return strconv.FormatBool(*v), flags.Changed(name) }
	case p.Type == "array":
		v := flags.StringSlice(name, nil, usage)
		f.query[p.Name] = func() (string, bool) { return strings.Join(*v, ","), flags.Changed(name) }
	default:
		v := flags.String(name, "", usage)
		f.query[p.Name] = func() (string, bool) { return *v, flags.Changed(name) }
	}
}

// isListOp tells a list from one item: a list takes a cursor.
func isListOp(op publicapi.Operation) bool {
	return slices.ContainsFunc(op.Query, func(p publicapi.Param) bool { return p.Name == "cursor" })
}

// cursorValue reads the value of --cursor. A whole `next` address gives its
// `cursor` query value, and the CLI never requests that address.
func cursorValue(v string) (string, error) {
	if !strings.HasPrefix(v, "http://") && !strings.HasPrefix(v, "https://") {
		return v, nil
	}
	u, err := url.Parse(v)
	if err != nil {
		return "", fmt.Errorf("--cursor: %w", err)
	}
	cursor := u.Query().Get("cursor")
	if cursor == "" {
		return "", errors.New("--cursor: the address holds no cursor value")
	}

	return cursor, nil
}

// isTerminalWriter and terminalWidth are variables, so a test can stand in for
// a terminal.
var isTerminalWriter = func(w io.Writer) bool {
	f, ok := w.(*os.File)

	return ok && term.IsTerminal(int(f.Fd()))
}

var terminalWidth = func(w io.Writer) int {
	f, ok := w.(*os.File)
	if !ok {
		return 0
	}
	width, _, err := term.GetSize(int(f.Fd()))
	if err != nil {
		return 0
	}

	return width
}

// checkFields refuses a field name that the answer does not hold, before a
// request goes out. An operation that takes fields leaves the check to the API.
func checkFields(op publicapi.Operation, names []string) error {
	if op.TakesFields || len(op.AnswerFields) == 0 {
		return nil
	}
	for _, n := range names {
		if n != "all" && !slices.Contains(op.AnswerFields, n) {
			return &exitError{exitUsage, fmt.Errorf("--fields: %q is no field of the answer; use %s", n, strings.Join(op.AnswerFields, ", "))}
		}
	}

	return nil
}

// renderHooks let one command print its own table.
var renderHooks = map[string]func(cmd *cobra.Command, format output.Format, body []byte, fieldsSet bool) (bool, error){
	"listProjects": renderProjectList,
}

func runPublic(cmd *cobra.Command, c publicapi.Command, op publicapi.Operation, args []string, f *publicFlags) error {
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
	if err := checkRequiredQuery(op, f); err != nil {
		return err
	}
	var body []byte
	if f.write != nil {
		var err error
		if body, err = buildBody(cmd, c, op, f.write); err != nil {
			return err
		}
	}
	if c.Confirm {
		if err := confirmSend(cmd, op, args, f.yes); err != nil {
			return err
		}
	}

	query := url.Values{}
	for _, p := range op.Query {
		v, set := f.query[p.Name]()
		if !set {
			continue
		}
		if p.Name == "cursor" {
			var err error
			if v, err = cursorValue(v); err != nil {
				return &exitError{exitUsage, err}
			}
		}
		query.Set(p.Name, v)
	}
	switch {
	case len(f.fields) > 0 && op.TakesFields:
		query.Set("fields", strings.Join(f.fields, ","))
	case op.TakesFields && isListOp(op) && len(c.Columns) > 0 && (format == output.Table || format == output.TSV):
		query.Set("fields", strings.Join(c.Columns, ","))
	}

	ctx := cmd.Context()
	if ctx == nil {
		ctx = context.Background()
	}
	s, err := publicClient(cmd)
	if err != nil {
		return err
	}
	so := sendOptions{retry: f.retry, jsonErrors: format == output.JSON}

	path := op.Path
	if op.HasProject && !slices.Contains(op.PathArgs, "project") {
		project, err := publicProject(ctx, s, f.project, so)
		if err != nil {
			return err
		}
		path = strings.ReplaceAll(path, "{project}", url.PathEscape(project))
	}
	for i, name := range op.PathArgs {
		path = strings.ReplaceAll(path, "{"+name+"}", url.PathEscape(args[i]))
	}

	req := api.PublicRequest{Method: op.Method, Path: path, Query: query, Body: body}
	if op.Idempotent {
		if key := retryKey(f.idem, os.Getenv(seedEnv), op.Method, path, body); key != "" {
			req.Header = http.Header{idempotencyKey: {key}}
		}
	}
	resp, err := s.send(ctx, req, so)
	if err != nil {
		return err
	}

	out := cmd.OutOrStdout()
	if isWrite(op) {
		if f.format == "" && len(f.fields) == 0 {
			writeShort(cmd, op, resp.Body)

			return nil
		}
		if len(bytes.TrimSpace(resp.Body)) == 0 {
			return nil
		}
	}
	if hook := renderHooks[op.ID]; hook != nil {
		handled, err := hook(cmd, format, resp.Body, len(f.fields) > 0)
		if handled || err != nil {
			return err
		}
	}
	opts := output.Options{
		Fields:     f.fields,
		Columns:    c.Columns,
		LongText:   c.LongText,
		TimeFields: op.TimeFields,
		Width:      terminalWidth(out),
	}
	if err := output.Write(out, format, resp.Body, opts); err != nil {
		return &exitError{1, err}
	}
	if format == output.Table && isTerminalWriter(out) && output.IsEmptyList(resp.Body) {
		fmt.Fprintln(cmd.ErrOrStderr(), emptyListNote)
	}
	if format == output.Table || format == output.TSV {
		noteNextPage(cmd.ErrOrStderr(), resp.Body)
	}

	return nil
}

const emptyListNote = "This list has no items."

// noteNextPage tells on stderr how to ask for the next page of a list.
func noteNextPage(w io.Writer, body []byte) {
	var page struct {
		Next *string `json:"next"`
	}
	if json.Unmarshal(body, &page) != nil || page.Next == nil || *page.Next == "" {
		return
	}
	cursor, err := cursorValue(*page.Next)
	if err != nil || cursor == *page.Next {
		return
	}
	fmt.Fprintf(w, "More rows: pass --cursor %s\n", cursor)
}
