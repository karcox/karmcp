=== KarMCP ===
Contributors: karmcp
Tags: elementor, mcp, ai, page-builder, automation
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.21.1
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your WordPress site into something an AI agent can operate: pages, content, media, users, and diagnostics, all exposed as MCP tools.

== Description ==

KarMCP exposes your WordPress site as **MCP (Model Context Protocol) tools**, so Claude, Cursor, and any other MCP-compatible client can build Elementor pages, write content, manage the site, and audit it â€” driving WordPress through its own APIs rather than a browser.

It builds on the official WordPress MCP Adapter, which ships bundled. The MCP endpoint is `/wp-json/mcp/karmcp-server` and tool names are prefixed `karmcp-`.

There is no telemetry and no auto-updater: the plugin makes no outbound calls unless you configure one. Third-party copyright notices are in the bundled NOTICE file.

**Elementor is optional.** Every WordPress domain below works without it; installing Elementor unlocks the page-building family.

**Build pages**

* **Elementor**: containers, widgets, templates, global colours and typography, and a one-call `build-page` that renders a whole declarative page structure.
* **Widget catalog**: discover â†’ inspect â†’ act (`list-widgets` â†’ `get-widget-schema` â†’ `add-free-widget` / `add-pro-widget` â†’ `update-widget`). Curated parameters for 62 widgets live in a built-in catalog, so every widget stays reachable while the per-turn tool-list cost stays small. `add-pro-widget` registers only when Elementor Pro is active.
* **Atomic elements (Elementor 4.0+)**: dedicated tools for flexbox, div-block, heading, paragraph, button, image, SVG, YouTube, video and divider, plus universal add/update and `detect-elementor-version`.
* **Gutenberg blocks**: list block types and patterns, read a post's block tree, and add, update, move, duplicate or remove blocks by index path.
* **Theme builder**: builder-agnostic headers, footers, single, archive, search and 404 layouts with display conditions, injected on the front end.

**Run the site**

* **Content**: posts, pages and any custom post type â€” content, status, taxonomy terms, custom fields and featured images. Never touches Elementor data; every post carries an `is_elementor` flag so agents switch tools correctly.
* **Settings**: read and batch-update core settings over a curated allowlist. No arbitrary option access; `admin_email` stays read-only.
* **Plugins & themes**: discover, install (wordpress.org only), update, activate and delete. KarMCP and Elementor are protected from deactivation.
* **Media**: full attachment detail, metadata editing, and deletion behind an explicit confirmation.
* **Users**: list and read users; create and edit non-admin profiles. No delete, no role changes, administrators off-limits, passwords auto-generated and emailed rather than returned.
* **Nav menus, redirects, filesystem and database**, each behind its own capability and default-off switches for anything that writes.

**Understand and undo**

* **Page snapshot**: one normalized digest of a page in a single call.
* **Change ledger with rollback**: every recorded change is reversible from the History tab, across Elementor, filesystem and database.
* **Content search** over your own pages and templates, and **content mirror** for git-trackable JSON exports.
* **Performance analyzer**: server config, WordPress internals (database size, autoloaded options, revisions, cron backlog, object cache, OPcache) and a target page, returned as a scored report (0-100 plus Aâ€“F) with ranked recommendations. Read-only.
* **Catalog audit**: checks the documented widget parameters against the controls your widgets really register, and reports the ones that mislead — no matching control, a type that describes something else, an undocumented range, a value transformed before use. The class of mistake where a write succeeds and does nothing. Read-only.
* **Security scanner**: malware heuristics, core-file integrity against official checksums, hardening checks and outdated software, returned as a scored report. The malware walk is bounded and never returns full file contents. Read-only.

**Speak your plugins**

Integrations register only when their plugin is active, and follow a fixed two-tool shape (`<plugin>-read` / `<plugin>-write`) to keep the tool list small: **ACF / ACF PRO**, **Meta Box**, **Contact Form 7**, **Slim SEO**, **Astra**, **Spectra**, plus an active-theme integration that works with any theme.

**Requires:**

* WordPress 6.9 or later (the Abilities API is in core)
* PHP 8.1 or later
* The WordPress MCP Adapter, bundled with the plugin â€” no separate install

**Recommended (optional):**

* Elementor 3.20 or later (4.0+ for atomic elements)

== Installation ==

1. Upload the `karmcp` folder to `/wp-content/plugins/`, or install the ZIP from **Plugins â†’ Add New â†’ Upload Plugin**.
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

For a client that only speaks stdio, bridge it with `mcp-remote`. Create an Application Password at **Users â†’ Profile â†’ Application Passwords** first:

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

Yes. Every WordPress domain â€” content, media, users, settings, plugins, diagnostics, Gutenberg â€” works with no page builder installed. Elementor only adds the Elementor tool family.

= Does it work without Elementor Pro? =

Yes. Free and core widgets go through `add-free-widget`. The `add-pro-widget` tool registers only when Elementor Pro is active.

= Can I disable specific tools? =

Yes. Open **KarMCP â†’ Tools** and toggle any tool. If your MCP client caps the number of tools, turn on **Compact tool mode** on the same screen: the server then exposes three meta-tools (`list-tools`, `get-tool-schema`, `call-tool`) instead of the full list, and your per-tool toggles still decide what `call-tool` will run.

