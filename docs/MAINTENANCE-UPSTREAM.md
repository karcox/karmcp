# Upstream tracking (internal maintenance note)

> **Internal document.** KarMCP ships and is presented as an independent product; this file exists only so maintainers can keep harvesting fixes from the codebase KarMCP originally derived from. Legal attribution lives in `NOTICE` — that is the file the licence requires, and it stays.

The origin tree is [`msrbuilds/elementor-mcp`](https://github.com/msrbuilds/elementor-mcp), GPL-2.0-or-later.

It is a **source of ideas, not a source of code**. We never rebase or merge; changes are read, judged, and ported by hand. One line per reviewed release goes in the log below — in a year that log is the difference between knowing where you stand and re-reading forty releases.

La rama de trabajo es **`master`** (antes `karmcp`, unificada el 2026-08-15). La rama local `main`, que seguía a `upstream/main`, se ha borrado a propósito: era la única cosa del repositorio que invitaba a mergear upstream por confusión de nombres. El punto de fork sigue marcado por el tag `baseline-3.12.0`, y `upstream/main` se lee directamente desde el remote-tracking.

**The automatic daily `git merge upstream/main` workflow has been deleted** (it contradicted every line of this document — it would have silently overwritten the divergences below). Fetch and read; never merge.

## Current baseline

| | |
|---|---|
| Baseline tag | `baseline-3.12.0` |
| Upstream commit | `73c9b92` |
| Reviewed up to | 3.12.1 (reviewed from the published release notes and file tree, not a fetched commit — see the log row) |

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
- Freemius SDK — never loaded, and no stand-in remains: `KarMCP_License` / `karmcp_fs()` are gone entirely (see the two-tier bullet below).
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

KarMCP builds three of the upstream Pro capabilities itself. Everything else from that Pro (AI Chat, Backup/Migrate, Project Memory, the form/SEO plugin adapters, the Elementor addon packs) is **dropped, not deferred**.

- **Widget & Block Builder — done in 1.12.0.** `includes/sandbox/`: spec vocabulary, template compiler, one generator per platform, the block store/loader, and the two ability classes. **Written from scratch, not ported** — the upstream free tree never had the generator either, so there was nothing to import. The design is ours: a spec is data, the escape function comes from the declared type, and the generators are pure so the dry-run tool runs the real compiler. Never overwrite it from an upstream diff.
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
| 3.12.1 | 2026-08-15 | `upload-media`, reimplemented rather than transcribed (KarMCP 1.3.0). | Nothing else in the release. |

### 3.12.1 — notes

Reviewed from the release notes and the public file tree; the baseline tag was **not** moved, because the local clone has no `upstream` remote to fetch and the tag must point at a commit that has actually been read. Move it on the next review that fetches.

`upload-media` was written against the same seam upstream uses (`media_handle_sideload()`), not copied. Three things here that upstream's version does not do, so an upstream diff should not "fix" them back:

- The permission callback requires `edit_post` on `post_id` in addition to `upload_files`. Upstream gates on `upload_files` alone, which lets an Author attach a file to somebody else's page.
- The payload is size-checked against `wp_max_upload_size()` from the **encoded** length before decoding. Without it an oversized base64 blob is a memory fatal rather than an error.
- The new attachment is recorded via `KarMCP_Change_Recorder::record_post_create()`, so it is reversible from the change ledger. Upstream has no ledger. Note the asymmetry this leaves: `sideload-image` and `upload-svg-icon` also create attachments and still do **not** record — worth closing, but it is a change to those tools, not this one.

Everything else in 3.12.0/3.12.1 was either already present or unavailable:

- The **Backup / Sync / Migrate** suite (the headline of 3.12.0, seven MCP tools plus a paired connector) is Pro-only and **its source is not in the public repository** — there is no such directory under `includes/` or `includes/modules/`. It is also on the "dropped, not deferred" list above. Nothing to port even if we wanted it.
- The three bug fixes in 3.12.0 were already in this tree at the fork point: dynamic-value paragraphs locking Theme Builder documents (#112, `KarMCP_Elementor_Data::is_atomic_validation_rejection()`), empty renders with the Flexbox Container experiment off (#111, `KarMCP_Atomic_Props::is_container_supported()`), and OAuth tokens issued without being persisted (`KarMCP_OAuth_Store::issue_token()` verifies the insert and self-heals the schema). Verified in the code, not assumed from the log.
