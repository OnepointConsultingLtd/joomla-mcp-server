#!/usr/bin/env python3
"""Maintain the Joomla update feed (update.xml).

Ensures an <update> block exists for a given version (prepended, newest first)
and, when a checksum is supplied, sets/replaces that block's <sha256> element so
Joomla can validate the downloaded package integrity.

Usage:
    sync_update_entry.py <update.xml> <version> [sha256]

Idempotent: re-running with the same arguments leaves the file unchanged.

From 1.12.0 the release is the pkg_mcpserver package (the component plus the
system plugin that serves the OAuth URLs), so every version gets a package
entry. MIGRATION_VERSION also gets a component entry whose download is that
same package: sites with the component installed on its own (1.11.x and
earlier) are only ever offered updates for the component, and installing the
package from it puts them on the package's update stream. Later versions have
no component entry, so a packaged site is never offered the same update twice.

Every entry carries <changelogurl>, which is what makes the Changelog column in
Extensions: Update render a link instead of "N/A": Joomla's UpdateAdapter copies
that element into #__updates.changelogurl, and the view keys the link off that
column. The <changelogurl> in mcpserver.xml populates a different column
(#__extensions.changelogurl, which supplies the modal's contents) and does not
substitute for it.
"""

import re
import sys

MIGRATION_VERSION = "1.12.0"

ENTRY_TEMPLATE = """    <update>
        <name>MCP Server for Joomla</name>
        <description>Model Context Protocol server for Joomla</description>
        <element>{element}</element>
        <type>{kind}</type>
        <client>{client}</client>
        <version>{version}</version>
        <infourl title="MCP Server for Joomla {version}">https://github.com/OnepointConsultingLtd/joomla-mcp-server/releases/tag/v{version}</infourl>
        <changelogurl>https://raw.githubusercontent.com/OnepointConsultingLtd/joomla-mcp-server/master/changelog.xml</changelogurl>
        <downloads>
            <downloadurl type="full" format="zip">https://github.com/OnepointConsultingLtd/joomla-mcp-server/releases/download/v{version}/pkg_mcpserver-{version}.zip</downloadurl>
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

PACKAGE = {"element": "pkg_mcpserver", "kind": "package", "client": "site"}
COMPONENT = {"element": "com_mcpserver", "kind": "component", "client": "administrator"}


def targets(version):
    """The extensions an entry for this version is written for, in feed order."""
    return [PACKAGE, COMPONENT] if version == MIGRATION_VERSION else [PACKAGE]


def find_block(xml, version, element):
    """Return (start, end) span of the <update> block for version and element, or None."""
    for m in re.finditer(r"<update>.*?</update>", xml, flags=re.S):
        block = m.group(0)
        if f"<version>{version}</version>" in block and f"<element>{element}</element>" in block:
            return m.span()
    return None


def main():
    if len(sys.argv) < 3:
        sys.exit("Usage: sync_update_entry.py <update.xml> <version> [sha256]")

    path, version = sys.argv[1], sys.argv[2]
    sha256 = sys.argv[3] if len(sys.argv) > 3 else None

    with open(path, encoding="utf-8") as f:
        xml = f.read()

    # Reversed, because each new entry is prepended: targets() order is feed order.
    for target in reversed(targets(version)):
        if find_block(xml, version, target["element"]) is None:
            # Prepend a fresh entry right after the <updates> root element.
            entry = ENTRY_TEMPLATE.format(version=version, **target)
            xml = re.sub(r"(<updates>\n)", lambda m, e=entry: m.group(1) + e, xml, count=1)

    if sha256:
        # Every entry for this version downloads the same package.
        for target in targets(version):
            span = find_block(xml, version, target["element"])
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