= Is it safe to use on a production site? =

Every tool runs a real WordPress capability check first, so an agent can only do what the authenticating user could do by hand. Anything that writes, deletes, or renders site-wide ships **disabled** and is opt-in; destructive operations additionally require an explicit confirmation. Administrators cannot be edited over MCP, there is no delete-user tool, and filesystem access is confined to the WordPress root with automatic backups and an audit log.

= A remote MCP client intermittently says the server isn't responding =

On shared LiteSpeed hosting this is usually the host caching or timing out the request rather than the plugin. Exclude `/wp-json/mcp/` from the host cache, raise PHP `max_execution_time` to 60 or more, and check **KarMCP â†’ MCP Log** (with `WP_DEBUG` on it records the underlying error) to tell a real error from a transport timeout. For large operations use `build-page` with `dry_run`, `sideload-image` with `convert_webp:false`, and `get-page-structure` with `summary:true`.

== Screenshots ==

1. Tools management page with category-grouped toggles.
2. Connection configuration page with copy-paste configs.

== Changelog ==

= 1.21.1 =

* Fixed: the widget schema described a multi-select control as a single string when it takes a list — affecting a form's submit actions, a countdown's expiry actions and the heading tags of a table of contents.
* Fixed: three sources of false findings in the new catalog audit, so what it reports is worth reading.

= 1.21.0 =

* Removed: the dark bar across the top of the panel. Everything it held moved into the side rail — the KarMCP mark and version at the top, and MCP Log, History, Changelog, Get Help, notifications and cloud status at the bottom — so nothing became harder to reach.
* Removed: the "KarMCP found N critical security issues" notice that appeared on every screen of the WordPress admin. The Security tab already shows the same counts and when the site was last checked. Scanning is unchanged.

= 1.20.3 =

* Fixed: when the WebP copy of an image came out **bigger** than the original — which happens on ordinary photographs more often than you would expect — it was kept anyway and served to visitors in place of the smaller original. It is now discarded, and the number of discards is reported: if it happens to every image, the WebP quality setting is too high for your photos.

= 1.20.2 =

* Added: **audit-widget-catalog**, a read-only tool that checks the documented widget parameters against the controls the widgets on your site really have, and reports the ones that mislead: a parameter with no matching control, a type that describes something else, an undocumented range, and a value the widget transforms before using it. This is the class of defect where a write is accepted, stored, and simply does nothing.
* Fixed: the video widget documented `insert_url` as the self-hosted video URL when it is a switch for using an *external* one, and did not document `hosted_url` at all — so there was no documented way to insert a self-hosted video, which is the only kind that survives a SCORM export.
* Fixed: blockquote's `quote_size` is a multiplier from 0.5 to 2, not a size in pixels. Read as pixels, 62 produced a 3,720px quotation mark.
* Fixed: `__globals__` was reported as an unknown setting key on every write that bound a control to a global colour or font.

= 1.20.1 =

* Fixed: the unknown-setting warning added in 1.20.0 let through any name starting with a prefix the widget already uses — which is exactly how the two names that prompted the feature were shaped, so it caught neither. Names are now checked against the widget's real control list.

= 1.20.0 =

* Fixed: **update-page-settings wiped the page settings it was not given.** Sending two keys to a post replaced all of its stored page settings with those two — losing things like a popup's full-screen height. It now merges, like every other write. **If you have used this tool, upgrade.**
* Fixed: the button widget's padding was published under the wrong name (`button_padding`, which belongs to the global kit styles, instead of the widget's `text_padding`), so setting it did nothing at all.
* Fixed: on a site behind an access wall, rendering a page returned the login screen as if it were the page, complete with warnings describing the login screen. It now says so instead, and accepts a preview key so it can fetch the real page.
* Added: writes now report setting names they do not recognise, with suggestions. Previously a misspelled control name was accepted, stored and reported as a success, so it looked applied everywhere except on the page.

= 1.19.1 =

* Fixed: clicking **KarMCP** in the sidebar opened the Skills list instead of the panel. WordPress sends a top-level menu to whatever sits first in its submenu, and Skills was getting there first. **If you installed 1.19.0, upgrade.**
* Fixed: Skills vanished from the sidebar. Hiding the duplicated section rows was hiding it too, and the panel's own rail does not list it.

= 1.19.0 =

* Changed: the sections now live in a collapsible rail down the left of the panel instead of a row of tabs across the top. All twelve are visible at once, and the rail collapses to icons when you want the width back. The choice is remembered per user.
* Changed: the WordPress sidebar no longer repeats the section list — it keeps just the KarMCP entry. Every section is still reachable by its own URL, exactly as before.
* Changed: the top bar now carries only the brand and the log, history, changelog, help, notification and cloud controls.

= 1.18.1 =

* Fixed: on the Connection tab, the four status cards rendered as giant black discs. The 1.18.0 cleanup removed their styles while the page still used them, so the icons inside had no size and expanded to fill the screen. **If you installed 1.18.0, upgrade.**

