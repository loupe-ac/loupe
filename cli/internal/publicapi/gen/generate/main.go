// Command generate writes operations_gen.go from the OpenAPI spec.
package main

import (
	"flag"
	"fmt"
	"os"

	"github.com/loupe-ac/loupe/cli/internal/publicapi/gen"
)

func main() {
	specPath := flag.String("spec", "/openapi/public.json", "the OpenAPI spec file")
	out := flag.String("out", "internal/publicapi/operations_gen.go", "the Go file to write")
	flag.Parse()

	if err := run(*specPath, *out); err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		os.Exit(1)
	}
}

func run(specPath, out string) error {
	data, err := os.ReadFile(specPath)
	if err != nil {
		return err
	}
	src, err := gen.Generate(data)
	if err != nil {
		return err
	}

	return os.WriteFile(out, src, 0o644)
}
