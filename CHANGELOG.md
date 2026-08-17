# Changelog

All notable changes to KarMCP are documented in this file.

## [1.14.2]

### Fixed

- **The SEO report contradicted itself on a site whose tags come from somewhere else.** Running 1.14.1 against a real site found it: no SEO plugin the audit recognises, and a perfectly good 160-character meta description served anyway — a theme, or Jetpack, or anything else can emit those tags. The report said the field *"cannot be set on this page at all"* two lines above measuring the one that plainly existed.

  `seo-plugin-missing` now checks what the page actually emits. When something is producing the tags it says so — they can be read here but not changed here — and points out that a second source emitting the same tags is its own problem. The blunt message survives only when nothing is being emitted, which is when it is true.

- **The same mistake in the other half:** `og-image-missing` looked only at what the SEO plugin had stored, so a social image emitted by the theme was reported missing while the page plainly carried one. It now checks the served page too.

- `KarMCP_Content_Extractor` reads `og:image` from the document head, querying both `property` and `name` — Open Graph is specified with `property`, but enough plugins emit it as `name` that reading one of them misses real tags.

## [1.14.1]

### Fixed

- **The SEO audit gave advice you could not follow on a site with no SEO plugin.** Running 1.14.0 against a real site turned this up immediately: the site had no SEO plugin at all, and the report still said *"write one sentence"* for the missing meta description and *"configure a site-wide fallback in the SEO plugin"* for the missing social image.

  Both findings were true and neither was actionable. WordPress on its own has no meta description field and no social image field, so the reader was being sent to look for a screen that does not exist — which is worse than saying nothing, because it costs a search before the dead end.

  The audit now names the cause once, as `seo-plugin-missing`, and the findings underneath it stop pointing at a plugin that is not installed. It is graded `info` rather than a warning on purpose: the consequence already costs points through `description-missing`, and charging for the cause as well would penalize the same fact twice. The social-image finding is suppressed entirely in that case, since there is nowhere for a per-page image to live. `canonical-missing` also stops blaming SEO-plugin settings and points at the theme, which is the only thing that can be dropping `rel_canonical` when no plugin is active.

  A test now fails the build if any recommendation mentions an SEO plugin while none is active.

## [1.14.0]

### Added

- **`audit-page-seo`: the plugin can finally judge its own work.** Every tool up to now either wrote a page or described it. `render-page` was the first that could disagree with the agent about what came out. This one goes further and says whether what came out is any good: it renders the page, reduces it to the same digest, reads whatever the active SEO plugin has stored, and grades the two **together** — because the gap between them is where the real findings live. A title template that expands to nothing, a description the theme never emits, a noindex nobody meant to leave on.

  Every finding carries a recommendation, not just a verdict. Title and description presence and length, H1 and heading outline, content depth, image alt text, links still pointing at `#`, leftover shortcodes and placeholder text, indexability, canonical, declared language, and focus keyword placement when one is stored. Scored 0-100 with a letter grade so two runs can be compared.

  `scope: "content"` works on drafts. `scope: "full"` fetches the served page and is the only one that can judge the real title, the canonical and the `lang` attribute — the report says which it used rather than quietly grading a fragment as if it were the page.

  Two decisions worth knowing about. Lengths are counted in **characters, not bytes**, because every accent in a Spanish title is two bytes and byte-counting would fail a title that displays perfectly. And a stored value still carrying `%%variables%%` is reported as a template rather than measured, because measuring it would grade the template instead of what the visitor reads.

- **`KarMCP_Seo_Meta`** — one vocabulary over Yoast, Rank Math and Slim SEO, reusing the field names the Slim SEO integration already used rather than inventing a second set. It handles the encodings each plugin chose: Yoast's tri-state robots value where only `1` is a noindex and `2` is an explicit index, Rank Math's robots array, which is merged rather than overwritten so a `noarchive` somebody set on purpose survives.

  All in One SEO and SEOPress are **detected but deliberately not read**: they keep their data in their own tables, and guessing at a foreign schema is how you ship a reader that silently returns empty strings forever. The `karmcp_seo_meta` filter is there for an integration that actually knows.

- `get-page-snapshot` now fills its `seo` section, which had reserved the seam and returned an empty stub since the beginning. Passing `include: ["seo"]` returns the score, the grade and the findings that need acting on.

### Changed

- The unresolved heavy sections of `get-page-snapshot` reported themselves as `pro_gated`, a leftover from a tier split this build does not have. They now say `reason: "not_implemented"`, which is true. Nothing consumed the old key.

## [1.13.1]

### Fixed

- **An element extension applied nothing to the page.** Verifying the first real extension on a live Elementor 4.2 site turned up a mistake in how the compiled code wrote to the element: it used `add_render_attribute( '_wrapper', … )`, the way a classic widget would.

  Atomic elements with a template do not work that way. Their `before_render()` is empty on purpose — *"Twig template handles full rendering"* — and the opening tag comes from `_macros.html.twig`, which reads `settings.classes` and `settings.attributes`. The wrapper attributes never reach the HTML.

  The symptom was misleading enough to be worth recording: the hook **did** run — the extension's stylesheet and script were enqueued and the critical CSS was printed — and the element still came out without the class. Everything looked wired up except the one thing that mattered.

  The generated code now writes into the element's own props, in the shape each prop type declares, and keeps writing the wrapper too for any atomic element that renders without a template. Whichever path an element takes, one of the two lands.

- The `kind` enum on `export-sandbox-artifact` and on the cloud backup tool was written by hand and never grew an `extension` entry, so exporting an extension failed input validation even though the code supported it. Both now read `KarMCP_Sandbox_Bundle::KINDS`.

## [1.13.0]

### Added