= 1.18.0 =

* Changed: the admin panel has been redesigned. Flat surfaces separated by borders instead of cards on shadows, tighter corners, a grey palette tinted toward the brand colour, an inverted app bar and section navigation as pills. Nothing moved: same screens, same tools, same settings.
* Changed: every colour in the admin stylesheet now comes from a variable, so the panel can be repainted from one block. Previously 305 colours were written literally, which is why past palette changes only ever landed on part of the screen.
* Fixed: nine style variables were referenced but never defined, so those rules quietly ignored the design system and kept hardcoded values.
* Removed: 494 lines of styling for screens the plugin no longer has, and a duplicate page header that was styled but never rendered.

= 1.17.2 =

* Fixed: with the Login Guard module enabled, every REST API request failed with a fatal error — core routes and the MCP server alike — while the front end and the admin kept working, which made it very hard to place. The module's user-enumeration hardening treated the route namespace that WordPress stores alongside each route's handlers as if it were a handler. Present since 1.4.0 and unrelated to the plugin version: downgrading did not clear it, because the module's state lives in an option. **If you use Login Guard, upgrade to this version.**

= 1.17.1 =

* Changed: the filter that lets a site authorise MCP to touch protected meta now receives the post type and post id, so the authorisation can be narrowed to the one custom post type that needs it instead of applying everywhere. Existing filter callbacks keep working. Note that duplicate-post already covers the common case — copying a working plugin CPT wrapper — without opening the guard at all.

= 1.17.0 =

* Fixed: a post filled through MCP could end up never marked as built with Elementor, and then Elementor does not enqueue its CSS — the page renders with no containers, no padding and widget placeholders showing, or as an empty strip for a popup. Opening it in the editor once appeared to fix it. The flag is now written on every Elementor write, which also repairs posts left unflagged by an earlier version.
* Fixed: CSS classes set on a container were stored under the widget spelling (`_css_classes`) and never reached the HTML, silently killing every stylesheet rule that depended on them. Either spelling is now accepted and rewritten to the one the element type actually reads.
* Fixed: updating only the colours of a container that had a gradient background downgraded it to a flat colour, without being asked to. The background activator is now decided from the element's real settings instead of from the incoming payload.
* Added: `null` in an update-element or page-settings payload deletes the key instead of storing a null, so a setting can finally be removed rather than neutralised by guesswork.
* Added: `strip_media` on apply-template inserts a template block without the source's background images, overlays, widget images and image filters — the parts CSS cannot override.
* Added: `post_id` with `mode: append | replace` on build-page writes a structure into an existing post of any type, replacing the three-call detour that left orphan scratch pages behind. `replace` requires `confirm: true`.

= 1.16.4 =

* Fixed: 1.16.3 shipped the main plugin file with a UTF-8 byte order mark, which put three stray bytes in front of every response the site produced. The MCP server became unreachable, the OAuth discovery documents and the authorize endpoint returned 404, and connecting a client failed at registration — while the site itself kept loading normally. Upgrade straight to this version.
* Added: a test that fails on a byte order mark anywhere in the plugin, since the file stays valid PHP and nothing else catches it.

= 1.16.3 =

* Changed: the static-analysis ruleset (injection, unescaped output, nonces, prepared SQL, i18n, global prefixes, PHP 8.1 compatibility) went from 87 errors to zero, and now blocks. It had been in the repo for a while without ever being run.
* Changed: every plugin table name in a query now uses `%i`, WordPress's identifier placeholder, instead of being concatenated into the SQL. Two of those build SQL from caller-supplied table and column names and were escaping them by hand; that is now WordPress's job.
* Changed: the handful of places where the obvious fix would be wrong — an OAuth redirect that must leave the site, an Authorization header that must not be rewritten, PHP template source that cannot be sanitized — now carry the reason in a comment instead of looking like oversights.
* Fixed: the login redirect to the site's own login screen now uses `wp_safe_redirect()`; `$_SERVER` reads across OAuth, the WebP rewriter and the login guard are sanitized; the Themer canvas template prefixes its one variable, which really is a global.
* Fixed: eleven translator comments added, so the placeholders in those strings can be translated correctly.

= 1.16.2 =

* Changed: the tool classes no longer load on requests that never ask for a tool. All 76 files under `includes/abilities/` â€” 1.1 MB of source, several MB in memory â€” were parsed on every request, front-end page views included. They now load the moment something first asks for a tool. On a host with a 128 MB limit this is the difference between the site working and not.
* Fixed: inserting a Kadence or Spectra block at a position that does not exist reported success. The tree operations return the tree untouched for a path that does not resolve, so the save succeeded and the tool answered "added" for a block it never inserted. Every caller now validates the position first, including an unrecognised `position.mode` â€” a typo in `inside` was a silent no-op too.
* Fixed: `move-block` reported a move it had not performed. Checking that both paths resolve is not enough: a block moved onto its own position, and a target inside the subtree being moved, both resolve and neither is a move.
* Fixed: `detect-elementor-version` failed validation on sites without Elementor Pro â€” it returned null against a schema declaring a string, from the one tool an agent is told to call first.
* Fixed: OAuth sign-in never started on a WordPress installed in a subdirectory. The endpoint was advertised with the subdirectory and matched without it, so the URL we published was never served and sign-in dead-ended on the site's 404 page.

