# Upstream tracking (internal maintenance note)

> **Internal document.** KarMCP ships and is presented as an independent product; this file exists only so maintainers can keep harvesting fixes from the codebase KarMCP originally derived from. Legal attribution lives in `NOTICE` — that is the file the licence requires, and it stays.

The origin tree is [`msrbuilds/elementor-mcp`](https://github.com/msrbuilds/elementor-mcp), GPL-2.0-or-later.

It is a **source of ideas, not a source of code**. We never rebase or merge; changes are read, judged, and ported by hand. One line per reviewed release goes in the log below — in a year that log is the difference between knowing where you stand and re-reading forty releases.

La rama de trabajo es **`karmcp`**, que es a donde apunta `origin/HEAD`. Este archivo decía `master`, y eso era falso: `master` se quedó parada el 2026-08-17 y va 51 commits por detrás (verificado el 2026-08-24). La unificación que describía no llegó a pasar. **`master` está muerta** — o se borra o se sincroniza, pero no se trabaja en ella.

La rama local `main`, que seguía a `upstream/main`, se borró a propósito: era la única cosa del repositorio que invitaba a mergear upstream por confusión de nombres. `upstream/main` se lee directamente desde el remote-tracking.

**Dos tags, dos cosas distintas.** `baseline-3.12.0` marca el **punto de fork** y no se mueve nunca. `baseline-<versión>` a secas marca lo último **revisado**, y ese sí se mueve en cada tanda de revisión.

**The automatic daily `git merge upstream/main` workflow has been deleted** (it contradicted every line of this document — it would have silently overwritten the divergences below). Fetch and read; never merge.

## Current baseline

| | |
|---|---|
| Baseline tag | `baseline-3.16.1` |
| Upstream commit | `51eb780` |
| Reviewed up to | 3.16.1 (fetched and read, 2026-09-17) — **in sync on everything useful** |

`baseline-<version>` marks the last upstream commit that has been **reviewed** — not merged. Everything after it is unread.

## The maintenance loop

```bash
git fetch upstream
git log --oneline baseline-3.14.0..upstream/main
```

Then narrow to where it landed:

```bash
git diff baseline-3.14.0 upstream/main -- includes/abilities/
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
| 3.12.2 | 2026-08-18 | The two OAuth fixes, both reimplemented (KarMCP 1.24.0) — see the 3.12.2/3.12.3 note. | Kadence static-save auto-repair (editor JS, no equivalent surface here). `invalid_position` on block inserts and the `elementor_pro_version` empty string were **already in this tree**; the front-end defer landed here in 1.16.2 and went further in 1.30.0. |
| 3.12.3 | 2026-08-18 | The client-purge fix, reimplemented as `authorized_at` (store DB v3). | Nothing else in the release. |
| 3.12.4 / 3.13.0 (security) | 2026-08-24 | **The SQL guard rewrite (KarMCP 1.34.0)** — lexer + policy, system schemas, delay/lock functions, variable assignment, and the database-side row bound. Written against the same design, not transcribed; four divergences below. | The loopback-spelling fix was **already correct here** (`redirect_uri_matches()` relaxes only http loopback). Connector pairing, AI Chat approval and AI Chat SSRF pinning touch subsystems that do not exist in this tree. Public-registration bounds: partly here since 1.2.0 (rate limit), the size/count caps are **still open**. |
| 3.13.0 (Themer) | 2026-08-24 | Nothing yet. | **Open decision, not a rejection:** nine free Themer widgets and dynamic data on free Elementor (~2.800 lines over 23 files). It is the one thing upstream has that this tree does not, on the design axis. If it is ported, their 3.13.1 fix is part of the job: one class per dynamic source, or the front end fatals when Elementor rebuilds it. |
| 3.13.1 | 2026-08-24 | Nothing to do. | Both fixes were **already in this tree**: the search-index re-entrancy guard (`class-search-index.php:29`) and the per-source class shape. |
| 3.13.2 | 2026-08-24 | **The atomic rich-text fix (KarMCP 1.34.0)**, plus an mbstring guard upstream does not have. | The three-level PHP snippet validator (notes vs warnings vs blockers) is **still open** and worth doing — a good snippet currently arrives covered in warnings, which teaches a reviewer to skim. |
| 3.14.0 | 2026-08-24 | Nothing. The stream-filter decode and the executable-extension refusal are **this tree's 1.33.0**, arrived at independently and shipped two days earlier. | BeTheme / BeBuilder is Pro and its source is not in the public repository. The "why a tool cannot be switched on" badges and the saved-notice toast are admin polish worth revisiting. |
| 3.15.0 | 2026-09-12 | Navigator labels, the `partial_dimensions` advisory and the grid note (KarMCP 1.38.0); non-object `styles` / `editor_settings` rejected (1.39.0); element readback of `styles` and `editor_settings`, `regenerate-css`, and `render-page` by URL with a resumable HTML slice (1.40.0). All reimplemented. | WooCommerce Brands and anything tied to AI Chat or the Cloud. |
| 3.15.1 | 2026-09-15 | Nothing. | AI Chat on the Responses API and Cloud settings sync — neither subsystem exists here. |
| 3.15.2 | 2026-09-15 | Nothing. | Changelog formatting only. Our equivalent guard (readme.txt headings) shipped in 1.39.1. |
| 3.16.0 | 2026-09-15 | **Install from an uploaded ZIP (KarMCP 1.41.0)**, with the same guard set plus a private temp copy against swaps; KarMCP, Elementor and Elementor Pro stay unreplaceable. | The Hello Elementor 3.x Themer fix was **already in this tree** (`class-themer-hello-adapter.php`). GSAP, FunnelKit reads and paginated change history are Pro. Structured ACF / WooCommerce imports and wider ACF coverage were judged not worth it for now — the WooCommerce import by SKU is the one to revisit if the need appears. |
| 3.16.1 | 2026-09-15 | **Plain-permalink base URL (KarMCP 1.41.0)**, also covering PATHINFO permalinks, which upstream's fix does not mention. | Nothing else in the release. |

### 3.12.1 — notes

Reviewed from the release notes and the public file tree; the baseline tag was **not** moved, because the local clone has no `upstream` remote to fetch and the tag must point at a commit that has actually been read. Move it on the next review that fetches.

`upload-media` was written against the same seam upstream uses (`media_handle_sideload()`), not copied. Three things here that upstream's version does not do, so an upstream diff should not "fix" them back:

- The permission callback requires `edit_post` on `post_id` in addition to `upload_files`. Upstream gates on `upload_files` alone, which lets an Author attach a file to somebody else's page.
- The payload is size-checked against `wp_max_upload_size()` from the **encoded** length before decoding. Without it an oversized base64 blob is a memory fatal rather than an error.
- The new attachment is recorded via `KarMCP_Change_Recorder::record_post_create()`, so it is reversible from the change ledger. Upstream has no ledger. Note the asymmetry this leaves: `sideload-image` and `upload-svg-icon` also create attachments and still do **not** record — worth closing, but it is a change to those tools, not this one.

Everything else in 3.12.0/3.12.1 was either already present or unavailable:

- The **Backup / Sync / Migrate** suite (the headline of 3.12.0, seven MCP tools plus a paired connector) is Pro-only and **its source is not in the public repository** — there is no such directory under `includes/` or `includes/modules/`. It is also on the "dropped, not deferred" list above. Nothing to port even if we wanted it.
- The three bug fixes in 3.12.0 were already in this tree at the fork point: dynamic-value paragraphs locking Theme Builder documents (#112, `KarMCP_Elementor_Data::is_atomic_validation_rejection()`), empty renders with the Flexbox Container experiment off (#111, `KarMCP_Atomic_Props::is_container_supported()`), and OAuth tokens issued without being persisted (`KarMCP_OAuth_Store::issue_token()` verifies the insert and self-heals the schema). Verified in the code, not assumed from the log.

### 3.12.4 / 3.13.0 — the SQL guard, and four divergences

The design was adopted wholesale and the code was written here: a fail-closed lexer that accounts for every byte, a policy over typed tokens, and analysis under all four session-mode readings. That part should not drift — if upstream extends `MODE_FLAGS`, extend it here too.

Four places where this tree deliberately differs. **An upstream diff must not "fix" any of them back**, and each has a test that fails if it is:

- **`REPLACE()`, `INSERT()` and `TRUNCATE()` are exempt when called as functions** (`KEYWORD_FUNCTIONS`). All three are ordinary read-only MySQL functions that share a spelling with a write statement. Upstream's rewrite denylists the bare word, which reintroduces the false positive this tree fixed in 1.2.0 — an agent gets "your SELECT contains an unsafe keyword" and has nothing to act on. Safe because none of the three can *begin* a statement here (the leading-keyword check runs first and does not consult the list), and elsewhere `WORD(` is a call: every statement form needs a table name where the parenthesis sits.
- **The exemption requires an adjacent parenthesis; blocking does not.** MySQL itself requires no space between a built-in function name and its `(` outside `IGNORE_SPACE`. Upstream uses one notion of "followed by `(`" for both directions, which is generous in the exempting direction. Here the asymmetry is explicit so both errors fall towards refusing.
- **`INTO` stays denylisted.** Upstream dropped it. In a `SELECT` it is only ever `INTO OUTFILE`, `INTO DUMPFILE` or `INTO @var` — a file write or a variable write — and the guard this replaced refused it.
- **System schemas are matched on qualifiers only** (the name before a dot), which is upstream's rule, but the reasoning is written down here because it looks like a gap: `sys` is a plausible column name, and data in a system schema is unreachable without qualifying it, since `USE` is a forbidden keyword and a second statement is refused.

One addition with no upstream counterpart: `KarMCP_Database_Guard::check_read_query()` bundles the three checks a raw read has to pass (read-only, no system schema, no protected table). Upstream leaves the three as separate calls at the ability layer, which works until a second raw-SQL path remembers two of them.

### 3.13.2 — the atomic rich-text fix

Ported as designed — both halves of an `html-v3` value from a single parse, generated ids written back into the markup — with one addition: `load_fragment()` also guards on `mb_encode_numericentity()`, not just `DOMDocument`. Neither extension is guaranteed on a WordPress host, and a fatal there would cost every atomic write on the site rather than just the editor tree.

The encoding round-trip is covered by tests in Spanish and with an emoji. libxml reads its input as ISO-8859-1, so accented copy is the first thing that breaks if the numeric-entity encoding is ever "simplified" away.
