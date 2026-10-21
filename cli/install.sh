#!/bin/sh
# Installs the loupe CLI from its GitHub release.
# Usage: curl -fsSL https://<your Loupe>/install.sh | sh -s -- [options]
set -eu

usage() {
	cat <<'EOF'
Install the loupe CLI.

Options:
  --install-dir DIR  Install loupe into DIR.
  --auto-update      Turn on automatic updates, and write that to the rule file.
                     If that fails, loupe stays installed and the script exits
                     with status 2. Without this option, "loupe init" asks.
  --version X.Y.Z    Install this release, not the newest one.
  --help             Show this help.

On macOS, a release that holds the Live review panel installs it at
"$HOME/Library/Application Support/loupe/live-review/X.Y.Z/Live review.app".
The script removes the panels of other versions. If the panel cannot be
installed, loupe stays installed and the script exits with status 3. If both
the panel and automatic updates fail, the status is 4.

Environment:
  LOUPE_INSTALL_NO_TTY=1     Treat the session as non-interactive.
  LOUPE_INSTALL_TTY=FILE     Read the answers from FILE, not from /dev/tty.
  LOUPE_RULES_FILE=FILE      Write the rule file of --auto-update at FILE.
EOF
}

say() { printf '%s\n' "$*"; }
die() {
	printf 'error: %s\n' "$*" >&2
	exit 1
}

has() { command -v "$1" >/dev/null 2>&1; }

# fetch URL FILE
fetch() {
	if has curl; then
		curl -fsSL -o "$2" "$1"
	else
		wget -qO "$2" "$1"
	fi
}

# install_panel ARCHIVE VERSION installs Live review.app from the archive, when it holds one.
# It prints the install path on success. It returns 0 with no output when the archive has no panel.
install_panel() {
	tar -tzf "$1" | grep -q '^Live review\.app/' || return 0
	panels="$HOME/Library/Application Support/loupe/live-review"
	final="$panels/$2/Live review.app"
	stage="$panels/.install.$$"
	rm -rf "$stage"
	mkdir -p "$stage" "$panels/$2" || return 1
	if ! { tar -xzpf "$1" -C "$stage" "Live review.app" && [ -d "$stage/Live review.app" ]; }; then
		rm -rf "$stage"
		rmdir "$panels/$2" 2>/dev/null
		return 1
	fi
	# A directory rename cannot replace a full directory, so the old copy moves aside first.
	if [ -e "$final" ] && ! mv "$final" "$stage/old"; then
		rm -rf "$stage"
		return 1
	fi
	if ! mv "$stage/Live review.app" "$final"; then
		[ ! -e "$stage/old" ] || mv "$stage/old" "$final"
		rm -rf "$stage"
		return 1
	fi
	rm -rf "$stage"
	for old in "$panels"/*; do
		[ -e "$old" ] || continue
		[ "${old##*/}" = "$2" ] || rm -rf "$old"
	done
	say "$final"
}

sha256() {
	if has sha256sum; then
		sha256sum "$1" | awk '{ print $1 }'
	else
		shasum -a 256 "$1" | awk '{ print $1 }'
	fi
}

# ask PROMPT reads one answer from the terminal on fd 3 into answer.
# A pasted block can feed its comment line to the prompt, so a comment counts as empty.
ask() {
	printf '%s' "$1" >&2
	answer=''
	read -r answer <&3 || true
	case $answer in
	'#'*) answer='' ;;
	esac
}

