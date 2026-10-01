#!/usr/bin/env python3
"""Resolve evaluation answers by calling a live Joomla MCP endpoint."""

from __future__ import annotations

import argparse
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from joomla_mcp_client import JoomlaHttpClient, JoomlaMcpError
from pagination_eval import (
    articles_page_two_has_items,
    expected_articles_page_two_has_items,
    expected_pagination_has_more_matches_meta,
    expected_search_articles_page_overlap,
    expected_walk_exceeds_first_api_page,
    pagination_has_more_matches_meta,
    search_articles_page_overlap,
    walk_exceeds_first_api_page,
)


def _enabled_templates(client: JoomlaHttpClient, site_client: str) -> list[dict]:
    result = client.call_tool("list_installed_templates", {"client": site_client})
    rows = result.get("data", []) if isinstance(result, dict) else []
    return [row for row in rows if row.get("enabled")]


def _main_menu_menutype(client: JoomlaHttpClient) -> str:
    result = client.call_tool("list_menus", {"client": "site"})
    rows = result.get("data", []) if isinstance(result, dict) else []
    for row in rows:
        attrs = row.get("attributes", row)
        title = attrs.get("title", "")
        if title == "Main Menu":
            return str(attrs.get("menutype", ""))
    raise JoomlaMcpError("Main Menu not found")


def _content_language_sef(client: JoomlaHttpClient, lang_code: str) -> str:
    result = client.call_tool("list_content_languages", {"published": 1})
    rows = result.get("data", []) if isinstance(result, dict) else []
    for row in rows:
        attrs = row.get("attributes", row)
        if attrs.get("lang_code") == lang_code:
            return str(attrs.get("sef", ""))
    raise JoomlaMcpError(f"Published content language {lang_code} not found")


def _safe_content_language_sef(client: JoomlaHttpClient, lang_code: str) -> str:
    try:
        return _content_language_sef(client, lang_code)
    except JoomlaMcpError:
        return "NOT_FOUND"


def _cassiopeia_style_count(client: JoomlaHttpClient) -> int:
    result = client.call_tool("list_template_styles", {"client": "site"})
    rows = result.get("data", []) if isinstance(result, dict) else []
    count = 0
    for row in rows:
        attrs = row.get("attributes", row)
        if attrs.get("template") == "cassiopeia":
            count += 1
    return count