= 1.16.1 =

* Fixed: the contrast check went silent when it had nothing to look at. Run against a real Elementor page, it found zero text declaring a colour inline â€” Elementor writes its colour into a generated stylesheet â€” and the report said nothing about contrast at all. Silence is ambiguous and the ambiguity flatters the tool, so it now reports that contrast was not checked, and that this is not a pass.

= 1.16.0 =

* New **`audit-page-a11y`**: image alt text, link and form-field accessible names, heading outline, declared language, page title, landmarks, duplicate ids and text contrast â€” each finding carrying its WCAG success criterion and a recommendation, scored 0-100 on the same curve as the SEO audit.
* It states its own limits in its output. An automated check catches a minority of WCAG failures: it sees a missing alt attribute, not whether the alt text describes the image; a skipped heading level, not whether the headings mean anything. Reading order, focus order and keyboard traps need a browser and a person. A clean report is a floor, not a certificate.
* New `KarMCP_Color_Contrast`: the WCAG maths, exact where the spec is exact and silent where it is not. A colour from a stylesheet, a variable, a gradient, a background image or a translucent overlay comes back `inconclusive` with its reason â€” never a pass, because a false pass closes the question.
* When the font size is unknown â€” the normal case, since sizes live in stylesheets â€” two thirds of the range still have a rigorous answer: below 3:1 the text fails at any size, at or above 4.5:1 it passes at any size, and only the band between them depends on the size.
* `render-page` collects the text elements that declare a colour inline, walking up for the nearest declared background and refusing gradients and images rather than guessing.
* `get-page-snapshot` now fills its `a11y` section as well as `seo`.
* Fixed: the readability recommendation always blamed sentence length. It now names whichever factor actually weighs â€” a page with 11-word sentences graded hard on vocabulary was being told to shorten its sentences.

= 1.15.1 =

* Fixed: readability was measuring the page furniture instead of the copy. 1.15.0 scored the whole visible text, which on a normal page is mostly navigation, button labels and headings â€” none of which end in a full stop. A real homepage came back at 8/100, "muy difÃ­cil", averaging 45 words per sentence, off copy that is plain Spanish; the recommendation told the owner to shorten sentences that were already short.
* Readability now measures prose only: `<p>` elements outside nav, header, footer, aside and form. Without enough prose it reports `insufficient` rather than falling back to the full text, because that fallback was the bug.
* Sentences are counted per paragraph with a minimum of one. A paragraph with no full stop is still a sentence; run together, a page of short paragraphs reads as one enormous sentence.
* `render-page` now returns `text.prose` and `text.prose_words` alongside `text.excerpt` and `text.words`: everything a visitor can read, and the running prose, as two separate views. Additive.

= 1.15.0 =

* New: **readability in the right formula for the language**, reported inside `audit-page-seo` â€” score, band and average sentence length. Flesch Reading Ease is calibrated on English; run Spanish through it and you get a number that looks valid and is not, because Spanish carries more syllables per word, so every page comes back "difficult".
* Spanish is scored with **Szigriszt-Pazos** on the **INFLESZ** scale; English keeps Flesch. A language with no calibrated formula gets no score and says so instead of borrowing one.
* The language is resolved per post, not per site â€” Polylang and WPML are asked first, because a multilingual site has a locale per page and that is exactly where the wrong-formula mistake lands.
* The Spanish syllable counter is exact, not heuristic: vowel groups, diphthongs, hiatus, the accent that breaks a diphthong (dÃ­a, paÃ­s), the silent h that does not (ahijado), Ã¼, and y as a vowel after one (rey, muy).
* Reported, never scored. A dense text is a legitimate choice for a legal or technical page, so the finding gives the number and leaves the judgement to whoever knows the audience. Pages under 100 words get no score: thin content is already its own finding.

= 1.14.2 =

* Fixed: the SEO report contradicted itself on a site whose meta tags come from somewhere other than an SEO plugin. It said the meta description "cannot be set on this page at all" two lines above measuring the 160-character one the page was serving â€” a theme or another plugin was emitting it.
* `seo-plugin-missing` now checks what the page actually emits: when something else is producing the tags it says so, and warns that a second source emitting the same tags is its own problem. The blunt message survives only when nothing is emitted, which is when it is true.
* Fixed: `og-image-missing` looked only at what the SEO plugin had stored, so a social image emitted by the theme was reported missing while the page carried one. It now checks the served page too.
* `render-page` and the audits now read `og:image` from the document head, querying both `property` and `name` â€” Open Graph is specified with `property`, but enough plugins emit it as `name` that reading one of them misses real tags.

= 1.14.1 =

* Fixed: the SEO audit gave advice you could not follow on a site with no SEO plugin. It reported the missing meta description and the missing social image â€” both true â€” and told the reader to write one and to configure a fallback "in the SEO plugin", when there was no plugin and no screen to do either in. WordPress on its own has neither field.
* The audit now names the cause once as `seo-plugin-missing` and the findings underneath stop pointing at something that is not installed. Graded `info`, not a warning: the consequence already costs points through the missing description, and charging for the cause too would penalize the same fact twice. The social-image finding is suppressed in that case, and `canonical-missing` points at the theme instead of at plugin settings.

