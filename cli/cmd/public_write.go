package cmd

import (
	"bufio"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"strconv"
	"strings"

	"github.com/loupe-ac/loupe/cli/internal/publicapi"
	"github.com/spf13/cobra"
	"github.com/spf13/pflag"
	"golang.org/x/term"
)

const (
	seedEnv        = "LOUPE_IDEMPOTENCY_SEED"
	idempotencyKey = "Idempotency-Key"
)

// writeFlags reads the body fields of a write from its flags.
type writeFlags struct {
	input    string
	bodyFile string
	// fields reads one body field. It reports whether the flag was set.
	fields map[string]func() (any, bool, error)
}

// addWriteFlags gives each body field a flag by its type, and adds --input and
// --body-file.
func addWriteFlags(flags *pflag.FlagSet, c publicapi.Command, op publicapi.Operation) *writeFlags {
	w := &writeFlags{fields: map[string]func() (any, bool, error){}}
	flags.StringVar(&w.input, "input", "", "a file with the JSON object of the request body, or - for stdin; the flags win over it")
	if c.LongText != "" && hasBodyField(op, c.LongText) {
		flags.StringVar(&w.bodyFile, "body-file", "", "a file that fills --"+flagName(c.LongText)+", or - for stdin")
	}
	for _, p := range op.Body {
		addBodyFlag(flags, w, p)
	}

	return w
}

func hasBodyField(op publicapi.Operation, name string) bool {
	for _, p := range op.Body {
		if p.Name == name {
			return true
		}
	}

	return false
}

func bodyUsage(p publicapi.Param) string {
	usage := strings.TrimSpace(p.Description)
	if len(p.Enum) > 0 {
		usage += " One of: " + strings.Join(p.Enum, ", ") + "."
	}
	if p.Nullable {
		usage += " Pass null to send null."
	}
	if p.Type == "object" || p.Items == "object" {
		usage += " Takes JSON text."
	}

	return strings.TrimSpace(usage)
}

func addBodyFlag(flags *pflag.FlagSet, w *writeFlags, p publicapi.Param) {
	name := flagName(p.Name)
	usage := bodyUsage(p)
	flag := "--" + name

	switch {
	case p.Nullable:
		v := flags.String(name, "", usage)
		w.fields[p.Name] = func() (any, bool, error) {
			if !flags.Changed(name) {
				return nil, false, nil
			}
			if *v == "null" {
				return nil, true, nil
			}
			out, err := parseScalar(flag, p.Type, *v)

			return out, true, err
		}
	case p.Type == "integer":
		v := flags.Int(name, 0, usage)
		w.fields[p.Name] = func() (any, bool, error) { return *v, flags.Changed(name), nil }
	case p.Type == "number":
		v := flags.Float64(name, 0, usage)
		w.fields[p.Name] = func() (any, bool, error) { return *v, flags.Changed(name), nil }
	case p.Type == "boolean":
		v := flags.Bool(name, false, usage)
		w.fields[p.Name] = func() (any, bool, error) { return *v, flags.Changed(name), nil }
	case p.Type == "array" && p.Items != "object" && p.Items != "array":
		v := flags.StringSlice(name, nil, usage)
		w.fields[p.Name] = func() (any, bool, error) {
			if !flags.Changed(name) {
				return nil, false, nil
			}
			list := make([]any, 0, len(*v))
			for _, item := range *v {
				out, err := parseScalar(flag, p.Items, item)
				if err != nil {
					return nil, true, err
				}
				list = append(list, out)
			}

			return list, true, nil
		}
	case p.Type == "array" || p.Type == "object":
		v := flags.String(name, "", usage)
		w.fields[p.Name] = func() (any, bool, error) {
			if !flags.Changed(name) {
				return nil, false, nil
			}
			out, err := parseJSONText(flag, *v)

			return out, true, err
		}
	default:
		v := flags.String(name, "", usage)
		w.fields[p.Name] = func() (any, bool, error) { return *v, flags.Changed(name), nil }
	}
}

func parseScalar(flag, typ, v string) (any, error) {
	switch typ {
	case "integer":
		n, err := strconv.Atoi(v)
		if err != nil {
			return nil, &exitError{exitUsage, fmt.Errorf("%s: %q is no integer", flag, v)}
		}

		return n, nil
	case "number":
		n, err := strconv.ParseFloat(v, 64)
		if err != nil {
			return nil, &exitError{exitUsage, fmt.Errorf("%s: %q is no number", flag, v)}
		}

		return n, nil
	case "boolean":
		b, err := strconv.ParseBool(v)
		if err != nil {
			return nil, &exitError{exitUsage, fmt.Errorf("%s: %q is no boolean", flag, v)}
		}

		return b, nil
	}

	return v, nil
}

func parseJSONText(flag, v string) (any, error) {
	dec := json.NewDecoder(strings.NewReader(v))
	dec.UseNumber()
	var out any
	if err := dec.Decode(&out); err != nil || dec.More() {
		return nil, &exitError{exitUsage, fmt.Errorf("%s: the value is no valid JSON text", flag)}
	}

	return out, nil
}

