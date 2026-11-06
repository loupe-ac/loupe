#!/usr/bin/env python3
# PreToolUse hook for Bash: refuse a command that writes a .php file inside the
# repository, so PHP edits go through Edit or Write and get LSP diagnostics.
# The check is a heuristic: it judges each simple command by what it writes.
import json
import os
import re
import shlex
import sys
import time

MESSAGE = "Edit PHP files with the Edit or Write tool, not through Bash. Bash writes get no LSP diagnostics."
HEREDOC = re.compile(r"(?<!<)<<(-?)(?:'([^']+)'|\"([^\"]+)\"|\\?([A-Za-z_]\w*))")
HEREDOC_TOKEN = re.compile(r"__HD(\d+)__")
WRAPPERS = {"sudo", "env", "command", "nohup", "time", "exec", "nice"}
INTERPRETER = re.compile(r"^(python[\d.]*|ruby|nodejs?|php)$")
QUOTED = r"""'[^'\n]*'|"[^"\n]*\""""
TARGET = rf"(?:Path\(\s*(?:{QUOTED}|[A-Za-z_]\w*)\s*\)|{QUOTED}|[A-Za-z_$][\w.$]*)"
OPEN_CALL = re.compile(rf"\b(?:open|fopen)\(\s*({TARGET})\s*,\s*(?:mode\s*=\s*)?('[^']*'|\"[^\"]*\")")
METHOD_CALL = re.compile(rf"({TARGET})\.write_(?:text|bytes)\(")
FUNCTION_CALL = re.compile(rf"\b(?:file_put_contents|(?:writeFile|appendFile)(?:Sync)?|File\.write)\(\s*({TARGET})")
UNKNOWN = object()


def find_root(cwd):
    folder = os.path.realpath(cwd)
    while True:
        if os.path.exists(os.path.join(folder, ".git")):
            return folder
        parent = os.path.dirname(folder)
        if parent == folder:
            break
        folder = parent
    project = os.environ.get("CLAUDE_PROJECT_DIR")
    return os.path.realpath(project) if project else None


def extract_heredocs(command):
    lines, bodies, pending = [], [], []
    for line in command.split("\n"):
        if pending:
            index, delimiter, dash = pending[0]
            if (line.lstrip("\t") if dash else line) == delimiter:
                pending.pop(0)
            else:
                bodies[index] += line + "\n"
            continue

        def replace(match):
            bodies.append("")
            pending.append((len(bodies) - 1, match.group(2) or match.group(3) or match.group(4), bool(match.group(1))))
            return f" __HD{len(bodies) - 1}__ "

        lines.append(HEREDOC.sub(replace, line))
    return "\n".join(lines), bodies


def tokenize(text):
    text = text.replace("\\;", " __SEMI__ ")
    try:
        lexer = shlex.shlex(text, posix=True, punctuation_chars=";&|()<>\n")
        lexer.whitespace = " \t\r"
        lexer.whitespace_split = True
        lexer.commenters = ""
        return list(lexer)
    except ValueError:
        return re.findall(r"[;&|()<>\n]+|[^\s;&|()<>]+", text)


def split_commands(tokens):
    commands = []
    current = {"words": [], "redirs": [], "hd": None, "pipe_in": False}
    state = None
    for token in tokens:
        if state == "file":
            current["redirs"].append(token)
            state = None
            continue
        if state == "skip":
            state = None
            continue
        if re.fullmatch(r"[;&|()\n]+", token):
            if current["words"] or current["redirs"]:
                commands.append(current)
            current = {"words": [], "redirs": [], "hd": None, "pipe_in": token in ("|", "|&")}
        elif re.fullmatch(r"&>>?|>>?\|?", token):
            state = "file"
        elif "<" in token or ">" in token:
            state = "skip"
        elif HEREDOC_TOKEN.fullmatch(token):
            current["hd"] = int(HEREDOC_TOKEN.fullmatch(token).group(1))
        elif token == "__SEMI__":
            current["words"].append(";")
        else:
            current["words"].append(token)
    if current["words"] or current["redirs"]:
        commands.append(current)
    return commands


def resolve(word, folder):
    expanded = os.path.expandvars(os.path.expanduser(word))
    if "$" in expanded or "`" in expanded:
        return None
    if os.path.isabs(expanded):
        return os.path.realpath(expanded)
    if folder is None:
        return UNKNOWN
    return os.path.realpath(os.path.join(folder, expanded))


def is_inside(path, root):
    if path is None:
        return False
    if path is UNKNOWN:
        return True
    return path == root or path.startswith(root.rstrip(os.sep) + os.sep)


def is_php_path(word, folder, root):
    if word.startswith("--") and "=" in word:
        word = word.split("=", 1)[1]
    if not word.endswith(".php"):
        return False
    return is_inside(resolve(word, folder), root)


def in_place_flag(name, argument):
    if name == "perl":
        return re.fullmatch(r"-[nplaws0-9]*i\S*", argument) is not None
    return re.fullmatch(r"-[nEersuzb]*i\S*", argument) is not None or argument.startswith("--in-place")


def direct_write(name, args, redirs, is_php):
    if any(is_php(target) for target in redirs):
        return True
    files = [argument for argument in args if not argument.startswith("-")]
    if name == "tee":
        return any(is_php(argument) for argument in files)
    if name in ("cp", "install"):
        return bool(files) and is_php(files[-1])
    if name in ("sed", "gsed", "perl"):
        return any(in_place_flag(name, argument) for argument in args) and any(is_php(argument) for argument in args)
    return False