- **Element extensions: the agent can now add its own options to Elementor's elements.** The two builders shipped in 1.12.0 make things you *insert* — a widget, a block. This makes something that was missing: an option that appears **on elements Elementor already ships**. A control in the settings panel of any container, that switches an effect on. It is the third artifact kind of the sandbox, with the same shape as the other two: a spec, a compiler, a hash-verified manifest, a kill switch and its own screen.

  The spec declares four things — which element types it attaches to, which props it adds, which controls edit them, and what ends up in the element's HTML. From that, the plugin compiles a class that hooks Elementor's three seams: `props-schema` so the value is declared and therefore saved, `controls` so the section shows up in the panel, and `frontend/before_render` to put a class and `data-` attributes on the element and bring in its CSS and JavaScript. **Requires Elementor 4.2 or newer**: extensions attach to atomic elements, not to classic sections and columns.

  Two rules make writing into someone else's namespace defensible, and both are enforced by the compiler. **Prop names carry a `karmcp_` prefix**, because Elementor's props schema is a single shared array where a collision would silently shadow a core prop — and two active extensions may not declare the same name either, which the store refuses at activation rather than leaving as a bug to find later. **Only `data-` and `aria-` attributes can be written**: an extension able to emit `onclick`, `style` or `href` would be an XSS vector with a nice interface. The CSS class of a rule is a literal validated at compile time, never a value that came from a control.

  A detail that only shows up when you run it: props are declared on *every* element, and most elements will have nothing stored. If an absent value read as an empty string, a rule like `not: "none"` would be true everywhere and the effect would land on every container nobody ever configured. The compiled code falls back to each prop's **declared default** instead, so an element that never met the extension evaluates as if the control were at rest.

  Styles are split into `critical` and `deferred` because there is no `get_style_depends()` for something that decorates an element it does not own: the critical part is inlined once per page so the effect does not flash, the rest is a file enqueued only where a rule applies.

### Changed

- `syntax_check()` — parse generated PHP without running it — moved from the block store to the sandbox base, so both compilers share it.
- Sandbox bundles gained the `extension` kind: extensions export, import and back up like blocks, widgets and snippets. An imported extension is **recompiled locally from its spec** rather than trusting the PHP in the bundle.

### Added

- **The Widget Builder and the Block Builder work.** Both screens existed and neither did anything: the store, the manifest loader, the admin table, the export/import and the cloud backup were all there, but the compiler that turns a design into code was not, and two access gates returned `false` unconditionally. An agent could reach the Sandbox and find a locked door with no key anywhere. The compilers are now written, the gates are open to administrators, and the sixteen MCP tools the catalog had always listed are real.

  **The agent never writes PHP.** It supplies a spec: metadata, a list of typed fields, and an HTML template with `{{placeholder}}` references. The plugin compiles that into an `\Elementor\Widget_Base` subclass, or into a `block.json` plus a `render.php`, and **the escape function is chosen by the declared type** — `esc_html` for text, `esc_url` for links, `esc_attr` in attributes, a cast for numbers, `wp_kses_post` for the one rich-text type that is allowed to emit markup. There is deliberately no raw modifier. Everything in the template that is not a placeholder is emitted as a PHP string literal, so template text cannot become executable code even when it looks exactly like it: a template containing `<?php echo "pwned"; ?>` renders those characters on the page. The tests assert that by running the compiler's own output.

  Specs are rejected before compiling if they carry a PHP tag, a `<script>` element, an inline `onclick=`-style handler, or a `javascript:` URL. Each of those has a legitimate home in the spec's `scripts` field, which is written out as a static file and enqueued only where the artifact appears.

  Specs carry a `spec_version` from the first release. The stored spec is the regenerable source of truth for the code, so the format has to be able to move without orphaning what people already saved.

  Generated blocks are **server-rendered**: one shared editor script, no build step and no JavaScript per block, and a ServerSideRender preview of the same PHP the visitor gets. Editing a block's spec updates every post already using it, because the markup lives in the render file rather than in post content.

  The existing safety machinery now has something to guard. Artifacts load only from a hash-verified manifest, so a file edited on disk is skipped rather than executed. A fatal during load or render is attributed to the artifact that caused it and deactivates that one artifact, so the next request is clean. A widget that errors when Elementor builds its controls is demoted to draft with the reason recorded, instead of white-screening the editor. Two new filters, `karmcp_load_generated_widgets` and `karmcp_load_generated_blocks`, are the site-wide kill switches.

  Both tool groups ship **disabled**, like everything else that writes executable code, and are switched on from **KarMCP → Tools**.

### Fixed

- **Tool groups flagged as Pro were removed from the Tools screen entirely**, which is right for a group whose abilities do not exist in this build — but the Widget and Block Builders were flagged too. Their sixteen tools were seeded disabled by default and then hidden from the only screen that could enable them, so they were unreachable by construction. The two implemented groups are no longer flagged, and the "PRO" badges and upgrade copy are gone from the Sandbox screens, which now say what is actually required: an administrator account.

- **The uninstaller left generated block posts behind.** The sandbox files went with the shared tree, but a block post is itself the source of executable code and would have resurrected the block on reinstall.

## [1.11.1]

### Changed

- **Each cleanup task on the Optimize tab now runs in place, with its own button and spinner, instead of reloading the page.** It follows the plugin-update row on the Security tab, which already worked this way: press the button, a spinner appears beside it, and the row reports what it did.

  The reload was the wrong shape for this work. Deletions run in bounded batches, so a task that starts at four thousand rows needs running more than once — and every run cost a full page load just to find out whether it was finished. Now the answer arrives in the row itself: how many were removed, how many are left, and whether to press it again.

  Every count on the table is recomputed after each run, not only the one that was clicked. The tasks are not independent — deleting revisions leaves free space behind, which is exactly what the table-overhead figure measures — so refreshing a single row would leave the others quietly stale. Buttons re-arm or disable themselves from those fresh counts, and the "nothing to clean" notice appears on its own when the last count reaches zero.

  The confirmation is now per task and names it, rather than one blanket warning that several of the selected tasks delete permanently. **The page still works with JavaScript off:** each row is a real form that posts that one task and comes back through the redirect, as before.

## [1.11.0]

### Added

- **An Optimize tab, and `clean-database`.** The performance analyzer has measured database bloat since 3.0.0 — size, autoloaded options, accumulated revisions, a stalled cron — and, exactly like the hardening audit before `harden-site`, only ever said so. This is the half that acts.

  Six tasks: old post revisions, expired transients, orphaned metadata, spam and trashed comments, trashed posts, and table overhead reclaimed with `OPTIMIZE TABLE`. Each one shows how much it found, what it would break, and **whether it can be undone** — four of the six are permanent and say so beside the checkbox, in red, before the button.

  **This is not caching, and the distinction is the point.** Caching hides slow work behind a stored copy. This removes work the database is doing for nothing: rows nothing can read, revisions nobody will open, expired options loaded on every single request. It is the kind of speed that survives a cache flush, and the kind a caching plugin cannot give you.

  Revisions, comments and posts are deleted **through the WordPress API**, not with SQL. Deleting a revision row directly leaves its postmeta behind, so the row count drops while the orphan count rises — cleanup that manufactures the mess it exists to remove. Only genuinely orphaned rows, whose owner is already gone, are removed with SQL, because there is no API for a row with no owner.

  Deletions run in **bounded batches**, so one click on a site with a hundred thousand revisions cannot become a request the host kills halfway. Run it again until the counts reach zero.

  Three things it deliberately will not do, listed on the screen rather than left to be discovered: it does not change autoloaded options (switching autoload off on the wrong one breaks the plugin that owns it, and no rule can tell which), it does not remove tables left by uninstalled plugins (recognising those means guessing, and a wrong guess deletes real data), and it does not cache anything.

  `clean-database` ships disabled and requires `apply:true` with `confirm:true`.