# existing_dir prints the directory of a loupe on PATH that Homebrew does not own.
existing_dir() {
	found=$(command -v loupe 2>/dev/null) || return 1
	case $found in
	/*) ;;
	*) return 1 ;;
	esac
	real=$(readlink -f "$found" 2>/dev/null) || real=''
	[ -n "$real" ] || real=$found
	case $real in
	*/Cellar/* | /opt/homebrew/* | */.linuxbrew/*) return 1 ;;
	esac
	say "${real%/*}"
}

on_path() {
	case ":$PATH:" in
	*":$1:"*) return 0 ;;
	esac
	return 1
}

pick_dir() {
	if on_path "$HOME/.local/bin"; then
		say "$HOME/.local/bin"
	elif on_path "$HOME/bin"; then
		say "$HOME/bin"
	elif [ -n "$tty" ]; then
		ask 'Install directory [~/.local/bin]: '
		case $answer in
		'') say "$HOME/.local/bin" ;;
		'~' | '~'/*) say "$HOME${answer#?}" ;;
		*) say "$answer" ;;
		esac
	else
		say "$HOME/.local/bin"
	fi
}

# published_tags FILE prints one line for each release of a GitHub release list:
# its tag when "draft" and "prerelease" are both false, else "-".
# It tracks nesting and strings, so key order, JSON layout and the body do not matter.
published_tags() {
	awk '
	{
		n = length($0)
		for (i = 1; i <= n; i++) {
			c = substr($0, i, 1)
			if (instr) {
				if (esc) esc = 0
				else if (c == "\\") esc = 1
				else if (c == "\"") {
					instr = 0
					if (nest == 2) {
						str = substr($0, start, i - start)
						if (want_key) key = str
						else if (key == "tag_name") tag = str
					}
				}
				continue
			}
			if (c == "\"") { instr = 1; start = i + 1; continue }
			if (c == "{" || c == "[") {
				nest++
				if (nest == 2 && c == "{") { tag = ""; draft = ""; pre = ""; key = ""; lit = ""; want_key = 1 }
				continue
			}
			if (nest != 2) {
				if (c == "}" || c == "]") nest--
				continue
			}
			if (c == ":") { want_key = 0; lit = "" }
			else if (c == "," || c == "}") {
				if (key == "draft") draft = lit
				if (key == "prerelease") pre = lit
				want_key = 1; lit = ""
				if (c == "}") {
					out = "-"
					if (tag != "" && draft == "false" && pre == "false") out = tag
					print out
					nest--
				}
			} else if (c == "]") nest--
			else if (c ~ /[a-z]/) lit = lit c
		}
	}' "$1"
}

latest_version() {
	# Only plain X.Y.Z tags match, so a pre-release such as cli/v1.2.0-rc1 does not.
	sed -n 's/^cli\/v\([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\)$/\1/p' "$work/tags" |
		grep "^$major\." |
		sort -t. -k1,1n -k2,2n -k3,3n |
		tail -n 1
}

main() {
	major=${LOUPE_CLI_MAJOR:-1}
	url=${LOUPE_URL:-}
	github_api=${LOUPE_GITHUB_API:-https://api.github.com}
	github_download=${LOUPE_GITHUB_DOWNLOAD:-https://github.com}
	dir='' auto='' version='' auto_failed='' panel_failed='' panel_path=''

	while [ $# -gt 0 ]; do
		case $1 in
		--install-dir) [ $# -ge 2 ] || die "--install-dir needs a directory."; dir=$2; shift ;;
		--install-dir=*) dir=${1#*=} ;;
		--auto-update) auto=1 ;;
		--version) [ $# -ge 2 ] || die "--version needs a version."; version=${2#v}; shift ;;
		--version=*) version=${1#*=}; version=${version#v} ;;
		-h | --help) usage; exit 0 ;;
		*) die "unknown option $1. Run with --help to see the options." ;;
		esac
		shift
	done

	case $(uname -s) in
	Darwin) os=darwin ;;
	Linux) os=linux ;;
	*) die "unsupported operating system $(uname -s)." ;;
	esac
	case $(uname -m) in
	x86_64 | amd64) arch=amd64 ;;
	arm64 | aarch64) arch=arm64 ;;
	*) die "unsupported CPU $(uname -m)." ;;
	esac

	has curl || has wget || die "this script needs curl or wget."
	has sha256sum || has shasum || die "this script needs sha256sum or shasum to check the download."

	if has brew && brew list --formula loupe >/dev/null 2>&1; then
		say "loupe is already installed with Homebrew. To upgrade it, run: brew upgrade loupe"
		exit 0
	fi

	tty=''
	ttydev=${LOUPE_INSTALL_TTY:-/dev/tty}
	# A failed redirection on exec ends a POSIX shell, so a subshell tries it first.
	if [ "${LOUPE_INSTALL_NO_TTY:-}" != 1 ] && (exec 3<"$ttydev") 2>/dev/null; then
		exec 3<"$ttydev"
		tty=1
	fi

	work=$(mktemp -d)
	trap 'rm -rf "$work"' EXIT

	if [ -n "$version" ]; then
		say "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || die "the version must look like 1.2.3, not $version."
	else
		: >"$work/tags"
		page=1
		while [ "$page" -le 10 ]; do
			fetch "$github_api/repos/ubermuda/loupe/releases?per_page=100&page=$page" "$work/page.json" ||
				die "cannot read the release list from $github_api."
			published_tags "$work/page.json" >"$work/page.tags"
			[ -s "$work/page.tags" ] || break
			cat "$work/page.tags" >>"$work/tags"
			page=$((page + 1))
		done
		version=$(latest_version)
		[ -n "$version" ] || die "no loupe CLI release $major.x found. The GitHub API rate limit can cause this. Try again later, or use --version."
	fi

	archive="loupe_${version}_${os}_${arch}.tar.gz"
	base="$github_download/ubermuda/loupe/releases/download/cli/v$version"
	say "Downloading loupe $version for $os/$arch."
	fetch "$base/$archive" "$work/$archive" || die "cannot download $base/$archive."
	fetch "$base/checksums.txt" "$work/checksums.txt" || die "cannot download $base/checksums.txt."

	expected=$(awk -v f="$archive" '$2 == f { print $1 }' "$work/checksums.txt")
	[ -n "$expected" ] || die "checksums.txt has no entry for $archive. Nothing was installed."
	actual=$(sha256 "$work/$archive")
	[ "$actual" = "$expected" ] || die "the checksum of $archive does not match checksums.txt. Nothing was installed."

	tar -xzf "$work/$archive" -C "$work" loupe

	reachable=''
	if [ -z "$dir" ]; then
		if dir=$(existing_dir); then
			reachable=1
		else
			dir=$(pick_dir)
		fi
	fi
	mkdir -p "$dir" || die "cannot create $dir. Choose another directory with --install-dir."
	dir=$(CDPATH='' cd -- "$dir" && pwd)
	bin="$dir/loupe"
	# A rename replaces a running binary in one step, and the old process keeps its copy.
	tmp="$dir/.loupe.tmp.$$"
	if ! { cp "$work/loupe" "$tmp" && chmod 755 "$tmp" && mv -f "$tmp" "$bin"; }; then
		rm -f "$tmp"
		die "cannot install loupe into $dir. Choose another directory with --install-dir."
	fi

	if [ "$os" = darwin ]; then
		if panel_path=$(install_panel "$work/$archive" "$version"); then
			:
		else
			panel_failed=1
			panel_path=''
			say "warning: loupe could not install the Live review panel." >&2
		fi
	fi

	state='' output=''
	if [ -n "$auto" ]; then
		set -- update auto on
		if [ -n "${LOUPE_RULES_FILE:-}" ]; then
			set -- "$@" --rules "$LOUPE_RULES_FILE"
		fi
		if output=$("$bin" "$@" </dev/null 2>&1); then
			state=unknown
			case $output in
			*"Automatic updates: on"*) state=on ;;
			*"Automatic updates: off"*) state=off ;;
			esac
		else
			say "warning: loupe could not set automatic updates:" >&2
			state=unknown
			auto_failed=$auto
		fi
	fi

	say ""
	say "Installed loupe $version at $bin."
	[ -z "$panel_path" ] || say "Installed the Live review panel at $panel_path."
	if [ -n "$auto" ]; then
		say "$output"
		say "Auto-update: $state"
		[ "$state" = on ] || say "To turn on automatic updates, run: loupe update auto on"
	fi
	if [ -z "$reachable" ] && ! on_path "$dir"; then
		say ""
		say "$dir is not on your PATH. Add this line to your shell profile:"
		say "  export PATH=\"$dir:\$PATH\""
	fi
	say ""
	say "Next, run: loupe init --url ${url:-<your Loupe URL>}"

	if [ -n "$panel_failed" ]; then
		say "" >&2
		say "error: loupe is installed at $bin, but the Live review panel is NOT installed." >&2
		say "To try again, run this script again." >&2
		[ -z "$auto_failed" ] || say "Automatic updates are NOT on either. Run: loupe update auto on" >&2
		if [ -n "$auto_failed" ]; then exit 4; fi
		exit 3
	fi
	if [ -n "$auto_failed" ]; then
		say "" >&2
		say "error: loupe is installed at $bin, but automatic updates are NOT on." >&2
		say "To try again, run: loupe update auto on" >&2
		exit 2
	fi
}

main "$@"
