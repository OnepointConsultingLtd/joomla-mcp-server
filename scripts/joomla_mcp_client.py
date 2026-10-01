"""HTTP JSON-RPC client for the Joomla MCP Server component.

Speaks the stateless 2026-07-28 revision by default: every request carries its
protocol version and client capabilities in ``params._meta``, mirrored into the
headers Streamable HTTP requires. Pass ``protocol_version=None`` for the legacy,
initialize-based behaviour.
"""

from __future__ import annotations

import base64
import json
import os
import ssl
import subprocess
import threading
import uuid
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

PROTOCOL_VERSION = "2026-07-28"
LEGACY_PROTOCOL_VERSION = "2025-11-25"
CLIENT_INFO = {"name": "joomla-mcp-eval", "version": "1.0.0"}

META_PROTOCOL_VERSION = "io.modelcontextprotocol/protocolVersion"
META_CLIENT_CAPABILITIES = "io.modelcontextprotocol/clientCapabilities"
META_CLIENT_INFO = "io.modelcontextprotocol/clientInfo"

# Methods whose params.name / params.uri is mirrored into the Mcp-Name header.
_NAME_SOURCES = {"tools/call": "name", "prompts/get": "name", "resources/read": "uri"}


class JoomlaMcpError(RuntimeError):
    pass


def encode_header_value(value: str) -> str:
    """Base64-sentinel encode a value that is not plain visible ASCII."""
    plain = (
        all(0x20 <= ord(char) <= 0x7E for char in value)
        and value == value.strip()
        and not (value.startswith("=?base64?") and value.endswith("?="))
    )
    if plain:
        return value
    return "=?base64?" + base64.b64encode(value.encode("utf-8")).decode("ascii") + "?="


def parse_sse_messages(body: str) -> list[dict[str, Any]]:
    """The JSON-RPC messages of a Server-Sent Events body, in order."""
    messages = []
    for event in body.replace("\r\n", "\n").split("\n\n"):
        data = "\n".join(line[5:].removeprefix(" ") for line in event.split("\n") if line.startswith("data:"))
        if data.strip():
            messages.append(json.loads(data))
    return messages


class JoomlaHttpClient:
    """Call the Joomla MCP JSON-RPC endpoint over HTTP."""

    def __init__(
        self,
        endpoint: str,
        bearer_token: str = "",
        timeout: float = 30.0,
        protocol_version: str | None = PROTOCOL_VERSION,
    ):
        self.endpoint = endpoint
        self.bearer_token = bearer_token
        self.timeout = timeout
        self.protocol_version = protocol_version
        self._request_id = 0

    def _next_id(self) -> int:
        self._request_id += 1
        return self._request_id

    def _message(self, method: str, params: dict[str, Any] | None, request_id: Any) -> dict[str, Any]:
        params = dict(params or {})
        # initialize is the legacy handshake and never carries per-request metadata.
        if self.protocol_version and method != "initialize":
            meta = dict(params.get("_meta") or {})
            meta.setdefault(META_PROTOCOL_VERSION, self.protocol_version)
            meta.setdefault(META_CLIENT_CAPABILITIES, {})
            meta.setdefault(META_CLIENT_INFO, CLIENT_INFO)
            params["_meta"] = meta
        return {"jsonrpc": "2.0", "id": request_id, "method": method, "params": params}

    def _headers(self, message: dict[str, Any]) -> dict[str, str]:
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "Mcp-Method": message["method"],
        }
        if self.bearer_token:
            headers["Authorization"] = f"Bearer {self.bearer_token}"
        params = message["params"]
        version = (params.get("_meta") or {}).get(META_PROTOCOL_VERSION)
        if version:
            headers["MCP-Protocol-Version"] = version
        name_field = _NAME_SOURCES.get(message["method"])
        if name_field and isinstance(params.get(name_field), str):
            headers["Mcp-Name"] = encode_header_value(params[name_field])
        return headers

    def call(self, method: str, params: dict[str, Any] | None = None) -> Any:
        payload = self._message(method, params, self._next_id())
        headers = self._headers(payload)

        request = Request(
            self.endpoint,
            data=json.dumps(payload).encode("utf-8"),
            headers=headers,
            method="POST",
        )

        try:
            context = None
            if os.environ.get("MCP_IGNORE_SSL") == "1":
                context = ssl._create_unverified_context()
            with urlopen(request, timeout=self.timeout, context=context) as response:
                body = response.read().decode("utf-8")
                content_type = response.headers.get("Content-Type", "")
        except HTTPError as exc:
            detail = exc.read().decode("utf-8", errors="replace")
            raise JoomlaMcpError(f"HTTP {exc.code}: {detail[:500]}") from exc
        except URLError as exc:
            raise JoomlaMcpError(str(exc)) from exc

        if not body.strip():
            return None

        if content_type.startswith("text/event-stream"):
            # The response is the stream's message carrying our request id;
            # anything before it is a request-scoped notification.
            responses = [m for m in parse_sse_messages(body) if m.get("id") == payload["id"]]
            if not responses:
                raise JoomlaMcpError(f"No response for {method} in the event stream")
            data = responses[-1]
        else:
            data = json.loads(body)
        if "error" in data:
            error = data["error"]
            message = error.get("message", "Unknown JSON-RPC error")
            raise JoomlaMcpError(message)

        return data.get("result")

    def discover(self) -> dict[str, Any]:
        """server/discover: the server's supported versions, capabilities and identity."""
        return self.call("server/discover")

    def initialize(self) -> dict[str, Any]:
        """Send the legacy handshake.

        The server keeps no handshake state, so this does not switch the client
        to legacy semantics: construct it with ``protocol_version=None`` for that.
        """
        return self.call(
            "initialize",
            {"protocolVersion": LEGACY_PROTOCOL_VERSION, "capabilities": {}, "clientInfo": CLIENT_INFO},
        )

    def _list_paginated(self, method: str, items_key: str) -> list[dict[str, Any]]:
        items: list[dict[str, Any]] = []
        cursor: str | None = None

        while True:
            params: dict[str, Any] = {}
            if cursor is not None:
                params["cursor"] = cursor

            result = self.call(method, params)
            if not isinstance(result, dict):
                break

            page = result.get(items_key, [])
            if isinstance(page, list):
                items.extend(page)

            cursor = result.get("nextCursor")
            if not cursor:
                break

        return items

    def list_tools(self) -> list[dict[str, Any]]:
        return self._list_paginated("tools/list", "tools")

    def list_resources(self) -> list[dict[str, Any]]:
        return self._list_paginated("resources/list", "resources")

    def list_resource_templates(self) -> list[dict[str, Any]]:
        return self._list_paginated("resources/templates/list", "resourceTemplates")

    def list_prompts(self) -> list[dict[str, Any]]:
        return self._list_paginated("prompts/list", "prompts")

    def read_resource(self, uri: str) -> Any:
        return self.call("resources/read", {"uri": uri})

    def get_prompt(self, name: str, arguments: dict[str, Any] | None = None) -> Any:
        return self.call("prompts/get", {"name": name, "arguments": arguments or {}})

    def call_tool(self, name: str, arguments: dict[str, Any] | None = None) -> Any:
        result = self.call("tools/call", {"name": name, "arguments": arguments or {}})
        if not isinstance(result, dict):
            return result

        if result.get("isError"):
            content = result.get("content", [])
            if content and isinstance(content[0], dict):
                raise JoomlaMcpError(content[0].get("text", "Tool error"))
            raise JoomlaMcpError("Tool error")

        if "structuredContent" in result:
            return result["structuredContent"]

        content = result.get("content", [])
        if content and isinstance(content[0], dict) and content[0].get("type") == "text":
            text = content[0].get("text", "")
            try:
                return json.loads(text)
            except json.JSONDecodeError:
                return text

        return result


