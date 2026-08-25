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

**See what you built.** `render-page` renders a page the way a visitor gets it and returns a digest of the *output*, not the builder data: the heading outline, links, images, forms, visible text, and warnings for the failures that leave no trace in the source — containers that render empty, images with no alt text, links still pointing at `#`, shortcodes that never resolved, duplicate ids. It is the one read tool that can disagree with what the agent thought it wrote.

**Judge what you built.** `audit-page-seo` is the step after seeing it: it grades the page and says what to do about each finding. Title and description, the heading outline, content depth, alt text, links still pointing at `#`, indexability, canonical, and the focus keyword if one is set — scored out of 100 so two runs can be compared. Readability too, in the formula that matches the page's language: Spanish gets Szigriszt-Pazos on the INFLESZ scale rather than a Flesch score calibrated on English, which would call every Spanish page difficult and be believed. It reads what your SEO plugin has stored (Yoast, Rank Math, Slim SEO) and grades that *against* what the page actually renders, which is the only way to catch a title template that expands to nothing or a description the theme never emits.

**Audit accessibility, and admit what it cannot see.** `audit-page-a11y` checks alt text, link and form-field names, the heading outline, declared language, landmarks, duplicate ids and text contrast, each finding tied to a WCAG criterion. Contrast is the honest part: a colour that comes from a stylesheet, a gradient or a translucent overlay is reported as *undetermined*, never as a pass — because a false pass closes the question. And the report says in its own words that an automated check finds a minority of WCAG failures: it sees a missing alt attribute, not whether the text describes the image.

**Audit the tool's own documentation.** `audit-widget-catalog` turns the same scrutiny on KarMCP itself. The curated widget parameters are what an agent reads *before* it writes, so an entry that misleads is the most expensive defect this plugin can carry: the write is accepted, the value is stored, nothing warns, and the styling simply never appears. This checks every documented parameter against the controls the widgets on **your** site actually register — a parameter with no matching control, a type that describes something else, a range the description omits, and a value the widget transforms before using it (a size rendered through `calc(size * 100)` is a multiplier, not pixels). Widgets the catalog knows and your site does not have are reported as unavailable, not as faults.

**Build a whole site, not just a page.** `build-site` lays down the pages, the menu, the front page and the global palette in one call — dry-run by default, idempotent by slug. Contact forms that actually deliver mail, whether you run Contact Form 7 or Elementor Pro. Products, categories and store setup for WooCommerce. Translations for Polylang and WPML, duplicated and linked so the language switcher finds them. Validated Schema.org JSON-LD in the page head.

**Run the site.** Content and taxonomies, media, users, settings, plugins and themes, nav menus, the filesystem, and the database, all over MCP. Images can come from a stock provider, from a URL the server can reach, or — with `upload-media` — straight off the machine you are sitting at, sent as base64.

**Build the thing that was missing.** When no widget or block does what the page needs, the agent can describe one and the plugin compiles it: a custom Elementor widget or a custom Gutenberg block, from a structured spec into an isolated sandbox under `wp-content/karmcp-sandbox` — never your theme, core, or another plugin. **The agent never writes PHP.** It declares typed fields and an HTML template with `{{placeholders}}`, and the compiler decides every escape from the declared type; there is no raw output. Templates carrying PHP tags, `<script>`, or inline event handlers are refused outright. Generated code is loaded only from a hash-verified manifest, a fatal deactivates the artifact that caused it instead of the site, and every one of them can be paused or deleted from **KarMCP → Sandbox**. Small PHP snippets live there too, drafted by the agent and inert until a human activates them.

**Add options to elements you did not build.** When the missing piece is not a new widget but a *setting* — "let me switch particles on in any container" — the agent can describe an **element extension**: which Elementor elements it attaches to, which controls appear in their panel, and what those controls put on the element. Requires Elementor 4.2+. It can add a class of its own and `data-`/`aria-` attributes, and nothing else: no inline scripts, no `style`, no `href`. Its props are namespaced so they cannot shadow Elementor's own, and two extensions cannot claim the same one.

**Defend the login.** The Login Guard module blocks brute-force sign-ins — counting per address *and* per username, with an escalating but always time-limited delay — and closes the reconnaissance that precedes an attack: anonymous user enumeration and the XML-RPC multicall that batches hundreds of guesses into one request. Off by default, and it handles the reverse-proxy case explicitly, because behind Cloudflare a naive counter locks out every visitor at once.

**See the state of your security.** A Security tab with the score, the grade and the critical findings from the four audits that were previously MCP-only, refreshed daily in the background — and `harden-site` to apply what can be applied, dry-run first and reversible from a checkbox. An old or failed scan is always shown as exactly that, never as a clean bill of health.

**Come back from a crash.** An optional fatal-error handler records what broke and can deactivate the plugin responsible after repeated crashes, so the site is up again by the next request — and the agent, which cannot reach a dead site at all, can then read `get-fatal-log` and fix the cause. `update-core` is offered on the strength of it.

**Know what is vulnerable.** An optional module checks every installed plugin and theme against the Wordfence Intelligence database — CVE, CVSS, and the version that fixes it — by downloading the whole feed once a day and matching locally, so your site never tells anyone what it runs.

**Understand and undo.** One-call page snapshots, content search across your own pages and templates, a change ledger with rollback, and read-only performance and security scans that return a scored report.

**Speak your plugins.** Integrations that register only when the plugin is active: ACF, Meta Box, WooCommerce, Contact Form 7, Polylang, WPML, SEO plugins, and the Elementor addon packs.

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

## Languages

English and Spanish (`es_ES`). Switch your site's language and the admin, the Themer, the sandbox screens, the SEO and accessibility findings and every tool error message follow it — 2,154 strings, everything a person can see.

The `label` and `description` of each MCP tool stay in English on purpose. They are read by the AI agent, never rendered in the admin, and their wording is tested against agent behaviour; the POT marks them so nobody translates them by mistake.

To add a language, or after changing any translatable string:

```bash
php tools/make-pot.php && php tools/make-po.php fr_FR && php tools/make-mo.php fr_FR
```

No WP-CLI or GNU gettext needed — extract, merge and compile are self-contained PHP. `TranslationFilesTest` then checks that the PO matches the POT, that no placeholder was lost, and that the MO is really the compiled form of the PO.

## Tests

The suite runs with plain PHPUnit against a self-contained WordPress stub harness — no WordPress install required:

```bash
composer install && vendor/bin/phpunit
```

## License

[GNU General Public License v2.0 or later](LICENSE). Third-party copyright notices are in [NOTICE](NOTICE).