= 1.14.0 =

* New **`audit-page-seo`**: the plugin can now judge its own work, not just describe it. It renders the page, reduces it to the same digest `render-page` uses, reads whatever the active SEO plugin has stored, and grades the two **together** â€” because the gap between stored intent and rendered result is where the real findings live: a title template that expands to nothing, a description the theme never emits, a noindex nobody meant to leave on.
* Every finding carries a recommendation, not just a verdict: title and description presence and length, H1 and heading outline, content depth, image alt text, links still pointing at `#`, leftover shortcodes, indexability, canonical, declared language, and focus keyword placement. Scored 0-100 with a letter grade, so two runs can be compared.
* `scope: "content"` works on drafts; `scope: "full"` fetches the served page and is the only one that can judge the real title, canonical and `lang`. The report says which it used rather than grading a fragment as if it were the page.
* Lengths are counted in characters, not bytes â€” every accent in a Spanish title is two bytes, and byte-counting would fail a title that displays perfectly.
* New `KarMCP_Seo_Meta`: one vocabulary over Yoast, Rank Math and Slim SEO, handling each one's encoding â€” Yoast's tri-state robots value where only `1` is a noindex, Rank Math's robots array, merged rather than overwritten so directives you set on purpose survive. All in One SEO and SEOPress are detected but not read: they keep their data in their own tables, and the `karmcp_seo_meta` filter is there for an integration that knows the schema.
* `get-page-snapshot` now fills its `seo` section, which had reserved the seam and returned an empty stub since the beginning.

= 1.13.1 =

* Fixed: an element extension applied nothing to the page. Atomic elements with a template render their opening tag from Twig, off `settings.classes` and `settings.attributes`, and their `before_render()` is empty on purpose â€” so the wrapper attributes the compiled code was writing never reached the HTML. The symptom was misleading: the hook ran, the stylesheet and script were enqueued, and the element still came out without the class. The generated code now writes into the element's own props, and keeps writing the wrapper for elements that render without a template.
* Fixed: the `kind` enum on the export and cloud-backup tools was written by hand and lacked `extension`, so exporting one failed validation even though the code supported it. Both now read the bundle's own list of kinds.

= 1.13.0 =

* New **element extensions**: the third kind of sandbox artifact. Widgets and blocks are things you insert; an extension is an option that appears **on elements Elementor already ships** â€” a control in the settings panel of any container that switches an effect on. Requires Elementor 4.2+ (it attaches to atomic elements, not classic sections).
* The agent supplies a spec â€” target element types, typed props, and what ends up in the HTML â€” and the plugin compiles the class that hooks Elementor's props schema, its panel controls, and its render.
* Two rules keep writing into a shared namespace defensible: prop names carry a `karmcp_` prefix so they cannot shadow one of Elementor's (and two active extensions cannot declare the same one), and only `data-` and `aria-` attributes can be written â€” never `onclick`, `style` or `href`. A rule's CSS class is a compile-time literal, never a value from a control.
* An element that never touched an extension evaluates as the prop's declared default, so a rule like "not none" does not quietly apply to every container on the site.
* Extensions export, import and back up like the other artifacts; an imported one is recompiled locally from its spec rather than trusting the bundled PHP.

= 1.12.0 =

* The **Widget Builder** and **Block Builder** now work. Both screens shipped complete â€” store, loader, admin table, export/import â€” but the compiler that turns a design into code was missing and the access gates always returned false. The compilers are written, the gates are open to administrators, and the sixteen MCP tools the catalog listed are real.
* The AI never writes PHP. It supplies a spec â€” metadata, typed fields, and an HTML template with `{{placeholders}}` â€” and the plugin compiles it into an Elementor widget or a Gutenberg block. **The escape function is chosen by the field's declared type**, and there is no raw output modifier. Template text that is not a placeholder becomes a PHP string literal, so it cannot turn into code.
* Specs carrying PHP tags, `<script>` elements, inline event handlers or `javascript:` URLs are refused. Behaviour belongs in the spec's `scripts` field, which is served as a static file.
* Generated blocks are server-rendered: no build step, no per-block JavaScript, and the editor previews the same PHP the visitor gets. Editing a block's spec updates every post already using it.
* Artifacts load only from a hash-verified manifest; a fatal deactivates the offending artifact rather than the site; a widget Elementor rejects is demoted to draft with the reason recorded. New filters `karmcp_load_generated_widgets` and `karmcp_load_generated_blocks` are site-wide kill switches.
* Fixed: those sixteen tools were seeded disabled and then hidden from the Tools screen by the Pro-category filter, so they could not be enabled at all. Fixed: the uninstaller left generated block posts behind.

= 1.11.1 =

* Each cleanup task on the **Optimize** tab now runs without reloading the page: its own button, a spinner beside it while it works, and the result reported in the row â€” the same way plugin updates already work on the Security tab.
* Deletions are batched, so a task often needs running more than once. Every count on the table is refreshed after each run, buttons re-arm or disable themselves accordingly, and the confirmation now names the task it is about to delete.
* Still works with JavaScript off: each row is a real form that posts that single task.

