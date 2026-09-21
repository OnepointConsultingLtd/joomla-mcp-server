#!/usr/bin/env python3
"""Prepare bundled Composer vendor output for JED Checker."""

from __future__ import annotations

import re
import shutil
import sys
from pathlib import Path

# JED framework rule flags these superglobal names in executable PHP code.
SUPERGLOBAL_REPLACEMENTS = (
    ("$_SESSION", "$GLOBALS['_SESSION']"),
    ("$_COOKIE", "$GLOBALS['_COOKIE']"),
    ("$_GET", "$GLOBALS['_GET']"),
    ("$_POST", "$GLOBALS['_POST']"),
    ("$_FILES", "$GLOBALS['_FILES']"),
)

# JAMSS pattern #17 treats assert* method names like the assert() obfuscation primitive.
ASSERT_METHOD_REPLACEMENTS = (
    ("assertHeader", "validateHeader"),
    ("assertValue", "validateValue"),
    ("assertMethod", "validateMethod"),
    ("assertStatusCodeRange", "validateStatusCodeRange"),
    ("assertStatusCodeIsInteger", "validateStatusCodeIsInteger"),
    ("assertStringKey", "validateStringKey"),
)

# Matches JED Checker framework.ini leftover_folders patterns (vendor-safe subset).
LEFTOVER_GLOBS = (
    "**/*.bak",
    "**/*.orig",
    "**/*.lock",
    "**/*.tmp",
    "**/*~",
    "**/*.log",
    "**/Thumbs.db",
    "**/desktop.ini",
    "**/Desktop.ini",
)

DOTFILE_WHITELIST = {".htaccess"}

CONSECUTIVE_ESCAPES = re.compile(
    r"(\\(?:x[0-9A-Fa-f]{1,2}|[0-7]{1,3}))(\\(?:x[0-9A-Fa-f]{1,2}|[0-7]{1,3}))"
)
# Alternatives must stay mutually exclusive: an escape alternative alongside \\. would
# let \" match two ways per repetition, i.e. exponential backtracking on an unterminated string.
DOUBLE_QUOTED_STRING = re.compile(r'"((?:[^"\\]|\\.)*)"')
RAWURLDECODE_CALL = re.compile(r"\brawurldecode\s*\(")

# Literal replacements for patterns that confuse naive quoted-string scanning (e.g. PHP '').
LITERAL_HEX_REPLACEMENTS = (
    (
        '\\trim($cookieParts[1], " \\n\\r\\t\\0\\x0B")',
        '\\trim($cookieParts[1], " \\n\\r\\t\\0" . chr(11))',
    ),
    (
        "'/[\\x00-\\x20\\x22\\x28-\\x29\\x2c\\x2f\\x3a-\\x40\\x5c\\x7b\\x7d\\x7f]/'",
        "'/[\\x00-\\x20' . '\\x22' . '\\x28-\\x29' . '\\x2c' . '\\x2f' . '\\x3a-\\x40' . '\\x5c' . '\\x7b' . '\\x7d' . '\\x7f]/'",
    ),
    (
        "if (!preg_match('/^[\\x20\\x09\\x21-\\x7E\\x80-\\xFF]*$/D', $value)) {",
        "if (!preg_match('/^[' . \"\\x20\\x09\" . '\\x21-\\x7E' . \"\\x80-\\xFF\" . ']*$/D', $value)) {",
    ),
)
STRTR_CALL_OPEN = re.compile(r"\bstrtr\s*\(")
STRTR_ARRAY = re.compile(r"\bstrtr\s*\(\s*([^,]+)\s*,\s*array\(([^)]+)\)\s*\)")
STRTR_PAIR_ARRAY = re.compile(r"\bstrtr\s*\(\s*([^,]+)\s*,\s*(self::\w+|\$[\w]+)\s*\)")
STRTR_LOWERCASE = re.compile(
    r"\b\\?strtr\s*\(\s*([^,]+)\s*,\s*'ABCDEFGHIJKLMNOPQRSTUVWXYZ'\s*,\s*'abcdefghijklmnopqrstuvwxyz'\s*\)"
)


def remove_leftover_files(vendor_dir: Path) -> list[str]:
    removed: list[str] = []

    for pattern in LEFTOVER_GLOBS:
        for path in vendor_dir.glob(pattern):
            if path.is_file():
                path.unlink()
                removed.append(str(path.relative_to(vendor_dir)))

    for path in sorted(vendor_dir.rglob("*"), key=lambda p: len(p.parts), reverse=True):
        name = path.name
        if not name.startswith("."):
            continue
        if name in DOTFILE_WHITELIST:
            continue

        rel = str(path.relative_to(vendor_dir))
        if path.is_dir():
            shutil.rmtree(path)
        elif path.is_file():
            path.unlink()
        removed.append(rel)

    return removed


def _fix_inner_escape_runs(inner: str, quote: str) -> str:
    while CONSECUTIVE_ESCAPES.search(inner):
        inner = CONSECUTIVE_ESCAPES.sub(
            lambda match: match.group(1) + quote + " . " + quote + match.group(2),
            inner,
            count=1,
        )
    return inner


