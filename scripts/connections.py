"""Connection adapters for evaluating the Joomla MCP Server."""

from __future__ import annotations

from typing import Any

from joomla_mcp_client import JoomlaBridgeClient, JoomlaHttpClient, anthropic_tools


class JoomlaMcpConnection:
    """Async-friendly wrapper around the Joomla HTTP JSON-RPC client."""

    def __init__(self, endpoint: str, bearer_token: str = "", bridge_script: str = ""):
        self.endpoint = endpoint
        self.bearer_token = bearer_token
        self.bridge_script = bridge_script
        self._client: JoomlaHttpClient | JoomlaBridgeClient | None = None
        self._tools: list[dict[str, Any]] = []

    async def __aenter__(self) -> "JoomlaMcpConnection":
        if self.bridge_script:
            client: JoomlaHttpClient | JoomlaBridgeClient = JoomlaBridgeClient(
                self.bridge_script,
                self.endpoint,
                self.bearer_token,
            )
            client.start()
        else:
            client = JoomlaHttpClient(self.endpoint, self.bearer_token)

        client.discover()
        self._client = client
        self._tools = anthropic_tools(client.list_tools())
        return self

    async def __aexit__(self, exc_type, exc_val, exc_tb) -> None:
        if isinstance(self._client, JoomlaBridgeClient):
            self._client.stop()
        self._client = None

    async def list_tools(self) -> list[dict[str, Any]]:
        return self._tools

    async def call_tool(self, tool_name: str, arguments: dict[str, Any]) -> Any:
        if self._client is None:
            raise RuntimeError("Connection is not open")
        return self._client.call_tool(tool_name, arguments)


def create_connection(
    transport: str,
    endpoint: str = "",
    bearer_token: str = "",
    bridge_script: str = "",
) -> JoomlaMcpConnection:
    transport = transport.lower()
    if transport in {"http", "bridge"}:
        if not endpoint:
            raise ValueError("Endpoint URL is required")
        return JoomlaMcpConnection(
            endpoint=endpoint,
            bearer_token=bearer_token,
            bridge_script=bridge_script if transport == "bridge" else "",
        )
    raise ValueError("Supported transports: http, bridge")