= 1.11.0 =

* New **Optimize** tab and `clean-database` tool: removes old post revisions, expired transients, orphaned metadata, spam and trashed comments, trashed posts, and reclaims table overhead with OPTIMIZE TABLE. Each task shows what it found, what it can break, and whether it can be undone â€” four of the six are permanent and are marked as such before the button.
* This is not caching. It removes work the database is doing for nothing, which is the kind of speed that survives a cache flush.
* Revisions, comments and posts are deleted through the WordPress API so their metadata goes with them; deleting the rows directly would leave orphans behind. Only rows whose owner is already gone are removed with SQL.
* Deletions run in bounded batches so a large site cannot time out; run it again until the counts reach zero.
* It deliberately does not change autoloaded options, does not remove tables left by uninstalled plugins, and does not cache â€” the reasons are on the screen.

= 1.10.1 =

* Fixed: only one plugin could be updated per page load. A successful upgrade makes WordPress delete the `update_plugins` transient, and `Plugin_Upgrader` reads that transient to find what to install â€” so the second update in the same request found nothing and reported a failure that had never been attempted. The transient is now refreshed before each upgrade.
* Fixed: "The update did not complete" was uninformative and often wrong. The two cases â€” the upgrader refusing, and WordPress offering no update at all â€” are now reported separately and by name.

= 1.10.0 =

* Added **Where the score went**: a fold-out breakdown of the security score showing what each check cost and which categories are capped. When the total penalty exceeds the 100 points available it says so, and says how much must be cleared before the score starts to move â€” otherwise fixing things appears to do nothing.
* Updating a plugin from the Security tab no longer reloads the page: the button shows a spinner and the row reports the result in place. A failed update restores the button, since the plugin is unchanged.

= 1.9.0 =

* Added a **progress bar** to the security scan, which now runs one check per request instead of all of them in one. It names the check in flight, and it is more than cosmetic: a long scan could previously outlast the host's `max_execution_time` and die with nothing stored, reported as a failed scan. Short requests mean a timeout can now cost at most the one category it lands in.
* Both the stepped and the one-shot scan share the same code, and a test pins that they produce identical reports.
* Without JavaScript the original single-request scan still runs, so the button is never dead.

= 1.8.0 =

* Added an **Update** button beside the vulnerabilities that updating would fix. One button per plugin rather than per vulnerability â€” fifteen JetEngine CVEs are one update â€” and the row says how many that update actually clears, since an available version often covers some and not others.
* Where WordPress offers no update, there is no button and the row explains why; for a premium plugin that usually means the licence has stopped delivering updates.
* Updating goes through the same guard as the MCP tool, including the exception that lets Elementor and Elementor Pro be updated when a known vulnerability affects the installed version. A plugin that was active before is reactivated afterwards.

= 1.7.5 =

* Fixed: the fatal-error handler drop-in did not update when the plugin did. It is a copy in `wp-content/`, so the fix shipped in 1.7.4 never reached sites that already had the handler installed â€” the old file kept recording non-fatal warnings. The drop-in now carries the plugin version and is rewritten automatically when it falls behind.

= 1.7.4 =

* Fixed, and this one mattered: the fatal-error handler recorded any error `error_get_last()` returned, not only fatal ones. On a live site the log filled with `ini_set()` warnings, an undefined-property notice and a library deprecation â€” and the auto-pause counter counted them, so with that option on **Elementor Pro would have been deactivated over a notice**. Only the five types WordPress itself treats as fatal are recorded now.
* Fixed: fifteen separate JetEngine CVEs were grouped into one row labelled "Ã—15" that showed only the first. The CVE is now part of the grouping key.
* Fixed: vulnerability findings in the report table showed no version, fix or score. They now read `slug installed â†’ fix   CVSS n   CVE-â€¦`.

= 1.7.3 =

* Added a **Refresh vulnerability feed now** button to the Security tab. Enabling the module and entering an API key scheduled the first download two hours later with no way to ask for it sooner, so the data you enabled the module to see stayed empty. The button reports the outcome, including the rate-limit response, which is expected on the free quota and leaves stored data untouched.

= 1.7.2 =

* Fixed: a failure in any one of the five checks discarded the whole scan. The malware, integrity and hardening results were complete when something threw inside the software audit, and all of it was replaced by "scan failed". Each check now runs in its own guard â€” the ones that finish are kept, and the one that broke is reported as a warning in its own category.
* Fixed: the failure recorded only the exception message, with no class, file or line, which made it impossible to find what threw. Failures now name all three. The message seen in practice, `Attempt to assign property "plugin" on false`, comes from a third-party update checker reached through the scan; this release will say which one.

= 1.7.1 =