## [1.10.1]

### Fixed

- **Only one plugin could be updated per page load.** The second update in the same request failed with "The update did not complete", and reloading made exactly one more possible.

  `Plugin_Upgrader` reads the `update_plugins` transient to find the package to install, and a *successful* upgrade ends by calling `wp_clean_plugins_cache()`, which deletes that transient. So the second upgrade found nothing on offer and returned `false` — which this reported as a failed update when in truth it had never started. The transient is now refreshed before each upgrade, exactly as the `update-plugin` MCP tool has always done; joining the two paths is what would have avoided this in the first place.

- **"The update did not complete" said nothing useful, and was often wrong.** `false` from the upgrader now says the upgrader refused it and names the usual causes, and the case where WordPress is simply offering no update is reported as that, with the note that a premium licence not delivering updates looks identical.

## [1.10.0]

### Added

- **"Where the score went": the arithmetic behind the number.** A fold-out table showing, per check, how many criticals and warnings it found, how many points that cost, and — the part that matters — whether the category is **capped**. Capping is why more findings in one place stop costing anything.

  It exists because the score alone misleads once it bottoms out. A site can lose 160 points against the 100 available, and then clearing the single largest block of findings moves the number not at all, which reads as "nothing I do helps". When the total exceeds 100 the panel now says so outright, and says how much has to be cleared before the score will begin to move at all. Between 0 and 60 the grade says *act*, not *how much*.

- **Updating a plugin no longer reloads the page.** The button disables itself, shows a spinner and "Updating…", and the row reports its own outcome in place. An update takes several seconds, and a button that looks inert for that long gets clicked twice — which would have started a second upgrade over the first. A failed update brings the button back, because the plugin is still exactly as it was.

## [1.9.0]

### Added

- **A progress bar on the scan, and the restructuring that makes one honest.** The scan used to be a single request: click, wait in silence, page reloads. There was nothing to show a bar with because there was nothing to report until it was all over.

  It now runs one check per request — malware, integrity, hardening, software, vulnerabilities — with the browser stepping through them and naming the one in flight. That is not decoration. A malware walk over a large tree can outlast `max_execution_time`, and when it did, the request died with nothing stored and the tab reported a failed scan. Short requests mean a host limit can now cost at most the single category it lands in; everything already finished is banked.

  Both paths share one `run_check()`, and a test pins that stepping the checks adds up to exactly what running them together produces — otherwise the bar and the nightly cron would slowly start reporting different things about the same site.

  The form still posts normally and the script intercepts it, so without JavaScript the original one-shot scan runs unchanged. The button is never dead.

  Progress lives in a transient, not an option: it is scaffolding for the next minute, and closing the tab halfway should not leave anything behind.

## [1.8.0]

### Added

- **An Update button beside the vulnerabilities an update would fix.** The roadmap called this the biggest return in the whole module — the feed already names the version that fixes each vulnerability, and `update-plugin` already worked — and then nothing joined them, so the report told you what was wrong and left you to go and do it by hand.

  **One button per plugin, not per vulnerability.** Fifteen JetEngine CVEs are one update. The row says how many that update actually clears, which on a real site is often not all of them: an available version may reach the patched version of some and not others, and "clears 3 of 3" when it clears one would send somebody away believing they were finished.

  Where WordPress offers no update at all — which on the test bed is exactly what a premium plugin whose licence has stopped delivering looks like — there is no button and the row says so, rather than a button that would do nothing.

  Updating goes through the same Package Guard as the MCP tool, including the vulnerability exception: Elementor and Elementor Pro are updatable here precisely when a known vulnerability affects the installed version and the update clears it, and never otherwise. A plugin that was active before is reactivated afterwards — `Plugin_Upgrader` deactivates to work, and without that step "update" quietly becomes "update and switch off".

## [1.7.5]

### Fixed

- **The fatal-error handler drop-in never updated with the plugin.** It is a copy written into `wp-content/`, so updating KarMCP left the old file running. That turned 1.7.4 into a fix that did not apply: the handler was corrected to ignore non-fatal errors, the plugin was updated, and the file on disk carried on recording `ini_set()` warnings exactly as before — visibly, in the log, seconds after the upgrade. Every future change to the template would have been just as silent, which makes this worse than the bug it hid. The drop-in now carries the plugin version in its header and is rewritten automatically when they diverge. Somebody else's handler is still never touched.

## [1.7.4]

> Everything here came from reading one real scan. The first of the three was a hazard, not a nuisance.

### Fixed

- **The fatal-error handler recorded errors that were not fatal — and counted them towards deactivating a plugin.** `error_get_last()` returns the last error of *any* severity, and the handler recorded whatever it found. On a live site the log filled with `ini_set()` warnings from `wp-config.php`, an "undefined property" notice, and a deprecation from a bundled library. None of those ends a request. With auto-pause switched on, **Elementor Pro would have been deactivated over a notice** — the outage the feature exists to prevent, caused by the feature. The handler now records only the five types WordPress itself treats as fatal in `WP_Fatal_Error_Handler::detect_error()`.

- **Fifteen distinct vulnerabilities were collapsed into one row.** Findings are grouped by id and label, and every vulnerability shares an id — so JetEngine's fifteen separate CVEs became a single row reading "×15" that showed only the first one, as though it were the same issue repeated. The CVE is now part of the grouping key: grouping should merge what is identical, not what is merely similar.

- **Vulnerability findings showed no detail in the report table.** The location resolver understood file paths and plugin names but not a vulnerability's value, so the rows carried no version, no fix and no score. They now read `slug installed → fix   CVSS n   CVE-…`.

