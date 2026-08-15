=== KarMCP ===
Contributors: karmcp
Tags: elementor, mcp, ai, page-builder, automation
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.7.1
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

= 1.7.1 =

* Fixed: the malware audit flagged every PHP file that Code Snippets stores under `wp-content/uploads/code-snippets/` — one per snippet — as a critical finding. On a real site that alone was 142 of 144 criticals, all false. PHP under uploads from plugins known to put it there is now reported once as information; anything in an unrecognised directory is still critical, and all of them are still pattern-scanned.
* Fixed: findings never showed which file they referred to, so the report was 142 identical rows with nothing to act on. Locations are now displayed, and repeated findings are grouped with a count.
* Fixed: the security score could not tell "needs updating" from "compromised". Warning penalties were uncapped, so 46 ordinary warnings floored the score at 0 before anything else counted. They are now capped per category, like criticals already were.
* Fixed: warnings were charged to whichever category the previous critical finding belonged to.

= 1.7.0 =

* New **Known Vulnerabilities** module (off by default): checks every installed plugin and theme against the Wordfence Intelligence database and adds a fifth category — CVE, CVSS score, and the version that fixes it — to the Security report. Needs a free API key from a wordfence.com account, under Integrations.
* **Nothing about your site is sent anywhere.** The endpoint only serves the complete feed and takes no parameters, so the whole database is downloaded once a day and matched locally; there is no per-plugin query that could leak an inventory.
* The feed is 11.2 MB gzipped and 150 MB decoded, which is far too large to decode in PHP, so it is streamed and written record by record — verified against the real feed at 18 MB of peak memory.
* Elementor and Elementor Pro, normally not updatable over MCP, may now be updated **when a known vulnerability affects the installed version and the available update actually clears it**. Both conditions are required. KarMCP still never updates itself.
* New tool `list-vulnerabilities`: worst CVSS first, with the fixing version and whether an available update really resolves that specific vulnerability. A feed that was never downloaded reports itself as unknown rather than returning an empty list.
* Vulnerability records carry the Defiant copyright notice and a link to the record, which is the condition the data is licensed under.

= 1.6.0 =

* New **fatal-error handler** (install it from the Security tab). WordPress loads `wp-content/fatal-error-handler.php` instead of its own when it exists, so this runs at the moment of the crash — the only place it can help, since once PHP dies the REST API and MCP die with it. It records what broke and, optionally, deactivates the plugin responsible so the next request succeeds.
* Deactivation needs **three fatals in ten minutes**, never the first: one transient error must not take a shop offline in a different way than the bug would have. Off until switched on, honours a protected list, and KarMCP can never deactivate itself.
* Note: the Recovery Mode built into WordPress does not fix a broken site — it emails a link and pauses the extension for that recovery session while visitors keep seeing the error.
* New tools `get-fatal-log`, `list-paused-plugins` (read-only) and `resume-plugin` (requires confirm).
* New tool `update-core`: updates WordPress itself. Ships disabled and requires confirm — it replaces the code serving the request, so a failure takes the site down rather than returning an error. It reports whether the fatal-error handler is installed, which is what makes the offer reasonable.

= 1.5.0 =

* New **Security** tab. The four audits — malware heuristics, core-file integrity against wordpress.org checksums, configuration hardening, outdated and abandoned software — have existed since 3.0.0 and were never visible inside WordPress; `scan-security` returned its report to an agent and nowhere else. Now there is a screen with the score, the grade, the critical findings and how old the answer is.
* Scans run **once a day in the background**, not only when someone asks, with an admin notice on critical findings. A scanner that only runs on request is not monitoring.
* A scan that never ran, failed, or is more than two days old is shown as exactly that — never as a clean bill of health. A failed scan discards the previous report so it cannot masquerade as yesterday's good news.
* New tool `harden-site`: applies four of the hardening findings (dashboard file editor, XML-RPC, version disclosure, security headers). Dry-run by default, reversible from a checkbox, and recorded in History. Each fix is a switch the plugin owns rather than an edit to `wp-config.php`.
* Three hardening findings are never fixed automatically and the plan says why: `WP_DEBUG_DISPLAY` is read before plugins load, renaming the `admin` account breaks whatever signs in as it, and HTTPS needs a certificate on the server.