* Fixed: the malware audit flagged every PHP file that Code Snippets stores under `wp-content/uploads/code-snippets/` â€” one per snippet â€” as a critical finding. On a real site that alone was 142 of 144 criticals, all false. PHP under uploads from plugins known to put it there is now reported once as information; anything in an unrecognised directory is still critical, and all of them are still pattern-scanned.
* Fixed: findings never showed which file they referred to, so the report was 142 identical rows with nothing to act on. Locations are now displayed, and repeated findings are grouped with a count.
* Fixed: the security score could not tell "needs updating" from "compromised". Warning penalties were uncapped, so 46 ordinary warnings floored the score at 0 before anything else counted. They are now capped per category, like criticals already were.
* Fixed: warnings were charged to whichever category the previous critical finding belonged to.

= 1.7.0 =

* New **Known Vulnerabilities** module (off by default): checks every installed plugin and theme against the Wordfence Intelligence database and adds a fifth category â€” CVE, CVSS score, and the version that fixes it â€” to the Security report. Needs a free API key from a wordfence.com account, under Integrations.
* **Nothing about your site is sent anywhere.** The endpoint only serves the complete feed and takes no parameters, so the whole database is downloaded once a day and matched locally; there is no per-plugin query that could leak an inventory.
* The feed is 11.2 MB gzipped and 150 MB decoded, which is far too large to decode in PHP, so it is streamed and written record by record â€” verified against the real feed at 18 MB of peak memory.
* Elementor and Elementor Pro, normally not updatable over MCP, may now be updated **when a known vulnerability affects the installed version and the available update actually clears it**. Both conditions are required. KarMCP still never updates itself.
* New tool `list-vulnerabilities`: worst CVSS first, with the fixing version and whether an available update really resolves that specific vulnerability. A feed that was never downloaded reports itself as unknown rather than returning an empty list.
* Vulnerability records carry the Defiant copyright notice and a link to the record, which is the condition the data is licensed under.

= 1.6.0 =

* New **fatal-error handler** (install it from the Security tab). WordPress loads `wp-content/fatal-error-handler.php` instead of its own when it exists, so this runs at the moment of the crash â€” the only place it can help, since once PHP dies the REST API and MCP die with it. It records what broke and, optionally, deactivates the plugin responsible so the next request succeeds.
* Deactivation needs **three fatals in ten minutes**, never the first: one transient error must not take a shop offline in a different way than the bug would have. Off until switched on, honours a protected list, and KarMCP can never deactivate itself.
* Note: the Recovery Mode built into WordPress does not fix a broken site â€” it emails a link and pauses the extension for that recovery session while visitors keep seeing the error.
* New tools `get-fatal-log`, `list-paused-plugins` (read-only) and `resume-plugin` (requires confirm).
* New tool `update-core`: updates WordPress itself. Ships disabled and requires confirm â€” it replaces the code serving the request, so a failure takes the site down rather than returning an error. It reports whether the fatal-error handler is installed, which is what makes the offer reasonable.

= 1.5.0 =

* New **Security** tab. The four audits â€” malware heuristics, core-file integrity against wordpress.org checksums, configuration hardening, outdated and abandoned software â€” have existed since 3.0.0 and were never visible inside WordPress; `scan-security` returned its report to an agent and nowhere else. Now there is a screen with the score, the grade, the critical findings and how old the answer is.
* Scans run **once a day in the background**, not only when someone asks, with an admin notice on critical findings. A scanner that only runs on request is not monitoring.
* A scan that never ran, failed, or is more than two days old is shown as exactly that â€” never as a clean bill of health. A failed scan discards the previous report so it cannot masquerade as yesterday's good news.
* New tool `harden-site`: applies four of the hardening findings (dashboard file editor, XML-RPC, version disclosure, security headers). Dry-run by default, reversible from a checkbox, and recorded in History. Each fix is a switch the plugin owns rather than an edit to `wp-config.php`.
* Three hardening findings are never fixed automatically and the plan says why: `WP_DEBUG_DISPLAY` is read before plugins load, renaming the `admin` account breaks whatever signs in as it, and HTTPS needs a certificate on the server.

= 1.4.0 =

* New **Login Guard** module (KarMCP â†’ Modules, off by default): blocks brute-force sign-ins by counting failures per IP address *and* per username separately, with an escalating delay â€” 15 minutes, then 30, then an hour â€” that doubles to a hard ceiling of 24 hours and is never permanent.
* Login Guard handles the reverse-proxy trap explicitly. Behind Cloudflare every visitor shares one address, so a naive guard locks out the world on the fifth failure by anybody; the header that fixes it is forgeable by anyone. The guard uses REMOTE_ADDR by default and reads the forwarded header only from proxy addresses you declare.
* Login Guard optionally blocks anonymous user enumeration (`/wp-json/wp/v2/users` and `?author=N`) and drops XML-RPC `system.multicall`, which batches hundreds of password guesses into one request. Disabling XML-RPC breaks Jetpack and the mobile app, so it is a switch.
* New tools `list-login-lockouts` (read-only, enabled) and `clear-login-lockout` (opt-in). Clearing a lockout also forgets its escalation history, so the next lock starts from the base delay.
* The MCP server and its OAuth endpoints are exempt from the failure count, so a retrying client cannot lock out the agent.
* Internal: continuous integration on PHP 8.1-8.4 for the 544 tests, plus PHPCS and PHPStan. None of it ships in the plugin.