## [1.7.3]

### Added

- **A "Refresh vulnerability feed now" button.** Enabling the module and pasting an API key scheduled the first download two hours out and offered no way to ask for it sooner, so the thing you enabled the module to see stayed empty with nothing to click. An oversight in 1.7.0, and the one that mattered most, because it sat directly between the setup and the payoff. The button reports what happened either way — a 429 from the free quota is expected rather than exceptional, and it leaves the stored data untouched.

## [1.7.2]

> A scan reported `KarMCP security scan failed`, and the whole report was gone. What actually broke was one check out of five — and the two defects that turned that into a total loss are both mine.

### Fixed

- **One failing audit discarded the entire scan.** The malware, integrity and hardening checks had all completed; something threw inside the software audit and every one of those results was thrown away to store the single word "failed". Each audit now runs inside its own guard: the ones that finish are kept, and the one that broke is reported as a warning in its own category. A check that produced nothing because it crashed is not a check that came back clean, so it costs score rather than passing quietly.

- **The failure named nothing you could act on.** The stored reason was `Attempt to assign property "plugin" on false` and no more — no exception class, no file, no line. That message appears nowhere in this plugin, and the code that threw it is reached indirectly, so there was no way to find it. This is precisely the dead end 1.2.1 removed from the tools, reintroduced here in the monitor. Failures now name the class, the file relative to the WordPress root, and the line.

  For the record, that particular error is characteristic of a third-party plugin's update checker assigning to a property of `get_site_transient( 'update_plugins' )` without checking it is not `false` — reached through the scan rather than caused by it. With this release the scan will say which plugin and which line.

## [1.7.1]

> The first real scan of the new Security tab reported 144 critical findings, of which **none were real**, and scored the site 0 out of 100. Everything here comes from that one run. None of it could have been caught by a unit test: it took a site with a hundred snippets and thirty outdated plugins to show it.

### Fixed

- **142 of the 144 criticals were Code Snippets doing its job.** That plugin stores each snippet as a PHP file under `wp-content/uploads/code-snippets/`, and the malware audit flagged every one as "executable PHP under uploads". WP Staging's cache made the 143rd. PHP under uploads from a plugin that is known to put it there is now collected and reported **once, as information**, with a note that blocking PHP execution in uploads would break it. Anything in a directory nobody recognises is still critical, and every one of these files still goes through the pattern scan — so a webshell dropped *into* one of those directories is still found. The exemption matches the directory, never the filename, so it cannot be claimed by naming a file `2283.php` somewhere else.

- **The report never said which file it meant.** The path lives in the finding's `value`, and the screen rendered the label, the message and the recommendation — but not that. The result was 142 rows of identical text with nothing to act on. Findings now show where, whatever shape the audit's value takes.

- **Repeated findings are grouped** with a count instead of printed one per row.

- **The score could not distinguish "needs updating" from "compromised".** Critical penalties were capped per category; warnings were not, so 46 ordinary warnings cost 230 points and floored the score at zero before anything else was weighed. A site with thirty outdated plugins scored exactly what a site with modified core files scored. Warnings are now capped per category as well, so a maintenance backlog reads as a maintenance backlog.

- **A warning was charged to the wrong category.** The category was resolved inside the critical branch only, so every warning inherited whichever category the previous critical had. Introduced and caught while adding the cap above; now read for both.

## [1.7.0]

> The last two pieces, and the ones that finish the job Wordfence was doing here.

### Added

- **Known Vulnerabilities: a fifth category in the security report.** Every installed plugin and theme is checked against the Wordfence Intelligence database — CVE, CVSS score, severity, and the version that fixes it. A new module, **off by default**, because it is the one part of KarMCP that fetches from a third party.

  **Nothing about this site is ever sent anywhere.** Not by policy — by construction. The endpoint serves the complete feed and accepts no parameters, so there is no per-plugin query to leak an inventory through even if one wanted to.

- **The feed is streamed, never loaded.** Measured against the live API: 11.2 MB gzipped is **150.87 MB decoded**, 38,701 records. `json_decode()` on that wants about a gigabyte, which no ordinary host has. So a hand-written splitter walks the stream counting braces and decodes one record at a time — verified end to end against the real feed at a peak of **18 MB under a 128 MB limit**, producing exactly the 38,701 records a full decode finds.

  The splitter is hand-written for one reason: a `{` inside a string is not a delimiter, and a `"` after a backslash does not end a string. A naive counter breaks on the first vulnerability whose description contains a brace, and breaks silently. Both cases have a test.

- **Rows are keyed by `(type, slug)`, not slug.** Nineteen slugs in the real feed exist as both a theme and a plugin — `canvas`, `automotive`, `cardealer` — and conflating them would attribute a theme's vulnerability to an unrelated plugin.

- **The version matcher, tested against the cases that actually occur.** Ranges carry explicit inclusivity on each end, so `to_inclusive: false` on 3.8.9.1 means that exact version is already patched; `*` means unbounded, and treating it literally would make every comparison fail and report a clean site; four-component versions like `3.5.6.1` are routine; and `1.0.0-beta2` sorts below `1.0.0`. Every one of those is a way to be silently wrong in the reassuring direction, which is the only direction that matters here.

- **The Package Guard exception, as decided.** Elementor and Elementor Pro are normally unupdatable over MCP. That now lifts when a known vulnerability affects the installed version **and** the update on offer genuinely leaves the affected range — both halves required, because updating to something still vulnerable fixes nothing and would have spent the exception for nothing. It is wired as a filter so the guard, which is core plumbing, never depends on a module that ships disabled. KarMCP still never updates itself.

- **Attribution, because the licence requires it.** Defiant grants a perpetual, irrevocable licence to reproduce these records on condition that a link to the record and their copyright notice travel with any copy. Every finding carries both, and the Security tab prints them. That is a term of use, not a courtesy.

- **`list-vulnerabilities`.** Worst CVSS first, each with the fixing version, whether an update is available, and whether that update actually clears *this* vulnerability. A feed that was never downloaded reports itself as unknown rather than returning an empty list — an empty list reads as "you are clean", and that is the one thing it must not say when it does not know.

## [1.6.0]

> Pieces three and four, and they belong together: the second is only reasonable to offer because the first exists to catch the fall.

### Added

