# Changelog

All notable changes to KarMCP are documented in this file.

## [1.0.0]

> First public release. The MCP namespace is `karmcp/` and the server route is `/wp-json/mcp/karmcp-server`, so every client config points here.
>
> Some `@since` tags in the source read 3.x. Those track the codebase this one grew from, not KarMCP releases — the version numbering starts here.

- **No telemetry, no licensing SDK, no auto-updater.** The plugin makes no outbound network calls unless you configure one, and it never contacts a licensing or analytics service. Updates are manual: replace the plugin folder.
- **Theme builder, extended.** Unlimited templates per type, granular display conditions (a specific post, term, author, author archive, or date), Exclude rules, and working priority — with an object search in the condition builder so you can pick the exact target.
- **Fixed: the Hello Elementor theme adapter never rendered anything.** It targeted `hello_elementor_header` / `hello_elementor_footer`, which that theme does not fire — it renders header and footer inline, gated on its own filter. Injection silently never happened. The adapter now defers to Elementor Pro per location, suppresses the theme's own output through that filter, and re-emits whichever slot we do not fill.
- **Fixed: theme templates could not be created at all.** The theme-template post type name was 21 characters, and WordPress stores `post_type` in a 20-character column — so the type never registered and every insert failed. Shortened to `karmcp_theme_tpl`.
- **Fixed: a phantom "Backup & Migrate" tool category.** It listed seven toggles whose implementation is not part of this build, so they could never do anything; it also inflated the dashboard's "X of Y" tool counters and wrote non-existent tool names into stored settings.
- **Fixed: a failing announcements request every two hours.** With no announcement service configured — the default — the request was built from an empty base URL and could only fail, then retried on a two-hour cycle forever. It now skips cleanly.
- **Fixed: the remote-connection configs the admin generates.** They told your client to fetch an npm package that does not exist, so the `npx` route could never start. KarMCP now publishes nothing to npm and no longer offers a config that depends on it: remote clients use direct HTTP, the `.mcpb` bundle, or `mcp-remote` — all of which the Connection tab still generates, filled in. The proxy stays in the box at `bin/mcp-proxy.mjs` for anyone who wants its multi-site registry, documented as a file you copy locally; its docs now match the environment variables it actually reads (`KARMCP_SITES`, `KARMCP_SITES_FILE`, `KARMCP_DEFAULT_SITE`).
- **Corrected the security policy's scope.** It claimed there were no unauthenticated attack surfaces; the OAuth registration, token, revocation and discovery endpoints are deliberately unauthenticated per their specs, and findings against them are in scope.

### Security

- **The database write tools now validate column names against the table's real columns.** WordPress parameterizes the values you pass to `insert-row` / `update-rows` / `delete-rows`, but interpolates the *column names* into the SQL without escaping — so a crafted key could close the identifier, append its own SQL, and read tables the tools are supposed to refuse (including password hashes). Unknown column names are now rejected outright, which also turns a silent typo into a clear error.
- **WP-CLI can no longer read `wp-config.php`.** `config get` and `config list` printed the database password and every security salt. Those salts are what the plugin derives its encryption key from, so leaking them exposed every stored secret — and the filesystem tools already refused to read that file for the same reason. `config get`, `config list`, `config path` and `config has` are now refused alongside the writes.
- **Closed four more WP-CLI routes to a site takeover:** the top-level `search-replace` (rewrites every table in place, bypassing the protected-table list), installing a plugin or theme from a URL or `.zip` (runs whatever the archive contains — the dedicated install tool has always been wordpress.org-only), creating or promoting an administrator or setting a user's password, and writing the options that decide which code loads or who may register (`active_plugins`, `default_role`, `users_can_register`, and similar). Ordinary use is unaffected: installing by slug, creating a subscriber, and editing normal options all still work.