= 1.3.1 =

* Fixed: `upload-media` answered a bare "Permission denied" when `post_id` named a post that does not exist. `map_meta_cap()` resolves `edit_post` against a missing post to `do_not_allow`, so asking the capability before checking existence reported a permission problem for a mistyped id â€” and made the tool's own "no such post" message unreachable. Existence is now checked first, and all three refusals (no `upload_files`, no such post, no rights on that post) name the actual cause.
* Fixed: a file whose contents did not match its extension â€” PHP named `.jpg` â€” was refused by WordPress in the site's own language, with a message about permissions rather than about the file. It is now verified and refused before the upload, as `content_type_mismatch`, naming the file and the extension it contradicts. WordPress still runs its own check afterwards.
* Fixed: every upload failure suggested retrying with `convert_webp:false`, including failures that flag could not affect.

= 1.3.0 =

* New tool `upload-media`: uploads a file from the client machine into the Media Library by sending its bytes as base64. The companion to `sideload-image`, which can only fetch a URL the server already reaches â€” so until now an agent could not upload the photo sitting on the user's own disk. Accepts optional alt text, title, caption, description, and a `post_id` to attach it to. It goes through `media_handle_sideload()` like every other upload, so the type allowlist, the SVG sanitizer and the WebP/compression pass all still apply.
* `upload-media` refuses three things before writing: a file type this site does not accept (checked from the extension before the payload is decoded), a payload over the site's upload limit (estimated from the encoded length, with the limit named in the error), and attaching to a post the caller cannot edit â€” `upload_files` says a user may add files, not whose pages they may attach them to.

= 1.2.1 =

* Fixed: a tool that threw reported nothing you could act on. WordPress keeps only the exception's message, so when Elementor refused to save the Elementor kit with a bare "Access denied.", that literal â€” which appears nowhere in this plugin â€” was the entire report: no class, no file, no line. Anything escaping a tool now returns an error naming all three.
* Fixed: update-global-colors and update-global-typography checked `manage_options`, but Elementor requires `edit_post` on the kit post itself and throws if it is missing. The two are unrelated, so a capability manager that puts `elementor_library` under type-specific capabilities breaks both tools for every user, administrators included. They now check the capability Elementor actually enforces and say which post and which capability failed.
* Fixed: a per-post permission denial returned a bare "Permission denied" naming neither the post, the post type, nor the capability. update-post and delete-post now report all three.

= 1.2.0 =

* Security: create-post checked the generic `edit_posts` and then wrote any post type it was given, so an Editor could create posts of types this plugin gates behind `manage_options` â€” including Agent Skills and PHP snippets, bypassing their own guards. Capabilities are now resolved against the target post type, and the plugin's own types are refused outright.
* Security: get-post returned any post to anyone who could edit any post. It now applies the per-post `read_post` capability, so private drafts and the plugin's own private types are no longer readable by ID alone.
* Security: list-posts enumerated any post type it was asked for, so titles and slugs of types the caller cannot read came back anyway. Requested types are now filtered by capability, and private posts by another author are excluded.
* Security: a download could be redirected to the cloud metadata service at 169.254.169.254 â€” WordPress's own redirect check permits that range. Every hop is now re-validated against the plugin's address table, IPv6 and IPv4-mapped forms included.
* Security: OAuth dynamic client registration is rate-limited per address, and the granted scope is reduced to what the server actually supports instead of echoing back whatever the client asked for.
* Fixed: uninstalling left roughly two dozen options and all five plugin tables behind, including OAuth clients and live tokens. The cleanup now sweeps by prefix and drops the tables, once per site on a network install.
* Fixed: the plugin could not be translated â€” around three thousand strings and no `load_plugin_textdomain()` call. Translations placed in `languages/` now load.
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
* Agent Skills: short operating manuals you write once and every connected agent reads. Listed under KarMCP â†’ Skills, read-only over MCP. Documented under 1.0.0 but not part of that build; it ships here.
* Guardrails: an opt-in module of site rules above the capability checks â€” read-only mode, destructive-tool blocking, a freeze window, protected posts and post types. Enforced on every write and published into the agent's context. Documented under 1.0.0 but not part of that build; it ships here.
* Fixed: module cards said a feature required "an active Pro license", but this build has no licensing at all. They now say the feature is not included in this version.

= 1.0.0 =

* Independent release under the KarMCP name, with its own MCP namespace (`karmcp/`) and server route (`/wp-json/mcp/karmcp-server`).
* No telemetry, no licensing SDK, and no auto-updater: the plugin makes no outbound network calls unless you configure one.
* Theme builder: unlimited templates per type, granular display conditions (per post, term, author, date), exclude rules and working priority.
* Fixed: the theme builder's Hello Elementor adapter never injected anything, because it targeted hooks that theme does not fire.
* Fixed: the theme-template post type exceeded WordPress's 20-character limit, so it never registered and every template insert failed.
* Removed a "Backup & Migrate" tool category whose implementation is not part of this build; it was showing seven toggles that could never do anything and inflating the dashboard's tool counters.
* Fixed a failing announcements request that retried every two hours on installs with no service configured.