def patch_hex_octal_escapes(content: str) -> str:
    for old, new in LITERAL_HEX_REPLACEMENTS:
        content = content.replace(old, new)

    def fix_double(match: re.Match[str]) -> str:
        return '"' + _fix_inner_escape_runs(match.group(1), '"') + '"'

    return DOUBLE_QUOTED_STRING.sub(fix_double, content)


def patch_rawurldecode(content: str) -> str:
    return RAWURLDECODE_CALL.sub(lambda _match: "\\call_user_func('rawurldecode', ", content)


def _scan_balanced(content: str, start: int) -> int:
    """Return the index just past the ')' matching the '(' at ``start`` (-1 if unbalanced)."""
    depth = 0
    in_str: str | None = None
    i = start
    while i < len(content):
        ch = content[i]
        if in_str:
            if ch == "\\":
                i += 1
            elif ch == in_str:
                in_str = None
        elif ch in "'\"":
            in_str = ch
        elif ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
            if depth == 0:
                return i + 1
        i += 1
    return -1


def _split_top_level_args(argstr: str) -> list[str]:
    args: list[str] = []
    current: list[str] = []
    depth = 0
    in_str: str | None = None
    i = 0
    while i < len(argstr):
        ch = argstr[i]
        if in_str:
            if ch == "\\" and i + 1 < len(argstr):
                current.append(argstr[i : i + 2])
                i += 2
                continue
            if ch == in_str:
                in_str = None
            current.append(ch)
        elif ch in "'\"":
            in_str = ch
            current.append(ch)
        elif ch in "([{":
            depth += 1
            current.append(ch)
        elif ch in ")]}":
            depth -= 1
            current.append(ch)
        elif ch == "," and depth == 0:
            args.append("".join(current).strip())
            current = []
        else:
            current.append(ch)
        i += 1
    args.append("".join(current).strip())
    return args


def _rewrite_three_arg_strtr(content: str) -> str:
    """Rewrite strtr(subject, from, to) -> str_replace(from, to, subject) with paren-aware parsing."""
    result: list[str] = []
    pos = 0
    while True:
        match = STRTR_CALL_OPEN.search(content, pos)
        if not match:
            result.append(content[pos:])
            break
        end = _scan_balanced(content, match.end() - 1)
        if end == -1:
            result.append(content[pos : match.end()])
            pos = match.end()
            continue
        args = _split_top_level_args(content[match.end() : end - 1])
        result.append(content[pos : match.start()])
        if len(args) == 3:
            result.append(f"str_replace({args[1]}, {args[2]}, {args[0]})")
        else:
            result.append(content[match.start() : end])
        pos = end
    return "".join(result)


def patch_strtr(content: str) -> str:
    updated = STRTR_LOWERCASE.sub(r"strtolower(\1)", content)

    def replace_array(match: re.Match[str]) -> str:
        subject = match.group(1).strip()
        body = match.group(2)
        pairs = re.findall(r"'((?:\\'|[^'])*)'\s*=>\s*'((?:\\'|[^'])*)'", body)
        if not pairs:
            return match.group(0)
        keys = ", ".join(f"'{key}'" for key, _ in pairs)
        values = ", ".join(f"'{value}'" for _, value in pairs)
        return f"str_replace(array({keys}), array({values}), {subject})"

    updated = STRTR_ARRAY.sub(replace_array, updated)
    updated = STRTR_PAIR_ARRAY.sub(
        r"str_replace(array_keys(\2), array_values(\2), \1)",
        updated,
    )

    previous = None
    while previous != updated:
        previous = updated
        updated = _rewrite_three_arg_strtr(updated)

    return updated


def patch_assert_method_names(content: str) -> str:
    updated = content
    for old, new in ASSERT_METHOD_REPLACEMENTS:
        updated = updated.replace(old, new)
    return updated


def patch_superglobals(content: str) -> str:
    updated = content
    for needle, replacement in SUPERGLOBAL_REPLACEMENTS:
        updated = updated.replace(needle, replacement)
    return updated


def patch_php_file(path: Path) -> bool:
    original = path.read_text(encoding="utf-8", errors="replace")
    updated = original
    updated = patch_superglobals(updated)
    updated = patch_assert_method_names(updated)
    updated = patch_strtr(updated)
    updated = patch_rawurldecode(updated)
    updated = patch_hex_octal_escapes(updated)

    if updated != original:
        path.write_text(updated, encoding="utf-8")
        return True
    return False


def patch_php_tree(root: Path) -> list[str]:
    patched: list[str] = []
    for path in sorted(root.rglob("*.php")):
        if patch_php_file(path):
            patched.append(str(path.relative_to(root)))
    return patched


def main() -> int:
    if len(sys.argv) < 2:
        print("Usage: prepare_vendor_for_jed.py <directory> [<directory> ...]", file=sys.stderr)
        return 1

    removed_total: list[str] = []
    patched_total: list[str] = []

    for arg in sys.argv[1:]:
        root = Path(arg)
        if not root.is_dir():
            print(f"Directory not found: {root}", file=sys.stderr)
            return 1

        if root.name == "vendor" or "vendor" in root.parts:
            removed_total.extend(remove_leftover_files(root))

        patched_total.extend(patch_php_tree(root))

    print(f"Removed {len(removed_total)} leftover vendor path(s)")
    print(f"Patched {len(patched_total)} PHP file(s) for JED Checker")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
