<h1 align="center">
  <img src="assets/img/karmcp-mark.svg" alt="" width="56" height="56"><br>
  KarMCP<br>
  <sub>MCP Tools for WordPress &amp; Page Builders</sub>
</h1>

<div align="center">

[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-8892BF.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-%3E%3D6.9-21759B.svg)](https://wordpress.org)

</div>

Turn your WordPress site into something an AI agent can actually operate.

KarMCP is a WordPress plugin that exposes your site as **[MCP](https://modelcontextprotocol.io/) tools**, so Claude, Cursor, and any other MCP client can build Elementor pages, write content, manage plugins and users, audit performance and security, and drive the plugins you already run. It builds on the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter), which ships bundled.

## What it does

**Build pages.** The full Elementor workflow — containers, widgets, templates, global styles, and atomic elements for Elementor 4.0+. Also Gutenberg blocks, and a builder-agnostic theme builder for headers, footers, and archives.

**Run the site.** Content and taxonomies, media, users, settings, plugins and themes, nav menus, the filesystem, and the database, all over MCP.

**Understand and undo.** One-call page snapshots, content search across your own pages and templates, a change ledger with rollback, and read-only performance and security scans that return a scored report.

**Speak your plugins.** Integrations that register only when the plugin is active: ACF, Meta Box, WooCommerce, form builders, SEO plugins, and the Elementor addon packs.

Elementor is **optional**. Every WordPress domain works without it; installing Elementor unlocks the page-building family.

## Install

1. Build or download a `karmcp-*.zip` of this repository.
2. In WordPress: **Plugins → Add New → Upload Plugin**, then activate.
3. Open the **KarMCP** menu in the admin sidebar.

There is no in-dashboard update check — replace the plugin folder to upgrade.

**Requires** WordPress 6.9+ and PHP 8.1+. Elementor 3.20+ is optional (4.0+ for atomic elements). The MCP Adapter and Abilities API need no separate install.

## Connect your AI client

The **Connection** tab in the admin generates a ready-to-paste config for your client, including a one-click `.mcpb` bundle for Claude Desktop. It fills in your site URL and credentials for you.

The MCP endpoint is:

```
https://your-site.com/wp-json/mcp/karmcp-server
```

Tool names are prefixed `karmcp-` (for example `karmcp-add-container`), and abilities use the `karmcp/` namespace.

## Safe by default

Every tool runs a real WordPress capability check before it does anything, so an agent can only do what the authenticating user could do by hand.

Anything that writes, deletes, or renders site-wide **ships disabled** and is opt-in from **KarMCP → Tools**. Destructive operations additionally require an explicit `confirm: true`. Administrators cannot be edited over MCP, there is no delete-user tool, and filesystem access is confined to the WordPress root with automatic backups and an audit log.

## Tests

The suite runs with plain PHPUnit against a self-contained WordPress stub harness — no WordPress install required:

```bash
composer install && vendor/bin/phpunit
```

## Sample prompts

The [`prompts/`](prompts/) directory has complete landing-page blueprints that build an entire page from a single paste.

## License

[GNU General Public License v2.0 or later](LICENSE). Third-party copyright notices are in [NOTICE](NOTICE).