// readSource reads a file, or stdin for "-".
func readSource(cmd *cobra.Command, flag, path string) ([]byte, error) {
	var data []byte
	var err error
	if path == "-" {
		data, err = io.ReadAll(cmd.InOrStdin())
	} else {
		data, err = os.ReadFile(path)
	}
	if err != nil {
		return nil, &exitError{exitUsage, fmt.Errorf("%s: %w", flag, err)}
	}

	return data, nil
}

// buildBody merges --input, then --body-file, then the flags, and checks the
// required fields. It returns the canonical JSON bytes: encoding/json sorts the
// keys of a map, so the same fields always give the same bytes.
func buildBody(cmd *cobra.Command, c publicapi.Command, op publicapi.Operation, w *writeFlags) ([]byte, error) {
	if w.input == "-" && w.bodyFile == "-" {
		return nil, &exitError{exitUsage, errors.New("--input and --body-file cannot both read stdin")}
	}

	body := map[string]any{}
	if w.input != "" {
		data, err := readSource(cmd, "--input", w.input)
		if err != nil {
			return nil, err
		}
		dec := json.NewDecoder(bytes.NewReader(data))
		dec.UseNumber()
		if err := dec.Decode(&body); err != nil || body == nil || dec.More() {
			return nil, &exitError{exitUsage, errors.New("--input: the content is no JSON object")}
		}
	}

	if w.bodyFile != "" {
		if _, set, _ := w.fields[c.LongText](); set {
			return nil, &exitError{exitUsage, fmt.Errorf("--body-file and --%s both fill %s: pass one", flagName(c.LongText), c.LongText)}
		}
		data, err := readSource(cmd, "--body-file", w.bodyFile)
		if err != nil {
			return nil, err
		}
		body[c.LongText] = string(data)
	}

	for _, p := range op.Body {
		v, set, err := w.fields[p.Name]()
		if err != nil {
			return nil, err
		}
		if set {
			body[p.Name] = v
		}
	}

	for _, p := range op.Body {
		v, ok := body[p.Name]
		if p.Required && (!ok || (v == nil && !p.Nullable)) {
			return nil, &exitError{exitUsage, fmt.Errorf("missing --%s, or %q in --input", flagName(p.Name), p.Name)}
		}
	}

	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	if err := enc.Encode(body); err != nil {
		return nil, &exitError{1, err}
	}

	return bytes.TrimRight(buf.Bytes(), "\n"), nil
}

// retryKey gives the Idempotency-Key of a request. The flag wins. Else the seed
// gives a key that is the same for the same method, path and body.
func retryKey(flag, seed, method, path string, body []byte) string {
	if flag != "" {
		return flag
	}
	if seed == "" {
		return ""
	}
	h := sha256.New()
	for _, part := range [][]byte{[]byte(seed), []byte(method), []byte(path), body} {
		h.Write(part)
		h.Write([]byte{0})
	}

	return "loupe-seed-" + hex.EncodeToString(h.Sum(nil))
}

// checkRequiredQuery refuses a request that lacks a required query value.
func checkRequiredQuery(op publicapi.Operation, f *publicFlags) error {
	for _, p := range op.Query {
		if _, set := f.query[p.Name](); p.Required && !set {
			return &exitError{exitUsage, fmt.Errorf("missing --%s", queryFlagName(p))}
		}
	}

	return nil
}

// isTerminalReader is a variable, so a test can stand in for a terminal.
var isTerminalReader = func(r io.Reader) bool {
	f, ok := r.(*os.File)

	return ok && term.IsTerminal(int(f.Fd()))
}

// confirmSend asks on stderr before a command sends. It asks only when stdin,
// stdout and stderr are all terminals. Anything else needs --yes.
func confirmSend(cmd *cobra.Command, op publicapi.Operation, args []string, yes bool) error {
	if yes {
		return nil
	}
	errOut := cmd.ErrOrStderr()
	if !isTerminalReader(cmd.InOrStdin()) || !isTerminalWriter(cmd.OutOrStdout()) || !isTerminalWriter(errOut) {
		return &exitError{exitUsage, errors.New("this command asks for a confirmation and has no terminal to ask on: pass --yes")}
	}

	fmt.Fprintf(errOut, "%s: %s. Continue? [y/N] ", op.Summary, strings.Join(args, " "))
	line, _ := bufio.NewReader(cmd.InOrStdin()).ReadString('\n')
	switch strings.ToLower(strings.TrimSpace(line)) {
	case "y", "yes":
		return nil
	}

	return &exitError{1, errors.New("cancelled, nothing was sent")}
}

// writeShort prints the default result of a write: a note on stderr, and the
// url of the answer on stdout when it has one.
func writeShort(cmd *cobra.Command, op publicapi.Operation, body []byte) {
	fmt.Fprintf(cmd.ErrOrStderr(), "Done: %s.\n", op.Summary)

	var answer struct {
		URL *string `json:"url"`
	}
	if json.Unmarshal(body, &answer) == nil && answer.URL != nil && *answer.URL != "" {
		fmt.Fprintln(cmd.OutOrStdout(), *answer.URL)
	}
}

func isWrite(op publicapi.Operation) bool {
	return op.Method != http.MethodGet
}