- **A fatal-error handler that brings the site back on its own.** WordPress loads `wp-content/fatal-error-handler.php` in place of its own when the file exists, which means our code runs *at the moment of the crash*. That is the only place it can help, because once PHP has died the REST API is gone and MCP with it — the agent cannot reach a broken site at exactly the moment it is most wanted.

  Worth correcting a common assumption: **Recovery Mode does not fix the site.** WordPress emails a link and pauses the extension for that one recovery session; every visitor keeps seeing the error. Hence this.

  The handler records each fatal — message, file, line, and which plugin owns the file — and can deactivate the offender so the next request succeeds. Deactivation needs **three fatals in ten minutes**, never the first: one transient error must not be able to take a shop offline in a different way than the bug would have. It is off until switched on, honours a protected list, and KarMCP can never deactivate itself.

  It runs on shutdown with memory possibly exhausted and WordPress half-loaded, so it carries **no autoloader, no plugin classes and no dependencies**, and wraps its own work in a catch that swallows everything: a handler that becomes the failure leaves the site worse off than no handler at all. It is generated from a template and **the test suite compiles it** — a parse error there is a fatal on every request with nothing in the output to say why, and reading is not a reliable way to catch that.

- **`get-fatal-log`, `list-paused-plugins` and `resume-plugin`.** How an agent finds out what happened once it can connect again — which it can, precisely because the site came back. Resuming requires `confirm:true`, since reactivating an unfixed plugin simply crashes the site again.

- **`update-core`.** WordPress core updates were detected and never applied. This is the most consequential write in the plugin: it replaces the code serving the very request that asked for it, so a failure takes the site down rather than returning an error. It ships disabled, requires `confirm:true`, checks the filesystem is writable first, and reports whether the fatal-error handler is installed — because that is what makes this offer reasonable rather than reckless.

## [1.5.0]

> Two pieces of the security roadmap, shipped together because neither is much use alone: the screen without the fixes is a list of complaints, and the fixes without the screen are a tool nobody finds.

### Added

- **A Security tab, at last.** The four audits have existed since 3.0.0 — malware heuristics, core-file integrity against wordpress.org checksums, configuration hardening, outdated and abandoned software — and **none of it has ever been visible inside WordPress**. `scan-security` returned its report to an agent and nowhere else. Now there is a screen: score out of 100, letter grade, what is critical, and how old the answer is.

- **A scan that runs whether or not anyone asks.** Once a day by WP-Cron, plus a *Scan now* button. This is the half that made the tab necessary rather than nice: a scanner that only runs when someone asks is not monitoring. A vulnerability lands on a Tuesday, and if nobody opens a session that week, nobody finds out. An admin notice fires on a critical finding — and, just as importantly, on a scan that could not finish.

- **The rule the whole screen is built around.** A scan that never ran, failed, or is more than two days old is shown as exactly that, never as a clean bill of health. Freshness is a four-state answer rather than a boolean, and a failed scan deliberately discards the previous report's data so it cannot masquerade as yesterday's good news. Confusing *"I don't know"* with *"nothing found"* is the failure that leaves somebody compromised and reassured.

- **`harden-site`: the audit finally does something.** The hardening checks have always known what was wrong and only ever said so. This applies four of them — disable the dashboard file editor, disable XML-RPC, stop disclosing the WordPress version, send the missing security headers — dry-run by default like `build-site`, and recorded in the change ledger.

  Each fix is a switch this plugin owns, **not an edit to `wp-config.php`**: the filesystem guard refuses to read that file, let alone write it, and that is not an oversight to route around. Which means every one of them is reversible from a checkbox, and `DISALLOW_FILE_EDIT` becomes a constant defined early on each request rather than a line someone has to remember they added.

  Three findings are **never** fixed automatically and the plan says which and why: `WP_DEBUG_DISPLAY` is read before any plugin loads, so no plugin can change it; renaming the `admin` account breaks whatever authenticates as it; and HTTPS needs a certificate on the server. An unfixable finding that quietly vanished from the output would be the same lie as calling it fixed.

  Two details worth naming. The version fix **substitutes a hash for `?ver=`** rather than stripping it — the usual advice removes the query argument outright, which also removes the cache busting and leaves visitors on stale CSS after an upgrade. And **no Content-Security-Policy is generated**: a CSP a machine guessed breaks page builders and embeds, and one loosened until the site worked again protects nothing while looking like it does.

## [1.4.0]

> The first piece of the security section, and the one that had to come first: the site it protects had just had Wordfence removed. Of everything that goes away with Wordfence, brute-force protection is the only half worth rebuilding here — it needs no threat intelligence and no maintained rule set, just counting. The firewall stays out on purpose; that belongs in front of PHP, not inside a plugin that boots after WordPress has already started.

### Added

- **Login Guard: brute-force protection that cannot lock you out forever.** A new module (KarMCP → Modules, **off by default**) that counts failed sign-ins and blocks with an escalating delay — 15 minutes, then 30, then an hour, doubling to a hard ceiling of 24 hours. Never permanent: a guard that can permanently lock out its owner gets uninstalled the same afternoon, and then the site has nothing at all.

  Failures are counted **per address and per username, separately**. Only by address and a distributed attack on one account walks straight through; only by username and a single address can sweep the entire user list unhindered.

  The decision half is a pure class with no options, no superglobals and no clock — it is handed the failures and the time and returns a verdict — so all of it is tested without WordPress, the same arrangement as the Guardrails policy. Every setting is clamped on the way in, because a typo in the form must not be able to mean "everyone is locked out forever".

- **The reverse-proxy trap, handled explicitly.** Behind Cloudflare `REMOTE_ADDR` is Cloudflare, so every visitor shares one address and the fifth failure by anybody locks out the world. The forwarded header fixes that and is forgeable by anyone, which would let an attacker mint a fresh identity per request and never be locked at all. So the guard uses `REMOTE_ADDR` by default and reads the header **only** when the request arrives from a proxy address the administrator has declared. Both halves of that trade have a test, because getting either one wrong produces a module that looks like it is working.

- **The reconnaissance that comes before the attack.** Optionally blocks anonymous user enumeration — `/wp-json/wp/v2/users` hands a stranger every username on the site, and `?author=N` leaks one in the redirect — and drops XML-RPC's `system.multicall`, which batches hundreds of password guesses into a single request and is most of why XML-RPC is worth attacking at all. Both are on when the module is, and both are switchable, since disabling XML-RPC breaks Jetpack and the mobile app.

