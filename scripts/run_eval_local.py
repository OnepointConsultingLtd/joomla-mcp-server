#!/usr/bin/env python3
"""Verify evaluation answers programmatically without an LLM."""

from __future__ import annotations

import argparse
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from joomla_mcp_client import JoomlaHttpClient, JoomlaMcpError
from resolve_eval_answers import build_qa_pairs


def load_expected(path: Path) -> list[dict[str, str]]:
    tree = ET.parse(path)
    pairs = []
    for qa_pair in tree.getroot().findall(".//qa_pair"):
        question = (qa_pair.findtext("question") or "").strip()
        answer = (qa_pair.findtext("answer") or "").strip()
        pairs.append({"question": question, "answer": answer})
    return pairs


def main() -> int:
    parser = argparse.ArgumentParser(description="Run deterministic Joomla MCP evaluation")
    parser.add_argument("eval_file", type=Path)
    parser.add_argument("-u", "--url", required=True)
    parser.add_argument("-t", "--token", default="")
    args = parser.parse_args()

    client = JoomlaHttpClient(args.url, args.token)
    try:
        client.discover()
        actual_pairs = build_qa_pairs(client, for_verification=True)
    except JoomlaMcpError as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1

    expected_pairs = load_expected(args.eval_file)
    if len(actual_pairs) != len(expected_pairs):
        print(
            f"Warning: expected {len(expected_pairs)} pairs, resolved {len(actual_pairs)}",
            file=sys.stderr,
        )

    correct = 0
    print("# Local Joomla MCP Evaluation\n")
    for index, (expected, actual) in enumerate(zip(expected_pairs, actual_pairs), start=1):
        match = expected["answer"] == actual["answer"]
        correct += int(match)
        status = "PASS" if match else "FAIL"
        print(f"## Task {index} — {status}")
        print(f"**Question**: {expected['question']}")
        print(f"**Expected**: `{expected['answer']}`")
        print(f"**Actual**: `{actual['answer']}`\n")

    total = len(expected_pairs)
    print(f"**Accuracy**: {correct}/{total} ({(correct / total * 100) if total else 0:.1f}%)")
    return 0 if correct == total else 1


if __name__ == "__main__":
    raise SystemExit(main())
