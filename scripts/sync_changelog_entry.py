#!/usr/bin/env python3
"""Maintain the Joomla changelog feed (changelog.xml) from CHANGELOG.md.

Ensures a <changelog> block exists for a given version (prepended, newest first),
generated from the matching ## <version> section in CHANGELOG.md. Bullet items are
classified as addition (Added…), fix (Fixed…), or change (everything else).

Usage:
    sync_changelog_entry.py <changelog.xml> <version> [changelog.md]

Idempotent: re-running with the same arguments leaves the file unchanged.

Each version gets one block per extension in TARGETS. From 1.12.0 the release is
the pkg_mcpserver package; the component block is kept too, because sites
upgrading from a component-only install are offered the update on the component.
"""

import re
import sys
from pathlib import Path
from xml.sax.saxutils import escape

# (element, type) pairs, in the order their blocks appear in the feed.
TARGETS = (("pkg_mcpserver", "package"), ("com_mcpserver", "component"))
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


def build_changelog_block(version: str, grouped: dict[str, list[str]], element: str, kind: str) -> str:
    lines = [
        "    <changelog>",
        f"        <element>{element}</element>",
        f"        <type>{kind}</type>",
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


def find_changelog_block(xml: str, version: str, element: str) -> tuple[int, int] | None:
    for match in re.finditer(
        r"\n    <changelog>.*?</changelog>",
        xml,
        flags=re.S,
    ):
        block = match.group(0)
        if f"<version>{version}</version>" in block and f"<element>{element}</element>" in block:
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
    xml = changelog_path.read_text(encoding="utf-8")
    original = xml

    # Reversed, because each new block is prepended: TARGETS order is feed order.
    for element, kind in reversed(TARGETS):
        new_block = build_changelog_block(version, grouped, element, kind)
        span = find_changelog_block(xml, version, element)

        if span is None:
            # A function replacement, because the block is changelog prose: a string
            # template would read backslashes in it (e.g. \RuntimeException) as escapes.
            xml = re.sub(
                r"(<changelogs>\n)",
                lambda match, block=new_block: match.group(1) + block + "\n",
                xml,
                count=1,
            )
        else:
            xml = xml[:span[0]] + "\n" + new_block + xml[span[1]:]

    if xml == original:
        return

    changelog_path.write_text(xml, encoding="utf-8")


if __name__ == "__main__":
    main()