- **`list-login-lockouts` and `clear-login-lockout`.** The Security screen that will own this is a later piece, but a lockout with no visible way out is how an administrator ends up switching off the whole module. Clearing a lockout also forgets its escalation history, so an unblocked user starts from the base delay rather than a doubled one. The read is enabled by default; the write opts in like every other one.

- **The MCP server is exempt from the count.** OAuth already rate-limits its own registration endpoint, and a client retrying a token exchange would otherwise lock out the very agent the plugin exists to serve.

### Infrastructure

- **Continuous integration, at last.** The 544 tests now run on every push and pull request across PHP 8.1 to 8.4, and that check is blocking. This is the compensating control for a private repository: nobody outside will ever find a bug here, so the automation is the only reviewer left — and 1.3.0 proved the point, shipping two defects that survived a careful review and thirteen tests, and surfaced only when the tool was run against a real site.
- **PHPCS and PHPStan**, deliberately narrow to start: security, correctness and PHP-version compatibility, but not formatting, and PHPStan at level 1. Both are non-blocking until the first run has been triaged and baselined, because a permanently red check is a check everyone learns to ignore. The ratchet is documented in CONTRIBUTING.md. None of it ships: the whole toolchain is `export-ignore`d, and the release zip is byte-for-byte the same shape as before.

## [1.3.1]

> Both of these were found the same way: by running 1.3.0 against a real site instead of only against the test suite. Neither could have been caught by a unit test, because both are about what WordPress does around the code, not what the code does.

### Fixed

- **`upload-media` answered a bare "Permission denied" for a `post_id` that did not exist.** The permission callback asked `edit_post` on the parent before checking the parent was there — and `map_meta_cap()` resolves `edit_post` against a missing post to `do_not_allow`. So a mistyped id came back as a permission problem, which is the wrong diagnosis and the wrong fix for whoever reads it; worse, it made the executor's own `post_not_found` branch unreachable, so the good message could never be reached. Existence is now resolved first, and the callback returns a `WP_Error` naming the cause in all three cases — no `upload_files`, no such post, or no rights on that post (with its post type, since `edit_post` is a meta capability a capability manager can revoke per type). This is the same bare-`false` seam 1.2.1 closed on `update-post` and `delete-post`; the new tool had reopened it.

- **A file whose contents did not match its extension was refused in a language the caller may not read.** Uploading PHP named `.jpg` was correctly refused — by WordPress, deep inside `media_handle_sideload()`, with core's translated string: on a Spanish site, *"Lo siento, no tienes permisos para subir este tipo de archivo."* That says the caller lacks a permission, which is not what happened, and it is locale-dependent, so no client can reason about it. The contents are now verified before the sideload and refused as `content_type_mismatch`, in this plugin's own words, naming the file and the extension it contradicts. WordPress still runs its own check afterwards — that one is the guarantee, this one is the explanation.

- **Every upload failure suggested `convert_webp:false`.** The hint was appended unconditionally, so a rejected file type was answered with "retry with convert_webp:false", a flag that could not possibly change the outcome — an invitation to a retry loop. Now that type mismatches are caught earlier, what reaches that message really is a move or processing failure, and the hint is worded as the conditional it always was.

## [1.3.0]

> One tool. The library had four ways to work with a file that was already on the server and none to put one there from the machine the person is sitting at.

### Added

- **`upload-media`: put a local file into the Media Library.** `sideload-image` fetches a URL *the server* can already reach, which covers stock photos and anything public and covers nothing else — so the one thing an agent could not do was upload the photo the user has on their own disk. This tool takes the bytes as base64 and hands them to `media_handle_sideload()`, the same path every other upload takes, so the type allowlist, the SVG sanitizer and the Image Optimization module's compress/WebP pass all apply unchanged (`convert_webp:false` skips the last one, as on its sibling). Optional `alt`, `title`, `caption`, `description`, and `post_id` to attach it to a page.

  Three things it refuses before writing anything, each because the alternative is worse than an error:

  - **A type this site does not accept**, checked from the extension against `get_allowed_mime_types()` *before* decoding. That resolves per user and per site, so the SVG Support module widening the list is honoured, and so is the narrowing core applies to a user without `unfiltered_html`. The authoritative check still happens inside `media_handle_sideload()`, which reads the content and rejects a `.jpg` holding PHP; this one only avoids decoding megabytes to learn what the filename already said.
  - **A payload over the site's upload limit**, estimated from the *encoded* length — base64 costs four bytes per three, so refusing early is what keeps an oversized upload from being a memory fatal instead of a message. The limit is named in the error so an agent resizes rather than retrying the same bytes.
  - **Attaching to a post the caller cannot edit.** `upload_files` says a user may add files; it does not say whose pages they may hang them off. When `post_id` is present the permission callback also requires `edit_post` on it.

  Enabled by default, like `sideload-image`: it writes one attachment, gated on the capability WordPress itself gates uploads on. The upload is recorded in the change ledger, so it is reversible from **KarMCP → Changes**.

## [1.2.1]

> Three failures, two causes, and one thing in common: the error said nothing you could act on. Nothing here changes what the plugin does — only what it tells you when it cannot do it.

### Fixed

- **A tool that threw reported nothing you could act on.** WordPress core catches whatever an ability callback throws and keeps only `getMessage()`. So when Elementor refused to save the kit with a bare `Access denied.`, that literal was the entire report — and it appears nowhere in this plugin, so the one clue pointed away from the code. Anything escaping a tool now comes back as an error naming the exception class, the file and the line, with the same facts in the error data. Paths are relative to the WordPress root, which is both what `read-file` accepts and one less thing disclosed to the client. `\Throwable` is caught, not just `\Exception`: a `TypeError` was equally opaque and returned an empty 500 rather than a tool error.
- **`update-global-colors` and `update-global-typography` checked the wrong capability.** Both gated on `manage_options`, but saving kit settings runs through Elementor's `ajax_before_save_settings()`, which throws unless the caller passes `edit_post` **on the kit post**. The two are unrelated: `edit_post` is a meta capability resolved against the kit's post type, so a capability manager that puts `elementor_library` under type-specific capabilities revokes it for every role — administrators included — while `manage_options` sits there untouched and the generic `edit_others_posts` still passes. Both tools now check what Elementor enforces, before doing any work, and name the capability, the kit post and its type.
- **A per-post permission denial said only "Permission denied".** The permission callbacks behind `update-post` and `delete-post` returned a bare `false`, which the adapter renders with no tool, no post and no capability attached — the same dead end as above, one layer up, and reached by the same missing `edit_post`. They now return an error naming the capability, the post and its post type, and distinguish lacking the capability in general from lacking it on that one post.

