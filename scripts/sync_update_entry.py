#!/usr/bin/env python3
"""Maintain the Joomla update feed (update.xml).

Ensures an <update> block exists for a given version (prepended, newest first)
and, when a checksum is supplied, sets/replaces that block's <sha256> element so
Joomla can validate the downloaded package integrity.

Usage:
    sync_update_entry.py <update.xml> <version> [sha256]

Idempotent: re-running with the same arguments leaves the file unchanged.

Every entry carries <changelogurl>, which is what makes the Changelog column in
Extensions: Update render a link instead of "N/A": Joomla's UpdateAdapter copies
that element into #__updates.changelogurl, and the view keys the link off that
column. The <changelogurl> in mcpserver.xml populates a different column
(#__extensions.changelogurl, which supplies the modal's contents) and does not
substitute for it.
"""

import re
import sys

ENTRY_TEMPLATE = """    <update>
        <name>MCP Server for Joomla</name>
        <description>Model Context Protocol server component for Joomla</description>
        <element>com_mcpserver</element>
        <type>component</type>
        <client>administrator</client>
        <version>{version}</version>
        <infourl title="MCP Server for Joomla {version}">https://github.com/OnepointConsultingLtd/joomla-mcp-server/releases/tag/v{version}</infourl>
        <changelogurl>https://raw.githubusercontent.com/OnepointConsultingLtd/joomla-mcp-server/master/changelog.xml</changelogurl>
        <downloads>
            <downloadurl type="full" format="zip">https://github.com/OnepointConsultingLtd/joomla-mcp-server/releases/download/v{version}/com_mcpserver-{version}.zip</downloadurl>
        </downloads>
        <tags>
            <tag>stable</tag>
        </tags>
        <targetplatform name="joomla" version="((4\\.)|(5\\.)|(6\\.))" />
        <php_minimum>8.1</php_minimum>
        <maintainer>Onepoint Consulting Ltd</maintainer>
        <maintainerurl>https://www.onepointltd.com</maintainerurl>
    </update>
"""


def find_block(xml, version):
    """Return (start, end) span of the <update> block for version, or None."""
    for m in re.finditer(r"<update>.*?</update>", xml, flags=re.S):
        if f"<version>{version}</version>" in m.group(0):
            return m.span()
    return None


def main():
    if len(sys.argv) < 3:
        sys.exit("Usage: sync_update_entry.py <update.xml> <version> [sha256]")

    path, version = sys.argv[1], sys.argv[2]
    sha256 = sys.argv[3] if len(sys.argv) > 3 else None

    with open(path, encoding="utf-8") as f:
        xml = f.read()

    if find_block(xml, version) is None:
        # Prepend a fresh entry right after the <updates> root element.
        xml = re.sub(
            r"(<updates>\n)",
            r"\1" + ENTRY_TEMPLATE.format(version=version),
            xml,
            count=1,
        )

    if sha256:
        span = find_block(xml, version)
        block = xml[span[0]:span[1]]
        sha_line = f"        <sha256>{sha256}</sha256>"
        if "<sha256>" in block:
            block = re.sub(r"[ \t]*<sha256>.*?</sha256>", sha_line, block)
        elif "<targetplatform" in block:
            block = block.replace(
                "        <targetplatform",
                sha_line + "\n        <targetplatform",
                1,
            )
        else:
            block = block.replace(
                "</downloads>", "</downloads>\n" + sha_line, 1
            )
        xml = xml[:span[0]] + block + xml[span[1]:]

    with open(path, "w", encoding="utf-8") as f:
        f.write(xml)


if __name__ == "__main__":
    main()
