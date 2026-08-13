# Upstream tracking

KarMCP is a **divergent hard fork** of [`msrbuilds/elementor-mcp`](https://github.com/msrbuilds/elementor-mcp) (EMCP Tools), GPL-2.0-or-later.

Upstream is a **source of ideas, not a source of code**. We never rebase or merge; changes are read, judged, and ported by hand. One line per reviewed release goes in the log below — in a year that log is the difference between knowing where you stand and re-reading forty releases.

## Current baseline

| | |
|---|---|
| Baseline tag | `baseline-3.12.0` |
| Upstream commit | `73c9b92` |
| Reviewed up to | 3.12.0 (fork point) |

`baseline-<version>` marks the last upstream commit that has been **reviewed** — not merged. Everything after it is unread.

## The maintenance loop

```bash
git fetch upstream
git log --oneline baseline-3.12.0..upstream/main
```

Then narrow to where it landed:

```bash
git diff baseline-3.12.0 upstream/main -- includes/abilities/
```

Port by hand, remembering that **every symbol is renamed here**: `EMCP_Tools_` → `KarMCP_`, `emcp_tools_` → `karmcp_`, `EMCP_TOOLS_` → `KARMCP_`, and the `emcp-tools` slug (text domain, ability namespace, server route, page slugs, table names, meta keys) → `karmcp`. A patch pasted straight from upstream will not apply and will not run.

Run the suite before and after every port:

```bash
vendor/bin/phpunit
```

When a batch of review is done, move the tag to the upstream commit you have read up to, and add a log row:

```bash
git tag -f baseline-<version> <upstream-commit>
```

## What to adopt

| Change type | Default | Why |
|---|---|---|
| Security fixes, fatals | **Always, with priority** | Non-negotiable; usually small and isolated. |
| New widgets and schemas | **Usually** | Catalog files are isolated data — cheap to port. |
| New features | **Judge on value** | Read it, decide, record the decision below. |
| Architecture refactors | **Usually not** | Highest conflict, lowest benefit on an already-divergent tree. |

## Permanent divergences

These are deliberate. Do **not** re-import them from upstream:

- `includes/class-github-updater.php` — deleted. Updates are manual; the header carries `Update URI: false`.
- Freemius SDK — never loaded. `KarMCP_License` in `karmcp.php` is the local stand-in; every capability method returns `false` because the `pro/` overlay is absent from this tree.
- `KarMCP_Upgrade_Notice`, `KarMCP_Community_Notice` — deleted (storefront and community marketing). `KarMCP_Elementor_Notice` is **kept** — it reports a real missing dependency.
- Upsell views — rewritten as neutral "not available in this build" notices.
- `KarMCP_Cloud::DEFAULT_BASE_URL` — emptied, so the plugin makes no outbound calls to a third-party service. Configure via `KARMCP_CLOUD_URL`, the `karmcp_cloud_base_url` option, or the filter of the same name.
- Root `phpunit.xml` — repointed from the private `pro/tests` to the public `tests/`, so `phpunit` works with no arguments.
- **The entire two-tier machinery is gone.** KarMCP is not licensed or distributed, so there is no free/premium split to maintain. Removed: `KarMCP_License` + `karmcp_fs()` and all 13 gate call sites, `KarMCP_Pro_Loader` (which resolved 73 files that only ever existed in the private overlay), `pro-manifest.txt`, the `pro/` submodule, `KarMCP_Library_Refresher` (it `require_once`'d absent files behind a guard that always passed — a latent fatal), `KarMCP_Admin::affiliation_page_available()` (called a Freemius method the shim never had), the AI Chat / Memory / Skills / Backup&Migrate admin tabs, the upsell views, and every "Upgrade to Pro" CTA.
- `KarMCP_Admin::get_all_tools()` now **drops** every `pro`-flagged catalog category instead of showing it greyed out. Those 22 categories described integrations whose code is absent, so listing them was a lie the Tools screen told the admin.
- Free⇄premium single-instance guard in `karmcp.php` — reduced to a plain double-load guard (`defined( 'KARMCP_VERSION' )`). The `.karmcp-pro` marker and sibling-deactivation logic are gone.
- `docs/` is tracked (upstream gitignored it as local-only notes).

**Rule of thumb when porting:** if an upstream change touches `pro/`, `can_use_premium_code()`, Freemius, or an upsell surface, it does not apply here. Skip it.

## Own features

KarMCP builds two of the upstream Pro capabilities itself. Everything else from that Pro (AI Chat, Backup/Migrate, Project Memory, Widget & Block Builder, the form/SEO plugin adapters, the Elementor addon packs) is **dropped, not deferred**.

- **Themer extended tier — done.** `includes/themer/class-themer-extended.php`: unlimited templates per type, granular matchers (`post`, `in-term`, `author`, `term`, `author-archive`, `date`), Exclude rules, working priority, and the object-search endpoint. Covered by `tests/ThemerExtendedTest.php`. This is **ours, not upstream's** — never overwrite it from an upstream diff.
- **SEO & Accessibility — not started.** Plan in the roadmap.

## Upstream bugs fixed here

Found by running against a real WordPress. Do not let an upstream diff revert these.

- **Hello Elementor adapter was inert.** `KarMCP_Themer_Theme_Adapters::map()` pointed the theme at `hello_elementor_header` / `hello_elementor_footer`. Neither action exists in Hello Elementor (verified against 3.4.6) — the theme renders header/footer inline in `header.php`/`footer.php`, gated on `elementor_theme_do_location()` then the `hello_elementor_header_footer` filter. Injection silently never fired. Replaced with a callback adapter, `KarMCP_Themer_Hello_Adapter`: defers to Elementor Pro per location, suppresses the theme via its own filter, prints on `wp_body_open` / `get_footer`, and re-emits the theme part for whichever slot we do not fill (that filter is a single boolean for both). The adapter map now supports `{ wire: callable }` alongside the hook shape. Tests: `tests/ThemerHelloAdapterTest.php`.
- **`karmcp_theme_template` exceeded the post_type limit.** The `emcp` → `karmcp` rename pushed the Themer CPT to 21 characters; WordPress caps `post_type` at 20 (`wp_posts.post_type` is `varchar(20)`), so the CPT never registered and every template insert failed. Now `karmcp_theme_tpl` (16). All five CPT names were audited — it was the only one over the limit. **Watch this on any future rename:** the prefix is 2 characters longer than upstream's.

See [docs/ROADMAP-SEO-A11Y-THEMER.md](docs/ROADMAP-SEO-A11Y-THEMER.md) for the grounded plan: which filter seams already exist, what has to be written from scratch, and the order to do it in. Notably, the Themer engine already implements include/exclude rules, specificity ranking and a priority ranker in the free tree — the extended tier is mostly quota + matchers + UI, not new architecture.

## Review log

| Version | Date reviewed | Adopted | Rejected / notes |
|---|---|---|---|
| 3.12.0 | 2026-08-13 | Fork point — full tree adopted as the baseline. | Auto-updater, Freemius SDK, upsell banners and the Cloud endpoint removed; see Permanent divergences. |