## [1.2.0]

> An audit release. No new tools: what changed is who is allowed to use the ones that exist, and what the plugin leaves behind when it goes.

### Security

- **`create-post` checked the wrong capability, and `wp_insert_post()` checks none.** The tool asked for the generic `edit_posts` and then wrote whatever post type it was handed — so anyone who could write a blog post could also create a post of *any* registered type. That includes the types this plugin registers for itself behind `manage_options`: an Agent Skill, which is an instruction file every connected agent reads, and a PHP snippet, whose whole point is that the code inside it is validated before it is stored. Both were reachable by an Editor with an MCP connection, and neither dedicated guard ran. The tool now resolves the capability against the target post type — `edit_products` for a WooCommerce product, `manage_options` for a skill — for creating, for publishing, and for assigning another author, and `update-post` does the same. The plugin's own post types are refused outright: they each have a tool of their own that runs the check the generic path cannot.
- **`get-post` returned any post to anyone who could edit any post.** The tool's gate says the caller writes content somewhere; it never said they could read *that row*. A bare ID was enough to pull back another author's private draft, or the body of one of the plugin's own private types — a snippet's PHP, a skill's text. It now applies the per-post `read_post` capability, which WordPress resolves against the post's type, status and author.
- **`list-posts` would enumerate a type you cannot read.** The same seam one level up: name a post type explicitly and you got back its titles, slugs and authors, whatever your role — including the plugin's own private types. The requested types are now filtered to the ones the caller has a capability for, and the query asks WordPress for readable posts only, so `status: "private"` can no longer be used to read past another author.
- **A download could be redirected to the cloud metadata service.** `safe_download()` validated the URL you gave it, then relied on WordPress's `reject_unsafe_urls` for the redirects — and the core check behind that flag permits the link-local `169.254.0.0/16` range, where AWS/GCP/Azure serve instance credentials at `169.254.169.254`, and does not look at IPv6 at all. So a public image URL that answered with a `302` was followed there. Every hop is now re-validated with the plugin's own address table, which covers link-local, CGNAT, unique-local IPv6 and IPv4-mapped forms like `::ffff:169.254.169.254`. The first URL is checked more thoroughly too: it reads AAAA records as well as A, so an IPv6-only internal host is no longer invisible, and a host publishing one public and one internal address is refused.
- **OAuth client registration had no ceiling.** `/register` is unauthenticated by design — an MCP client self-registers before anyone has logged in — but every call wrote a row, so one script could grow the table without limit. Registrations are now capped per address per hour, counted in fixed time buckets so a steady stream cannot both hold a counter open forever and lock a legitimate client out. The address is hashed rather than stored.
- **The granted OAuth scope was whatever the client asked for.** The request value was stored and echoed back as the scope that had been *granted*, so a client that asked for `mcp admin:everything` was told it had it. Nothing reads that column today, which is the only reason this was a truthfulness bug and not an open door — but it is what a scope check would have read the day one was added. Requests are now reduced to the scopes this server actually supports.

### Fixed

- **Uninstalling left almost everything behind.** The cleanup deleted seven options out of roughly thirty and dropped none of the five tables the plugin creates — including the OAuth pair, so a site kept its registered clients and its live access and refresh tokens after the plugin that understood them was gone. Options and transients are now swept by prefix rather than from a hand-written list that had already drifted, user meta with them, and the tables are dropped. On a network install the cleanup runs once per site, since each site has its own. Content a person wrote — brand-kit backups, Themer templates, Skills — is still deliberately kept; generated executable PHP is still deliberately destroyed.
- **The plugin could not be translated at all.** Around three thousand translatable strings and no `load_plugin_textdomain()` call anywhere. The automatic loading WordPress added in 4.6 only covers translations it downloads for plugins hosted on wordpress.org, and this one is installed by hand, so a `.mo` file placed in `languages/` could never load. It is now loaded on `init`.
- **The `query` tool refused `REPLACE()`.** The read-only guard denylisted the word, which is both a write statement (`REPLACE INTO …`) and an ordinary string function (`SELECT REPLACE(post_title, 'a', 'b')`). Legitimate analysis queries came back as "unsafe keyword". Only the statement form is blocked now — it is never the one followed by an opening parenthesis — and the write form remains refused with or without its optional `INTO`.
- **Removed the dead Project Memory code.** Three AJAX endpoints, a tab route and a section of the Tools tab for a feature whose implementation is not part of this build. The toggles could never do anything, and the endpoints were registered hooks with nothing behind them.

## [1.1.0]

> Six additions aimed at one thing: building a whole site, not just a page. The first of them is the one the rest depend on. Two features documented under 1.0.0 also arrive here — see the note further down.