def script_values(expression, text):
    expression = expression.strip()
    wrapped = re.fullmatch(r"Path\(\s*(.*?)\s*\)", expression, re.S)
    if wrapped:
        expression = wrapped.group(1)
    if expression[:1] in ("'", '"'):
        return [expression[1:-1]]
    assigned = re.findall(rf"(?<![\w.]){re.escape(expression)}\s*=\s*(?:Path\(\s*)?({QUOTED})", text)
    return [value[1:-1] for value in assigned] or None


def script_writes_php(text, folder, root):
    unknown = False
    targets = [match.group(1) for match in OPEN_CALL.finditer(text) if re.search("[wax]", match.group(2))]
    targets += [match.group(1) for match in METHOD_CALL.finditer(text)]
    targets += [match.group(1) for match in FUNCTION_CALL.finditer(text)]
    for target in targets:
        values = script_values(target, text)
        if values is None:
            unknown = True
        elif any(is_php_path(value, folder, root) for value in values):
            return True
    if unknown:
        return any(is_php_path(value[1:-1], folder, root) for value in re.findall(QUOTED, text))
    return False


def next_folder(args, folder):
    targets = [argument for argument in args if not argument.startswith("-")]
    if args and args[0] == "-":
        return None
    if not targets:
        return os.path.realpath(os.path.expanduser("~"))
    path = resolve(targets[0], folder)
    return None if path is UNKNOWN or path is None else path


def has_pattern(words):
    return any(word.split("=", 1)[-1].endswith(".php") for word in words)


def folders_inside(words, folder, root):
    absolute = [word for word in words if word.startswith(("/", "~"))]
    if not absolute:
        return folder is None or is_inside(folder, root)
    return any(is_inside(resolve(word, folder), root) for word in absolute)


def judge(command, cwd, root, depth=0):
    text, bodies = extract_heredocs(command)
    folder = os.path.realpath(cwd)
    written = {}
    previous = None
    for item in split_commands(tokenize(text)):
        words = list(item["words"])
        while words and (re.fullmatch(r"\w+=.*", words[0]) or os.path.basename(words[0]) in WRAPPERS):
            words.pop(0)

        def is_php(word, folder=folder):
            return is_php_path(word, folder, root)

        name = os.path.basename(words[0]) if words else ""
        args = words[1:]
        if name in ("cd", "pushd"):
            folder = next_folder(args, folder)
            previous = item
            continue
        if item["hd"] is not None:
            body = bodies[item["hd"]]
            targets = list(item["redirs"]) + ([a for a in args if not a.startswith("-")] if name == "tee" else [])
            for target in targets:
                path = resolve(target, folder)
                if isinstance(path, str):
                    written[path] = body
        if direct_write(name, args, item["redirs"], is_php):
            return True
        if name == "find" and "-exec" in args or name == "find" and "-execdir" in args:
            start = []
            for argument in args:
                if argument.startswith(("-", "(", "!")):
                    break
                start.append(argument)
            patterns = [args[i + 1] for i, a in enumerate(args[:-1]) if a in ("-name", "-iname", "-path", "-ipath", "-wholename")]
            if has_pattern(patterns) and folders_inside(start, folder, root):
                for i, argument in enumerate(args):
                    if argument in ("-exec", "-execdir"):
                        end = i + 1
                        while end < len(args) and args[end] not in (";", "+"):
                            end += 1
                        sub = args[i + 1:end]
                        if sub and direct_write(os.path.basename(sub[0]), sub[1:] + ["{}"], [], lambda w: w == "{}" or is_php(w)):
                            return True
        if name == "xargs" and item["pipe_in"] and previous is not None:
            before = previous["words"]
            sub = list(args)
            while sub and sub[0].startswith("-"):
                option = sub.pop(0)
                if option in ("-n", "-I", "-P", "-L", "-s", "-d", "-E") and sub:
                    sub.pop(0)
            if sub and has_pattern(before) and folders_inside(before, folder, root):
                if direct_write(os.path.basename(sub[0]), sub[1:] + ["{}"], [], lambda w: w == "{}" or is_php(w)):
                    return True
        if INTERPRETER.match(name):
            script = "\n".join(args)
            if item["hd"] is not None:
                script += "\n" + bodies[item["hd"]]
            for argument in args:
                path = resolve(argument, folder)
                if isinstance(path, str) and path in written:
                    script += "\n" + written[path]
            if script_writes_php(script, folder, root):
                return True
        if name in ("bash", "sh", "zsh") and "-c" in args and depth < 3:
            index = args.index("-c") + 1
            if index < len(args) and judge(args[index], folder or cwd, root, depth + 1):
                return True
        previous = item
    return False


def log_refusal(command):
    config = os.environ.get("CLAUDE_CONFIG_DIR")
    if not config:
        return
    try:
        folder = os.path.join(config, "hooks")
        os.makedirs(folder, exist_ok=True)
        with open(os.path.join(folder, "refusals.log"), "a") as log:
            line = {"time": time.strftime("%Y-%m-%dT%H:%M:%S%z"), "run": os.environ.get("LOUPE_RUN_ID", ""), "command": command[:2000]}
            log.write(json.dumps(line) + "\n")
    except OSError:
        pass


def main():
    try:
        payload = json.load(sys.stdin)
        command = payload.get("tool_input", {}).get("command", "")
        cwd = payload.get("cwd") or os.getcwd()
        root = find_root(cwd)
        refuse = root is not None and judge(command, cwd, root)
    except Exception:
        return 0
    if refuse:
        log_refusal(command)
        print(MESSAGE, file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
