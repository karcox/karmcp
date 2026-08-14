=== KarMCP ===
Contributors: karmcp
Tags: elementor, mcp, ai, page-builder, automation
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your WordPress site into something an AI agent can operate: pages, content, media, users, and diagnostics, all exposed as MCP tools.

== Description ==

KarMCP exposes your WordPress site as **MCP (Model Context Protocol) tools**, so Claude, Cursor, and any other MCP-compatible client can build Elementor pages, write content, manage the site, and audit it — driving WordPress through its own APIs rather than a browser.

It builds on the official WordPress MCP Adapter, which ships bundled. The MCP endpoint is `/wp-json/mcp/karmcp-server` and tool names are prefixed `karmcp-`.

There is no telemetry and no auto-updater: the plugin makes no outbound calls unless you configure one. Third-party copyright notices are in the bundled NOTICE file.

**Elementor is optional.** Every WordPress domain below works without it; installing Elementor unlocks the page-building family.

**Build pages**

* **Elementor**: containers, widgets, templates, global colours and typography, and a one-call `build-page` that renders a whole declarative page structure.
* **Widget catalog**: discover → inspect → act (`list-widgets` → `get-widget-schema` → `add-free-widget` / `add-pro-widget` → `update-widget`). Curated parameters for 62 widgets live in a built-in catalog, so every widget stays reachable while the per-turn tool-list cost stays small. `add-pro-widget` registers only when Elementor Pro is active.
* **Atomic elements (Elementor 4.0+)**: dedicated tools for flexbox, div-block, heading, paragraph, button, image, SVG, YouTube, video and divider, plus universal add/update and `detect-elementor-version`.
* **Gutenberg blocks**: list block types and patterns, read a post's block tree, and add, update, move, duplicate or remove blocks by index path.
* **Theme builder**: builder-agnostic headers, footers, single, archive, search and 404 layouts with display conditions, injected on the front end.

**Run the site**

* **Content**: posts, pages and any custom post type — content, status, taxonomy terms, custom fields and featured images. Never touches Elementor data; every post carries an `is_elementor` flag so agents switch tools correctly.
* **Settings**: read and batch-update core settings over a curated allowlist. No arbitrary option access; `admin_email` stays read-only.
* **Plugins & themes**: discover, install (wordpress.org only), update, activate and delete. KarMCP and Elementor are protected from deactivation.
* **Media**: full attachment detail, metadata editing, and deletion behind an explicit confirmation.
* **Users**: list and read users; create and edit non-admin profiles. No delete, no role changes, administrators off-limits, passwords auto-generated and emailed rather than returned.
* **Nav menus, redirects, filesystem and database**, each behind its own capability and default-off switches for anything that writes.

**Understand and undo**

* **Page snapshot**: one normalized digest of a page in a single call.
* **Change ledger with rollback**: every recorded change is reversible from the History tab, across Elementor, filesystem and database.
* **Content search** over your own pages and templates, and **content mirror** for git-trackable JSON exports.
* **Performance analyzer**: server config, WordPress internals (database size, autoloaded options, revisions, cron backlog, object cache, OPcache) and a target page, returned as a scored report (0-100 plus A–F) with ranked recommendations. Read-only.
* **Security scanner**: malware heuristics, core-file integrity against official checksums, hardening checks and outdated software, returned as a scored report. The malware walk is bounded and never returns full file contents. Read-only.

**Speak your plugins**

Integrations register only when their plugin is active, and follow a fixed two-tool shape (`<plugin>-read` / `<plugin>-write`) to keep the tool list small: **ACF / ACF PRO**, **Meta Box**, **Contact Form 7**, **Slim SEO**, **Astra**, **Spectra**, plus an active-theme integration that works with any theme.

**Requires:**

* WordPress 6.9 or later (the Abilities API is in core)
* PHP 8.1 or later
* The WordPress MCP Adapter, bundled with the plugin — no separate install

**Recommended (optional):**

* Elementor 3.20 or later (4.0+ for atomic elements)

== Installation ==

