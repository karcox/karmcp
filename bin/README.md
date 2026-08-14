# mcp-proxy.mjs

stdio↔HTTP proxy that connects MCP clients (Claude Desktop, Claude Code, Cursor, etc.) to a **remote** WordPress site running [KarMCP](https://github.com/karcox/karmcp).

MCP clients like Claude Desktop only speak the **stdio** transport and launch their servers as a local subprocess. This proxy runs locally, accepts JSON-RPC over stdio, and forwards it to your WordPress site's MCP HTTP endpoint — handling authentication, the `Mcp-Session-Id` session lifecycle, and pretty/plain permalink detection for you.

> **This is not published to npm and there is no `npx` form.** It is a single file you copy to the machine running your MCP client. If you would rather not manage a file, use `mcp-remote` (a published, general-purpose bridge) or connect over direct HTTP — the **Connection** tab in the admin generates both for you, filled in.

**Reach for this proxy when** you want the multi-site registry below, or when a client struggles with the streamable-HTTP handshake. Otherwise the admin's generated configs are simpler.

## Install

Because the client launches it locally, the proxy must run on the **same machine as your MCP client**, not on the WordPress server:

1. Extract `bin/mcp-proxy.mjs` from the plugin ZIP.
2. Save it somewhere stable on your own machine.
3. Point your client's `args` at that path.

Re-extract it after a plugin update to pick up proxy fixes. Requires Node.js 18+.

## Usage (Claude Desktop)

Add to `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "karmcp": {
      "command": "node",
      "args": ["/absolute/path/to/mcp-proxy.mjs"],
      "env": {
        "WP_URL": "https://your-site.com",
        "WP_USERNAME": "admin",
        "WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "MCP_PROTOCOL_VERSION": "2024-11-05"
      }
    }
  }
}
```

Create the application password at **WordPress Admin → Users → Profile → Application Passwords**.

## Multiple sites (one session, many installs)

This is the reason to prefer this proxy over the alternatives. Instead of the single `WP_URL` set, provide a **site registry** and drive several WordPress installs from one connection. Set `KARMCP_SITES` to a JSON map of aliases → credentials (or point `KARMCP_SITES_FILE` at a JSON file with the same shape):

```json
{
  "mcpServers": {
    "karmcp": {
      "command": "node",
      "args": ["/absolute/path/to/mcp-proxy.mjs"],
      "env": {
        "KARMCP_SITES": "{\"acme\":{\"url\":\"https://acme.com\",\"username\":\"admin\",\"appPassword\":\"xxxx xxxx xxxx xxxx\"},\"globex\":{\"url\":\"https://globex.com\",\"username\":\"admin\",\"appPassword\":\"yyyy yyyy yyyy yyyy\"}}",
        "KARMCP_DEFAULT_SITE": "acme"
      }
    }
  }
}
```

When more than one site is configured, two extra tools appear:

- **`karmcp_list_sites`** — list the configured sites and which one is active.
- **`karmcp_use_site`** — switch the active site (`{ "site": "globex" }`); every subsequent tool call targets it. The proxy keeps a separate session per site and initializes each one transparently on first use.

Single-site `WP_URL` mode is unchanged and does **not** add these tools.

## Environment variables

| Variable | Required | Purpose |
|---|---|---|
| `WP_URL` | single-site | WordPress site URL, e.g. `https://your-site.com` |
| `WP_USERNAME` | single-site | WordPress username |
| `WP_APP_PASSWORD` | single-site | WordPress Application Password |
| `KARMCP_SITES` | multi-site | JSON registry: `{ "alias": { "url", "username", "appPassword" }, … }` |
| `KARMCP_SITES_FILE` | multi-site | Path to a JSON file with the registry (alternative to `KARMCP_SITES`) |
| `KARMCP_DEFAULT_SITE` | no | Alias to start on (defaults to the first entry) |
| `MCP_PROTOCOL_VERSION` | no | Override the protocol version in the `initialize` handshake. Set to `2024-11-05` if your client rejects the adapter's `2025-06-18`. |
| `MCP_LOG_FILE` | no | Path to a debug log file. |

## Tests

```bash
node bin/mcp-proxy.test.mjs
```

## License

GPL-2.0-or-later