= 1.4.0 =

* New **Login Guard** module (KarMCP → Modules, off by default): blocks brute-force sign-ins by counting failures per IP address *and* per username separately, with an escalating delay — 15 minutes, then 30, then an hour — that doubles to a hard ceiling of 24 hours and is never permanent.
* Login Guard handles the reverse-proxy trap explicitly. Behind Cloudflare every visitor shares one address, so a naive guard locks out the world on the fifth failure by anybody; the header that fixes it is forgeable by anyone. The guard uses REMOTE_ADDR by default and reads the forwarded header only from proxy addresses you declare.
* Login Guard optionally blocks anonymous user enumeration (`/wp-json/wp/v2/users` and `?author=N`) and drops XML-RPC `system.multicall`, which batches hundreds of password guesses into one request. Disabling XML-RPC breaks Jetpack and the mobile app, so it is a switch.
* New tools `list-login-lockouts` (read-only, enabled) and `clear-login-lockout` (opt-in). Clearing a lockout also forgets its escalation history, so the next lock starts from the base delay.
* The MCP server and its OAuth endpoints are exempt from the failure count, so a retrying client cannot lock out the agent.
* Internal: continuous integration on PHP 8.1-8.4 for the 544 tests, plus PHPCS and PHPStan. None of it ships in the plugin.

= 1.3.1 =

* Fixed: `upload-media` answered a bare "Permission denied" when `post_id` named a post that does not exist. `map_meta_cap()` resolves `edit_post` against a missing post to `do_not_allow`, so asking the capability before checking existence reported a permission problem for a mistyped id — and made the tool's own "no such post" message unreachable. Existence is now checked first, and all three refusals (no `upload_files`, no such post, no rights on that post) name the actual cause.
* Fixed: a file whose contents did not match its extension — PHP named `.jpg` — was refused by WordPress in the site's own language, with a message about permissions rather than about the file. It is now verified and refused before the upload, as `content_type_mismatch`, naming the file and the extension it contradicts. WordPress still runs its own check afterwards.
* Fixed: every upload failure suggested retrying with `convert_webp:false`, including failures that flag could not affect.

= 1.3.0 =

* New tool `upload-media`: uploads a file from the client machine into the Media Library by sending its bytes as base64. The companion to `sideload-image`, which can only fetch a URL the server already reaches — so until now an agent could not upload the photo sitting on the user's own disk. Accepts optional alt text, title, caption, description, and a `post_id` to attach it to. It goes through `media_handle_sideload()` like every other upload, so the type allowlist, the SVG sanitizer and the WebP/compression pass all still apply.
* `upload-media` refuses three things before writing: a file type this site does not accept (checked from the extension before the payload is decoded), a payload over the site's upload limit (estimated from the encoded length, with the limit named in the error), and attaching to a post the caller cannot edit — `upload_files` says a user may add files, not whose pages they may attach them to.

= 1.2.1 =

* Fixed: a tool that threw reported nothing you could act on. WordPress keeps only the exception's message, so when Elementor refused to save the Elementor kit with a bare "Access denied.", that literal — which appears nowhere in this plugin — was the entire report: no class, no file, no line. Anything escaping a tool now returns an error naming all three.
* Fixed: update-global-colors and update-global-typography checked `manage_options`, but Elementor requires `edit_post` on the kit post itself and throws if it is missing. The two are unrelated, so a capability manager that puts `elementor_library` under type-specific capabilities breaks both tools for every user, administrators included. They now check the capability Elementor actually enforces and say which post and which capability failed.
* Fixed: a per-post permission denial returned a bare "Permission denied" naming neither the post, the post type, nor the capability. update-post and delete-post now report all three.

= 1.2.0 =