1. Upload the `karmcp` folder to `/wp-content/plugins/`, or install the ZIP from **Plugins → Add New → Upload Plugin**.
2. Activate the plugin. The MCP Adapter is bundled and WordPress 6.9+ already includes the Abilities API, so there is nothing else to install.
3. Open the **KarMCP** top-level menu, go to the **Connection** tab, and confirm the MCP server is enabled.
4. (Optional) Install [Elementor](https://wordpress.org/plugins/elementor/) to enable the page-building family.

The **Connection** tab generates a ready-to-paste config for your client, including a one-click bundle for Claude Desktop. The examples below are the same configs by hand.

= WP-CLI stdio (local development) =

`
{
  "mcpServers": {
    "karmcp": {
      "command": "wp",
      "args": ["mcp-adapter", "serve", "--server=karmcp-server", "--user=admin", "--path=/path/to/wordpress"]
    }
  }
}
`

= Remote sites over stdio =

For a client that only speaks stdio, bridge it with `mcp-remote`. Create an Application Password at **Users → Profile → Application Passwords** first:

`
{
  "mcpServers": {
    "karmcp": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://your-site.com/wp-json/mcp/karmcp-server",
        "--header",
        "Authorization: Basic BASE64_ENCODED_CREDENTIALS"
      ]
    }
  }
}
`

The plugin also bundles its own proxy at `bin/mcp-proxy.mjs` for driving several sites from one connection; see `bin/README.md`.

= Direct HTTP =

`
{
  "servers": {
    "karmcp": {
      "type": "http",
      "url": "https://your-site.com/wp-json/mcp/karmcp-server",
      "headers": { "Authorization": "Basic BASE64_ENCODED_CREDENTIALS" }
    }
  }
}
`

== Frequently Asked Questions ==

= What is MCP? =

MCP (Model Context Protocol) is an open standard that lets AI tools interact with external services. This plugin exposes WordPress and Elementor capabilities as MCP tools.

= Does this work without Elementor? =

Yes. Every WordPress domain — content, media, users, settings, plugins, diagnostics, Gutenberg — works with no page builder installed. Elementor only adds the Elementor tool family.

= Does it work without Elementor Pro? =

Yes. Free and core widgets go through `add-free-widget`. The `add-pro-widget` tool registers only when Elementor Pro is active.

= Can I disable specific tools? =

Yes. Open **KarMCP → Tools** and toggle any tool. If your MCP client caps the number of tools, turn on **Compact tool mode** on the same screen: the server then exposes three meta-tools (`list-tools`, `get-tool-schema`, `call-tool`) instead of the full list, and your per-tool toggles still decide what `call-tool` will run.

= Is it safe to use on a production site? =

Every tool runs a real WordPress capability check first, so an agent can only do what the authenticating user could do by hand. Anything that writes, deletes, or renders site-wide ships **disabled** and is opt-in; destructive operations additionally require an explicit confirmation. Administrators cannot be edited over MCP, there is no delete-user tool, and filesystem access is confined to the WordPress root with automatic backups and an audit log.

= A remote MCP client intermittently says the server isn't responding =

On shared LiteSpeed hosting this is usually the host caching or timing out the request rather than the plugin. Exclude `/wp-json/mcp/` from the host cache, raise PHP `max_execution_time` to 60 or more, and check **KarMCP → MCP Log** (with `WP_DEBUG` on it records the underlying error) to tell a real error from a transport timeout. For large operations use `build-page` with `dry_run`, `sideload-image` with `convert_webp:false`, and `get-page-structure` with `summary:true`.

== Screenshots ==

1. Tools management page with category-grouped toggles.
2. Connection configuration page with copy-paste configs.

== Changelog ==

= 1.0.0 =

* Independent release under the KarMCP name, with its own MCP namespace (`karmcp/`) and server route (`/wp-json/mcp/karmcp-server`).
* No telemetry, no licensing SDK, and no auto-updater: the plugin makes no outbound network calls unless you configure one.
* Theme builder: unlimited templates per type, granular display conditions (per post, term, author, date), exclude rules and working priority.
* Fixed: the theme builder's Hello Elementor adapter never injected anything, because it targeted hooks that theme does not fire.
* Fixed: the theme-template post type exceeded WordPress's 20-character limit, so it never registered and every template insert failed.
* Removed a "Backup & Migrate" tool category whose implementation is not part of this build; it was showing seven toggles that could never do anything and inflating the dashboard's tool counters.
* Fixed a failing announcements request that retried every two hours on installs with no service configured.
