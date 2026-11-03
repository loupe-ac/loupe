// Package publicapi holds what the public API says about each operation, and
// the commands that call them. The spec facts are generated; the names are not.
package publicapi

// Param is one parameter or one body field of an operation.
type Param struct {
	Name        string
	Type        string // string, integer, number, boolean, array or object
	Format      string
	Items       string // the type of an array item
	Required    bool
	Nullable    bool
	Description string
	Enum        []string
}

// Operation is the part of an operation that the spec decides.
type Operation struct {
	ID          string
	Method      string
	Path        string
	Summary     string
	Description string
	// PathArgs are the positional values, in path order. `project` is in the
	// list only when the path ends at the project itself.
	PathArgs []string
	// HasProject is true when the path holds a {project} value.
	HasProject   bool
	Query, Body  []Param
	Idempotent   bool
	TakesFields  bool
	AnswerFields []string
	TimeFields   []string
	// Multipart is true for a file upload body.
	Multipart bool
}

// Command is the part of an operation that a person decides.
type Command struct {
	Path    []string
	Op      string
	Columns []string
	// LongText is the long text field: --body-file fills it on a write, and one
	// item prints it last.
	LongText string
}

// Exception is an operation that has no command, with the reason.
type Exception struct {
	Op     string
	Reason string
}

// Lookup finds an operation of the generated table by its id.
func Lookup(id string) (Operation, bool) {
	for _, op := range Operations {
		if op.ID == id {
			return op, true
		}
	}

	return Operation{}, false
}