def build_qa_pairs(client: JoomlaHttpClient, *, for_verification: bool = False) -> list[dict[str, str]]:
    tools = client.list_tools()
    read_only_count = sum(
        1 for tool in tools if (tool.get("annotations") or {}).get("readOnlyHint") is True
    )

    site_templates = _enabled_templates(client, "site")
    site_template_names = sorted(str(row.get("element", "")).lower() for row in site_templates if row.get("element"))
    admin_has_atum = any(row.get("element") == "atum" for row in _enabled_templates(client, "administrator"))

    languages = client.call_tool("list_installed_languages", {})
    default_language = ""
    if isinstance(languages, dict):
        default_language = str((languages.get("meta") or {}).get("application_default", ""))

    article = client.call_tool("get_article_by_id", {"id": 1})
    article_attrs = {}
    if isinstance(article, dict):
        data = article.get("data", {})
        article_attrs = data.get("attributes", data) if isinstance(data, dict) else {}

    associations = client.call_tool("list_article_associations", {"id": 1})
    association_count = 0
    if isinstance(associations, dict) and isinstance(associations.get("data"), list):
        association_count = len(associations["data"])

    return [
        {
            "question": (
                "How many tools does this MCP server expose? Use tools/list and return only the integer."
            ),
            "answer": str(len(tools)),
        },
        {
            "question": (
                "Inspect every tool returned by tools/list. How many tools have readOnlyHint set to true "
                "in their annotations? Return only the integer."
            ),
            "answer": str(read_only_count),
        },
        {
            "question": (
                "Using list_installed_templates with client set to site, consider only enabled templates. "
                "Which template element name comes first alphabetically? Return only the element name in lowercase."
            ),
            "answer": site_template_names[0] if site_template_names else "NOT_FOUND",
        },
        {
            "question": (
                "Using list_installed_templates with client set to administrator, is there an installed template "
                "whose element is exactly 'atum'? Answer True or False only."
            ),
            "answer": "True" if admin_has_atum else "False",
        },
        {
            "question": (
                "Call list_installed_languages and read meta.application_default. "
                "Return only the default language tag."
            ),
            "answer": default_language,
        },
        {
            "question": (
                "List site menus and find the menu whose title is exactly 'Main Menu'. "
                "Return only its menutype alias."
            ),
            "answer": _main_menu_menutype(client),
        },
        {
            "question": (
                "Retrieve article ID 1 with get_article_by_id. Return only its alias."
            ),
            "answer": str(article_attrs.get("alias", "NOT_FOUND")),
        },
        {
            "question": (
                "Among published content languages (published=1), what is the SEF prefix for the language "
                "with lang_code en-GB? Return only the SEF value."
            ),
            "answer": _safe_content_language_sef(client, "en-GB"),
        },
        {
            "question": (
                "List site template styles and count how many styles use template element 'cassiopeia'. "
                "Return only the integer."
            ),
            "answer": str(_cassiopeia_style_count(client)),
        },
        {
            "question": (
                "For article ID 1, how many associated items are returned by list_article_associations? "
                "Return only the integer."
            ),
            "answer": str(association_count),
        },
        {
            "question": (
                "Call search_articles with no limit or offset. Does pagination.has_more equal "
                "(meta.total-pages > 1)? Answer True or False only."
            ),
            "answer": (
                pagination_has_more_matches_meta(client, "search_articles", {})
                if for_verification
                else expected_pagination_has_more_matches_meta()
            ),
        },
        {
            "question": (
                "Using list_menu_items with client set to site and no limit or offset, does pagination.has_more "
                "equal (meta.total-pages > 1)? Answer True or False only."
            ),
            "answer": (
                pagination_has_more_matches_meta(client, "list_menu_items", {"client": "site"})
                if for_verification
                else expected_pagination_has_more_matches_meta()
            ),
        },
        {
            "question": (
                "Call search_articles with limit 5 and offset 20. If meta.total-pages on a no-limit "
                "search_articles call is greater than 1, is pagination.count greater than 0? "
                "Answer True or False only."
            ),
            "answer": (
                articles_page_two_has_items(client, 5, 20)
                if for_verification
                else expected_articles_page_two_has_items(client)
            ),
        },
        {
            "question": (
                "Call search_articles twice with limit 5: once at offset 0 and once at offset 20. "
                "Do the two result sets share any article ID? Answer True or False only."
            ),
            "answer": (
                search_articles_page_overlap(client, 5, 0, 20)
                if for_verification
                else expected_search_articles_page_overlap(client)
            ),
        },
        {
            "question": (
                "Page through all search_articles results using limit 5 and pagination.next_offset until "
                "has_more is false. If the first no-limit search_articles call has meta.total-pages greater "
                "than 1, is the unique article count greater than the first page item count? "
                "Answer True or False only."
            ),
            "answer": (
                walk_exceeds_first_api_page(client, 5)
                if for_verification
                else expected_walk_exceeds_first_api_page(client)
            ),
        },
    ]


def write_evaluation(path: Path, qa_pairs: list[dict[str, str]]) -> None:
    root = ET.Element("evaluation")
    for pair in qa_pairs:
        qa = ET.SubElement(root, "qa_pair")
        question = ET.SubElement(qa, "question")
        question.text = pair["question"]
        answer = ET.SubElement(qa, "answer")
        answer.text = pair["answer"]

    tree = ET.ElementTree(root)
    ET.indent(tree, space="   ")
    path.write_text('<?xml version="1.0" encoding="UTF-8"?>\n' + ET.tostring(root, encoding="unicode") + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Resolve Joomla MCP evaluation answers from a live site")
    parser.add_argument("-u", "--url", required=True, help="MCP JSON-RPC endpoint URL")
    parser.add_argument("-t", "--token", default="", help="MCP bearer token")
    parser.add_argument(
        "-o",
        "--output",
        type=Path,
        default=Path("evaluations/joomla-mcp-eval.xml"),
        help="Output evaluation XML path",
    )
    args = parser.parse_args()

    client = JoomlaHttpClient(args.url, args.token)
    try:
        client.discover()
        qa_pairs = build_qa_pairs(client)
    except JoomlaMcpError as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1

    args.output.parent.mkdir(parents=True, exist_ok=True)
    write_evaluation(args.output, qa_pairs)

    print(f"Wrote {len(qa_pairs)} qa pairs to {args.output}")
    for index, pair in enumerate(qa_pairs, start=1):
        print(f"{index}. {pair['answer']}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