class JoomlaBridgeClient(JoomlaHttpClient):
    """Speak JSON-RPC over the Node stdio bridge process."""

    def __init__(
        self,
        bridge_script: str,
        endpoint: str,
        bearer_token: str = "",
        timeout: float = 30.0,
        protocol_version: str | None = PROTOCOL_VERSION,
    ):
        super().__init__(endpoint, bearer_token, timeout, protocol_version)
        self.bridge_script = bridge_script
        self._process: subprocess.Popen[str] | None = None
        self._lock = threading.Lock()
        self._pending: dict[str, Any] = {}

    def start(self) -> None:
        args = ["node", self.bridge_script, self.endpoint]
        if self.bearer_token:
            args.append(self.bearer_token)
        self._process = subprocess.Popen(
            args,
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            bufsize=1,
        )
        reader = threading.Thread(target=self._read_stdout, daemon=True)
        reader.start()

    def stop(self) -> None:
        if self._process and self._process.poll() is None:
            self._process.terminate()
            self._process.wait(timeout=5)
        self._process = None

    def _read_stdout(self) -> None:
        assert self._process and self._process.stdout
        for line in self._process.stdout:
            line = line.strip()
            if not line:
                continue
            try:
                message = json.loads(line)
            except json.JSONDecodeError:
                continue
            request_id = message.get("id")
            with self._lock:
                if request_id in self._pending:
                    self._pending[request_id] = message

    def call(self, method: str, params: dict[str, Any] | None = None) -> Any:
        if not self._process or not self._process.stdin:
            raise JoomlaMcpError("Bridge process is not running")

        request_id = str(uuid.uuid4())
        # No headers on stdio: the bridge derives them from the body.
        payload = self._message(method, params, request_id)

        with self._lock:
            self._pending[request_id] = None

        self._process.stdin.write(json.dumps(payload) + "\n")
        self._process.stdin.flush()

        import time

        deadline = time.time() + self.timeout
        while time.time() < deadline:
            with self._lock:
                message = self._pending.get(request_id)
            if message is not None:
                with self._lock:
                    self._pending.pop(request_id, None)
                if "error" in message:
                    raise JoomlaMcpError(message["error"].get("message", "Bridge error"))
                return message.get("result")
            time.sleep(0.05)

        raise JoomlaMcpError(f"Timed out waiting for bridge response to {method}")


def anthropic_tools(tools: list[dict[str, Any]]) -> list[dict[str, Any]]:
    return [
        {
            "name": tool["name"],
            "description": tool.get("description", ""),
            "input_schema": tool.get("inputSchema") or tool.get("input_schema") or {"type": "object", "properties": {}},
        }
        for tool in tools
    ]
