#!/usr/bin/env python3
"""Maintain the Joomla changelog feed (changelog.xml) from CHANGELOG.md.

Ensures a <changelog> block exists for a given version (prepended, newest first),
generated from the matching ## <version> section in CHANGELOG.md. Bullet items are
classified as addition (Added…), fix (Fixed…), or change (everything else).

Usage:
    sync_changelog_entry.py <changelog.xml> <version> [changelog.md]

Idempotent: re-running with the same arguments leaves the file unchanged.
"""

import re
import sys
from pathlib import Path
from xml.sax.saxutils import escape

ELEMENT = "com_mcpserver"
COMPONENT_TYPE = "component"
CATEGORY_ORDER = ("addition", "fix", "change", "note")


def strip_markdown(text: str) -> str:
    text = re.sub(r"\*\*([^*]+)\*\*", r"\1", text)
    text = re.sub(r"`([^`]+)`", r"\1", text)
    return text.strip()


def classify_item(text: str) -> str:
    lower = text.lower()
    if lower.startswith("fixed"):
        return "fix"
    if lower.startswith("added"):
        return "addition"
    return "change"


def parse_changelog_section(markdown: str, version: str) -> list[str]:
    """Return bullet items for ## <version> … in CHANGELOG.md."""
    pattern = re.compile(rf"^##\s+{re.escape(version)}\s+")
    lines = markdown.splitlines()
    items: list[str] = []
    capture = False

    for line in lines:
        if pattern.match(line):
            capture = True
            continue
        if capture and line.startswith("## "):
            break
        if capture:
            match = re.match(r"^-\s+(.+)$", line)
            if match:
                items.append(strip_markdown(match.group(1)))

    return items


def group_items(items: list[str]) -> dict[str, list[str]]:
    grouped: dict[str, list[str]] = {key: [] for key in CATEGORY_ORDER}
    for item in items:
        grouped[classify_item(item)].append(item)
    return grouped


def build_changelog_block(version: str, grouped: dict[str, list[str]]) -> str:
    lines = [
        "    <changelog>",
        f"        <element>{ELEMENT}</element>",
        f"        <type>{COMPONENT_TYPE}</type>",
        f"        <version>{version}</version>",
    ]
    for category in CATEGORY_ORDER:
        category_items = grouped.get(category, [])
        if not category_items:
            continue
        lines.append(f"        <{category}>")
        for item in category_items:
            lines.append(f"            <item>{escape(item)}</item>")
        lines.append(f"        </{category}>")
    lines.append("    </changelog>")
    return "\n".join(lines)


def find_changelog_block(xml: str, version: str) -> tuple[int, int] | None:
    for match in re.finditer(
        r"\n    <changelog>.*?</changelog>",
        xml,
        flags=re.S,
    ):
        if f"<version>{version}</version>" in match.group(0):
            return match.span()
    return None


def main() -> None:
    if len(sys.argv) < 3:
        sys.exit("Usage: sync_changelog_entry.py <changelog.xml> <version> [changelog.md]")

    changelog_path = Path(sys.argv[1])
    version = sys.argv[2]
    markdown_path = (
        Path(sys.argv[3])
        if len(sys.argv) > 3
        else changelog_path.parent / "CHANGELOG.md"
    )

    if not markdown_path.is_file():
        sys.exit(f"CHANGELOG not found: {markdown_path}")

    markdown = markdown_path.read_text(encoding="utf-8")
    items = parse_changelog_section(markdown, version)
    if not items:
        sys.exit(f"No changelog bullets found for version {version} in {markdown_path}")

    grouped = group_items(items)
    new_block = build_changelog_block(version, grouped)
    replacement = "\n" + new_block

    xml = changelog_path.read_text(encoding="utf-8")
    span = find_changelog_block(xml, version)

    if span is None:
        # A function replacement, because the block is changelog prose: a string
        # template would read backslashes in it (e.g. \RuntimeException) as escapes.
        xml = re.sub(
            r"(<changelogs>\n)",
            lambda match: match.group(1) + new_block + "\n",
            xml,
            count=1,
        )
    else:
        existing_block = xml[span[0]:span[1]]
        if existing_block == replacement:
            return
        xml = xml[:span[0]] + replacement + xml[span[1]:]

    changelog_path.write_text(xml, encoding="utf-8")


if __name__ == "__main__":
    main()