* Security: create-post checked the generic `edit_posts` and then wrote any post type it was given, so an Editor could create posts of types this plugin gates behind `manage_options` — including Agent Skills and PHP snippets, bypassing their own guards. Capabilities are now resolved against the target post type, and the plugin's own types are refused outright.
* Security: get-post returned any post to anyone who could edit any post. It now applies the per-post `read_post` capability, so private drafts and the plugin's own private types are no longer readable by ID alone.
* Security: list-posts enumerated any post type it was asked for, so titles and slugs of types the caller cannot read came back anyway. Requested types are now filtered by capability, and private posts by another author are excluded.
* Security: a download could be redirected to the cloud metadata service at 169.254.169.254 — WordPress's own redirect check permits that range. Every hop is now re-validated against the plugin's address table, IPv6 and IPv4-mapped forms included.
* Security: OAuth dynamic client registration is rate-limited per address, and the granted scope is reduced to what the server actually supports instead of echoing back whatever the client asked for.
* Fixed: uninstalling left roughly two dozen options and all five plugin tables behind, including OAuth clients and live tokens. The cleanup now sweeps by prefix and drops the tables, once per site on a network install.
* Fixed: the plugin could not be translated — around three thousand strings and no `load_plugin_textdomain()` call. Translations placed in `languages/` now load.
* Fixed: the query tool rejected the `REPLACE()` string function as if it were a `REPLACE INTO` write.
* Removed dead Project Memory code: three AJAX endpoints and a section of the Tools tab for a feature that is not part of this build.

= 1.1.0 =

* `render-page`: renders a page the way a visitor gets it and returns a digest of the output, with warnings for empty containers, missing alt text, placeholder links, unresolved shortcodes and duplicate ids. The one read tool that can disagree with what the agent thought it wrote.
* Contact Form 7: create and rebuild forms from a plain field list, with a mail template that reports every field. Reads submissions through Flamingo when it is installed.
* `add-contact-form`: a working Elementor Pro form from a plain field list, with the notification email and reply-to wired up.
* WooCommerce: list, get, create, update and delete products, set their categories and tags, and read the store setup. Prices are accepted in any human format. Orders and customers are deliberately not exposed.
* Polylang and WPML: list languages, read a page's translation status, create a translation (duplicated, assigned and linked), and link copies made by hand.
* `duplicate-post`: copies a post with its protected meta and terms, so post types owned by other plugins can finally be created rather than only edited.
* Structured data: validated Schema.org JSON-LD (Organization, LocalBusiness, Product, FAQPage, BreadcrumbList, Article, Person, Service, Event) printed in the page head.
* `build-site`: pages, menu, front page and global palette in one call. Dry-run by default, idempotent by slug, administrator only.
* Agent Skills: short operating manuals you write once and every connected agent reads. Listed under KarMCP → Skills, read-only over MCP. Documented under 1.0.0 but not part of that build; it ships here.
* Guardrails: an opt-in module of site rules above the capability checks — read-only mode, destructive-tool blocking, a freeze window, protected posts and post types. Enforced on every write and published into the agent's context. Documented under 1.0.0 but not part of that build; it ships here.
* Fixed: module cards said a feature required "an active Pro license", but this build has no licensing at all. They now say the feature is not included in this version.

= 1.0.0 =

* Independent release under the KarMCP name, with its own MCP namespace (`karmcp/`) and server route (`/wp-json/mcp/karmcp-server`).
* No telemetry, no licensing SDK, and no auto-updater: the plugin makes no outbound network calls unless you configure one.
* Theme builder: unlimited templates per type, granular display conditions (per post, term, author, date), exclude rules and working priority.
* Fixed: the theme builder's Hello Elementor adapter never injected anything, because it targeted hooks that theme does not fire.
* Fixed: the theme-template post type exceeded WordPress's 20-character limit, so it never registered and every template insert failed.
* Removed a "Backup & Migrate" tool category whose implementation is not part of this build; it was showing seven toggles that could never do anything and inflating the dashboard's tool counters.
* Fixed a failing announcements request that retried every two hours on installs with no service configured.