- **`render-page`: the agent can finally see its own work.** Every other read tool describes a page as the builder stores it — which is the data the agent just wrote, so it can only ever agree with itself. This one renders the page the way a visitor gets it and returns a digest of the *output*: heading outline, links, images, forms, landmarks, visible text, and warnings for the things that go wrong without leaving a trace in the builder data — containers that render completely empty, images with no alt attribute, links still pointing at `#`, shortcodes that were never resolved, duplicate DOM ids, forms with no submit button. `scope: "content"` works on drafts; `scope: "full"` fetches the whole themed page over a loopback request. It is not a pixel diff and will not catch a layout that wraps badly, but most of what a machine-built page gets wrong is structural. The normalized view underneath it (`KarMCP_Content_Extractor`) is the shared foundation the SEO and accessibility audits were always going to need.
- **Forms you can actually create.** Contact Form 7 was read-only: you could inspect a form and edit its mail template, but not make one. `cf7-write` now takes `create-form` and `update-form` with a plain field list and generates the form body, plus a mail template that reports every field — CF7's stock template lists its own demo field names, so a generated form shipped with it emails you a page of unresolved placeholders. `cf7-read` gains `list-entries`, which reads submissions from Flamingo and, when Flamingo is absent, says so plainly instead of returning an empty list that reads as "nobody has written to you". For Elementor Pro there is a new `add-contact-form`: same plain field list, correct repeater rows. Building that widget by hand has two traps that produce a form which looks perfect in the editor and delivers nothing — `required` is the string `"yes"`, and `custom_id` is what names the submitted value.
- **WooCommerce products.** The integration was declared and never built. `woo-read` and `woo-write` now cover the product catalog: list, get, create, update, categories and tags (inventing the ones that do not exist yet), and a confirm-gated delete, plus `get-store-setup` for the currency, base country, shop pages and stock settings you need before building shop pages. Prices are accepted the way a human writes them — `19,90`, `1.299,00`, `€19.90` — because WooCommerce stores what it is given and later reads `19,90` as `19`. A sale price above the regular price is refused rather than stored, since WooCommerce would show a sale badge and charge full price. Orders, refunds and customers are deliberately not exposed: that is the money and personal-data surface, and building a site never needs it.
- **Multilingual sites: Polylang and WPML.** `list-languages`, `get-translation-status` (which languages a page exists in and which are missing), `create-translation`, `set-post-language` and `link-translations`. Creating a translation duplicates the page with its layout, assigns the language, and links it into the *existing* translation group — the failure this replaces is a copy that was never linked, which looks finished and is invisible to the language switcher. A language the site has not configured is refused, because both plugins accept one and leave the post reachable from nowhere.
- **`duplicate-post`.** Copies a post with its meta and its terms. This closes a real gap: `create-post` refuses to write protected meta, correctly, but plugin post types (popups, listings, most builder types) keep their entire configuration there — so KarMCP could fill a container it could not create, and you got a `jet-popup` that was not a popup. Copying an existing one carries state a human already approved, so nothing about that guarantee is weakened. The featured image travels; the edit lock, the SKU and the cached Elementor stylesheet deliberately do not.
- **Structured data.** `get-post-schema` and `set-post-schema` attach validated Schema.org JSON-LD to a post — Organization, LocalBusiness, Product, FAQPage, BreadcrumbList, Article, Person, Service, Event. FAQs and breadcrumbs take a flat list and are expanded into the nested structure Google expects, since that nesting is where hand-written markup fails. Every node is validated before it is stored, because an invalid one is not reported by a search engine, it is ignored: the rich result never appears and the page looks identical either way. It also tells you when an SEO plugin is already emitting its own.
- **`build-site`.** The pages, the navigation menu pointing at them, the static front page, and the global palette and fonts, in one call and in the order that works. Dry-run by default: it returns the plan, and only writes when called again with `apply:true` and `confirm:true`. Idempotent by slug, so re-running a corrected brief adopts what exists instead of leaving a second copy of everything. Administrator-only, and disabled by default.
- **New tools ship disabled by default** where they write beyond a single page: `duplicate-post`, `build-site`, and the two multilingual write dispatchers. Turn them on under **KarMCP → Tools**.

> The three entries below were written under 1.0.0 but did not make that build — the code was still uncommitted when it was tagged. They ship here.

- **Agent Skills, now real.** Skills are short operating manuals you write once — how you build a landing page, the tone your copy uses, what to check before publishing — and every connected agent reads. Write them under **KarMCP → Skills** (an ordinary editor, so revisions and drafts come free; publish to make one live, keep it a draft to hide it). Agents get an *index* of names and summaries in their discovery context and fetch a full body on demand with the new `list-skills` / `get-skill` tools. That split is deliberate: shipping every body on every connection would tax conversations that never need them. Editing is administrators-only, since a skill steers behaviour across the whole site, and the tools are read-only by design — an agent that could rewrite its own instructions is a governance hole, not a feature. The previous build shipped a module card for this that could never turn on, because the implementation was not part of it.
- **Guardrails: site rules an agent cannot argue its way around.** A new opt-in module (KarMCP → Modules) that sits above the capability checks — those decide what a user *may* do; this decides what this *site* allows regardless. Read-only mode, blocking every tool flagged destructive, a recurring freeze window for business hours (overnight windows included), protected post IDs, protected content types, and a free-text house-rules block. The same policy drives two things at once: it is enforced on every write, and it is published into the agent's discovery context — so a connected agent reads "writes are frozen 09:00–19:00, Mon–Fri" while it is still planning, instead of finding out through a refused call. Enforcement survives compact/dispatcher mode, because `call-tool` runs its target through the same wrapped callback and the policy therefore sees the real tool name and the real arguments, not the dispatcher envelope. Off by default: an unconfigured policy changes nothing.
- **Fixed: module cards offered a licence that does not exist.** Anything unavailable read "requires an active Pro license" — but this build has no licensing of any kind, so the message sold something nobody could buy. It now says the feature is not included in this version.

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
- **Removed the marketplace entirely.** It advertised a storefront this build does not ship: a tab to browse and install listings, a dashboard panel offering to sync your artifacts across sites and sell them, "Publish to Marketplace" / "View on Marketplace" / "Push update" buttons in the Sandbox with their review-status badges, and two `cloud-marketplace-*` MCP tools that offered a connected agent a store it could never reach. All gone, along with the now-unreachable code behind them. What remains in the Sandbox is a plain **Save to Cloud** backup button, which stays dormant unless you point the plugin at a service of your own.

### Security

- **The database write tools now validate column names against the table's real columns.** WordPress parameterizes the values you pass to `insert-row` / `update-rows` / `delete-rows`, but interpolates the *column names* into the SQL without escaping — so a crafted key could close the identifier, append its own SQL, and read tables the tools are supposed to refuse (including password hashes). Unknown column names are now rejected outright, which also turns a silent typo into a clear error.
- **WP-CLI can no longer read `wp-config.php`.** `config get` and `config list` printed the database password and every security salt. Those salts are what the plugin derives its encryption key from, so leaking them exposed every stored secret — and the filesystem tools already refused to read that file for the same reason. `config get`, `config list`, `config path` and `config has` are now refused alongside the writes.
- **Closed four more WP-CLI routes to a site takeover:** the top-level `search-replace` (rewrites every table in place, bypassing the protected-table list), installing a plugin or theme from a URL or `.zip` (runs whatever the archive contains — the dedicated install tool has always been wordpress.org-only), creating or promoting an administrator or setting a user's password, and writing the options that decide which code loads or who may register (`active_plugins`, `default_role`, `users_can_register`, and similar). Ordinary use is unaffected: installing by slug, creating a subscriber, and editing normal options all still work.
