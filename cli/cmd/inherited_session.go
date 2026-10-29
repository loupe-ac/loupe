package cmd

import "os"

// inheritedSessionEnv names the variables that a Claude Code session, or the
// worker of another bridge, sets on each child. A bridge started from such a
// process must not pass them on. The list names each variable, because
// operator settings such as CLAUDE_CONFIG_DIR share the CLAUDE_CODE_ prefix.
var inheritedSessionEnv = []string{
	"CLAUDECODE",
	"CLAUDE_CODE_SESSION_ID",
	"CLAUDE_CODE_CHILD_SESSION",
	"CLAUDE_CODE_SESSION_ATTENDED",
	"CLAUDE_PID",
	"CLAUDE_EFFORT",
	"AI_AGENT",
	"CLAUDE_CODE_MESSAGING_SOCKET",
	"CLAUDE_CODE_MESSAGING_TOKEN",
	"CLAUDE_CODE_BRIDGE_SESSION_ID",
	"CLAUDE_CODE_ENTRYPOINT",
	"CLAUDE_CODE_EXECPATH",
	sessionEnv,
	socketEnv,
	runIDEnv,
	controlSocketEnv,
}

// dropInheritedSession removes the inheritedSessionEnv variables from the
// process environment, so every child process starts without them.
func dropInheritedSession() {
	for _, name := range inheritedSessionEnv {
		os.Unsetenv(name)
	}
}
