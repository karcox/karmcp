# Changelog

All notable changes to KarMCP are documented in this file.

## [1.43.0]

WordPress can update this plugin now.

### Added

- **KarMCP updates itself from its own GitHub releases.** Until now every site was updated by hand, by uploading the ZIP, and nothing ever said a new version existed. The `Update URI` header names github.com, which is what makes WordPress ask the `update_plugins_github.com` filter instead of looking a slug up in a directory this plugin was never published to. `KarMCP_Updater` answers it from the repository's latest published release, and the update then appears on the Plugins screen and installs with the button already there.

  What it answers with is deliberately narrow, because the value it returns is the URL WordPress downloads and unpacks over the running plugin. The release has to be published rather than a draft or a pre-release, its tag has to read as a version number and be newer than the installed one, and the download has to be a `.zip` asset served over HTTPS by github.com or GitHub's asset host. Anything else — a tag that is a word, a release with no ZIP, a link that merely mentions github.com — is "no update", never a guess.

  The check runs on WordPress's own update schedule, and the answer is cached for six hours (and a failure for thirty minutes, so a site that cannot reach GitHub does not try again on every page load). Nothing is sent: the request carries the site URL as a user agent string and asks for a public release. Set the `KARMCP_NO_UPDATE_CHECK` constant, or return false from the `karmcp_update_check_enabled` filter, and no request is made at all.

  This needs the repository to be public, which is what makes a token unnecessary. A token shared across sites would sit in a readable file on every one of them, and revoking it would silently stop the updates everywhere.

### Fixed

- **The dashboard always claimed you were on the latest version.** It hard-coded the installed version as the latest one, which was true only in the sense that nothing could ever find a newer one. It now reads what WordPress already knows, so it says an update is available exactly when the Plugins screen does — without a request of its own.

## [1.42.1]

A retried undo could restore something twice.

### Fixed

- **Retrying an undo whose history write failed could duplicate rows.** 1.42.0 reports `history_not_updated` when an undo lands but the history cannot record it, and the obvious next step — undoing again — re-ran the restore. For deleted database rows that meant inserting every row a second time. Undoing deleted rows now re-inserts only the copies that are missing, counting identical rows so a table without a key that lost two equal rows gets two back. Rows are grouped byte for byte, so two different binary values are never mistaken for one.

- **The other restores are safe to repeat too.** A post already out of the trash is left alone, as long as WordPress's own trash bookkeeping shows it came out through an untrash; if its status was changed some other way, the undo says so instead of calling it restored. A deleted post or attachment that an earlier attempt already put back under its id is recognised as restored instead of refused — and "already put back" means every identifying field matches, title, slug, date, author, parent, MIME type and GUID, because drafts and attachments often have no slug and a batch creates many items in the same second. A match on less would skip a real restore and report success.

- **Undoing a redirect change checked nothing.** Restoring an updated redirect failed whenever the row already held its prior values, since zero rows changed; restoring a deleted one twice tried to insert it twice; undoing a creation twice failed. Each now checks the row afterwards and is safe to repeat, and an update whose row is gone is reported.

- The `history_not_updated` message now says what to do: retry with `force`, which is needed because the target no longer matches the recorded state — it was already restored.

## [1.42.0]

The change history could report an undo that had not happened. This release is about the gap between "rolled back" and "back where it started".

### Fixed

- **Undoing `create-page` left an empty page behind instead of removing it.** The page's initial content was saved through the same path as any later edit, so it recorded itself as "edited Elementor page" — and that was the only entry. Its undo restored the tree as it was before the save, which on a new page is empty. The initial save is now part of the creation: recording is paused for it, and the creation is recorded once, afterwards, with the content in place. `create-page` returns that entry's `change_id`, and so do `create-post`, `upload-media` and `build-page` when it creates a post. `build-page` had the same bug and the same fix. `build-site` recorded nothing at all for the pages it created; each one now has an entry, with its `change_id` in `pages.created`. If the initial save fails, the half-made page is deleted instead of being left unrecorded.

- **Undoing a creation deleted the post even if someone had built on it since.** A creation entry is now stamped with the state of the post when it was recorded — core fields, Elementor data, page settings, categories and other terms, the featured image, and for an attachment its alt text, metadata and the bytes of its file — and undo refuses with a `conflict` when that has changed. `force` still overrides. Dates are left out on purpose: WordPress keeps moving the date of an undated draft, and counting that as an edit would block every undo of a draft. Free-form post meta is left out too, because view counters and similar plugins write it on every visit. Creations recorded by earlier versions carry no stamp, so undoing one now needs `force`: the history cannot tell whether the post was edited, and here "cannot tell" has to mean "ask first".

- **Page settings, custom CSS included, had no history.** `update-page-settings`, page-level `add-custom-css` and `build-page`'s settings wrote `_elementor_page_settings` without recording anything, so the one write an agent makes to restyle a whole page was the one it could not undo. They are recorded now; a save that changed nothing records nothing. Undoing them also drops the page's generated CSS, which was built from the settings being undone.

- **An empty prior value was deleted on undo instead of written back.** Meta entries used an empty value to mean "the key did not exist", which is also a value a key can hold. New entries mark an absent key explicitly and restore everything else exactly. Entries written by earlier versions are read the old way, so their meaning does not change under them. Restored meta is also slashed now: the metadata API unslashes what it is given, and custom CSS lost its backslash escapes on the way back.

- **Undo trusted every write it made.** A delete WordPress declined, a meta value or option that did not take, a trash that would not untrash, a user that was not deleted, a database row that no longer existed — each was marked rolled back. Options, post and term meta are now read back and compared; deletions are checked to have happened; database writes that fail or update a row that is gone are reported. A restore that did not take leaves the entry undoable instead of marking it done. What is still trusted once the write reports no error is said so in the `rollback-change` description: Elementor data, ACF fields, user profile fields, files, and database rows that matched.

- **Undoing an upload could leave files on disk.** Attachments are now deleted with `wp_delete_attachment()`, the files recorded at upload are checked afterwards, and any still present inside the uploads folder are removed. WordPress compares paths as strings before deleting a generated size, and a mix of separators on Windows is enough for it to skip them while reporting success. A file that cannot be removed is reported by name.

- **A deleted post or attachment could come back under a different id.** When its old id was taken, it was restored under a new one — and everything that pointed at it, an Elementor image widget for example, went on pointing at whatever now owned the old id. Restores now refuse when the id is in use, even with `force`, and one that WordPress lands under another id is removed again. An attachment whose deletion was recorded without a copy of its file, or whose saved copy is gone, is refused too, rather than restored as a broken image. Files are copied back before the post is inserted, and removed again if the insert fails.

- **A rollback switched recording back on for whatever called it.** It cleared the pause flag on the way out instead of restoring it, so a rollback inside an operation that was running unrecorded started recording that operation's remaining writes. It restores the caller's state now.

- **`list-changes` could not filter by most of what it lists.** Its `domain` filter accepted only `elementor`, `filesystem` and `database`, while the history also records content, Gutenberg, media, globals, settings, users, ACF, SEO, redirects and WP-CLI. All thirteen are accepted now.

- **`build-page` reported success when its page settings failed to save.** The error was discarded; it now comes back as a warning.

## [1.41.0]

A plugin or theme that is not on wordpress.org can now be installed over MCP, and the site's own URL stops carrying an `index.php` on sites without pretty permalinks.

### Added

- **`install-uploaded-zip` installs or updates a plugin or theme from a ZIP in the Media Library.** Until now the only installs came from wordpress.org, so a premium plugin had to be uploaded by hand in wp-admin. The flow is three steps: upload the ZIP with `upload-media`, pass its `attachment_id` together with the SHA-256 of the file you meant to upload, and `confirm: true`. It ships disabled, and it checks `install_plugins` or `install_themes`, plus `update_*` when it overwrites and `activate_plugins` when it activates — all before the file is touched.

  Nothing is extracted until the archive has been read entry by entry. It is refused when an entry would land outside the package folder, is a symbolic link, or has attributes that cannot be read to rule that out; when it holds more than one top-level folder or a file at the root; when it declares more than 20,000 entries or 200 MB uncompressed, or an entry expands more than 200 times its compressed size; and when the folder holds no plugin header or no `style.css` with a theme header. A ZIP made on a Mac carries a `__MACOSX/` folder beside the package, and that one is accepted — WordPress skips it on extraction — after it has passed the same path, link and size checks as everything else.

  The file is copied to a private temporary file first, and the hash, the inspection and the install all run on that copy, so nothing that replaces the upload after it has been checked can be what gets installed. The copy is deleted whether the install succeeds or is refused, and the ZIP leaves the Media Library after a successful install unless `keep_upload` is set.

  An existing package is replaced only with `overwrite: true`, and only by a package declaring the same name. That rule is a guard against replacing the wrong package by mistake; it is **not** a defence against a ZIP that lies about its name, which anyone building the file can do — the SHA-256 you supply is what vouches for the file. **KarMCP, Elementor and Elementor Pro cannot be replaced this way at all**, whatever name the ZIP declares, which is the rule `update-plugin` already follows: replacing the plugin that is serving the request, or the builder every page depends on, is not something a single MCP call should be able to do. Update those from wp-admin.

  When WordPress installs a plugin but does not report its main file, the response says it was not activated instead of reporting a clean install with `activate` silently ignored.

  This reverses a deliberate earlier decision not to accept archives over MCP, and it does so knowingly: the tool is off by default, and turning it on is the site owner's call.

### Fixed

- **On a site with plain or PATHINFO permalinks, the site's base URL kept `index.php`.** WordPress builds the REST root as `https://host/index.php?rest_route=/` when there is no permalink structure, and `https://host/index.php/wp-json/` under PATHINFO. The base was derived from that root and inherited the `index.php`, and everything built on the base inherited it too: the OAuth issuer, the advertised authorize URL, the Connection tab's default and the `WP_URL` baked into the downloadable bundle — so a client was sent to `https://host/index.php/karmcp-oauth/authorize`, which nothing serves. Only a trailing `index.php` segment is dropped now; a subdirectory install keeps its subdirectory.

  The test that covered this passed the whole time, because it fed `https://plain.test/?rest_route=`, a shape WordPress never emits. The new cases use the three shapes `get_rest_url()` really produces, with a subdirectory and a port, and five of them fail against the old code.

## [1.40.1]

The sidebar mark is white now, and the reason it was not is worth writing down.

### Fixed

- **The KarMCP icon in the WordPress sidebar showed as a near-invisible black K beside two grey dots.** WordPress colours a data-URI menu icon with `svg-painter.js`, which rewrites the SVG's `fill` to the admin colour scheme — and only its `fill` (`wp-admin/js/svg-painter.js`, the three `replace()` calls: `fill="…"`, `style="…"` and `fill:…;`). The KarMCP mark is a stroked shape. So the painter turned the two filled nodes grey and left the strokes of the K in the black they were drawn in, which on the default dark sidebar is a letter you can barely see. It had been that way since the mark shipped, and the docblock beside it said the opposite — that WordPress recolours the icon, so baking in a colour would be pointless — which is exactly why nobody went looking.

  The icon is now painted white by a CSS filter in the style block the admin already prints on every screen: `brightness(0) invert(1)` turns every painted pixel white whatever colour it arrived as, at rest and when active, so it no longer depends on what the painter does or skips. The one exception is the Light colour scheme, whose sidebar is pale grey and would swallow a white mark whole; there the icon is left dark, which reads. A test pins that exception, because it is a single conditional that looks like it could be simplified away and the only people it breaks for chose a light sidebar.

  The style block also carried two rules sizing a `.wp-menu-image img` that has not existed since the icon became a data URI — WordPress renders those as a background on a `div`, never an `<img>`. They are gone.

## [1.40.0]

Three gaps on the reading side, all of the same shape: the agent could write something and then had no way to look at the result.

### Added

- **`get-element-settings` returns `styles` and `editor_settings`.** On a v4 atomic element half the state lives outside `settings` — the local style classes and the editor's metadata are siblings of it at the element root — and the write tools have routed both since 1.38.0 while no read tool showed either. The only way to confirm an atomic write was `export-page` and its raw tree. A write that reports success and a read that cannot show the result is the same blind spot from the other end. Both keys are omitted when empty, because the factory seeds them as empty arrays on every atomic element and returning them regardless would make "has none" indistinguishable from "has an empty one".

- **`regenerate-css` throws away Elementor's generated CSS so it rebuilds from current data.** For the case where a page reads back correctly and still renders with the old styling: after writing global classes or global typography, after an edit made outside this plugin, or when a cached stylesheet outlived the data it described. `scope: "page"` clears one post's stylesheet, its rendered-element cache and its CSS file; `scope: "site"` does it for every post, and takes administrator rights and `confirm: true` because the rebuild is lazy and the next visitor to each page pays for it. Nothing it removes is anything but derived, so it is annotated non-destructive, and it ships disabled.

  It also says what it cannot reach. Spectra in separate-file mode writes its own CSS on its own schedule — the admin has warned about that combination for a while, and the response now repeats the warning at the moment it matters, rather than reporting a clean success over a page that will still render stale.

- **`render-page` can render a URL, and the front page.** It took a `post_id` and nothing else, so the front page — a query rather than a post on most sites — was unreachable, and so was every archive, search result and paginated page. Pass `url` instead, or omit both for the front page. Same-origin only: a tool that fetches any URL an agent names and returns the body is a server-side request forgery with a good description, and refusing to leave the site is the one guard that cannot be argued around. The post-id path keeps its `edit_post` check, since it can render a draft; the URL path needs none, because the loopback carries no session and sees what an anonymous visitor sees.

- **`include_html` can be resumed past its 200 KB cap.** A themed page routinely runs past it, and the response used to stop there with nothing saying so — the agent read a document whose closing markup it had never seen and reasoned from the absence. The slice now comes with `html_chunk`: where it stopped, where to resume, the document's full size, and a checksum of the whole thing so a continuation against a page that changed in between is detectable rather than silently spliced.

### Fixed

- **The HTML truncation ate one good byte on every clean cut.** Walking back off a partial UTF-8 sequence is right; doing it unconditionally is not. The old code stripped trailing continuation bytes and then dropped one more byte regardless, so a cut that had landed on a character boundary — which on ASCII markup is every cut — lost its last character. Now the lead byte is read and the character is kept when all of it made the cut.

## [1.39.1]

Removes the last traces of a feature this plugin does not have, and puts a test behind the half of the release ritual that never had one.

### Fixed

- **The admin advertised an AI Chat that does not exist here.** The dashboard's Modules card read "Turn big features on and off: AI Chat, Themer, Image Optimization, Redirects and more" — AI Chat first, and there is no such module: the eight are Themer, Redirects, Agent Skills, Image Optimization, SVG Support, Guardrails, Login Guard and Known Vulnerabilities. The Image Optimization module and its WebP setting named it too. All three now describe what is actually there.

  This is the same drift CLAUDE.md's "Lo que NO existe en este árbol" section exists to catch — leftovers of a tier separation that was removed — with one difference that made it worth a release of its own: these were not guards resolving quietly to false, they were sentences a person reads in wp-admin.

- **Three admin page slugs resolved to screens that cannot be reached.** `get_active_tab()` mapped `-ai-chat`, `-migrate` and `-skills` to tab ids, and none of the three is registered as a submenu — `get_submenus()` is the complete list and only drops `-redirects` conditionally, while `tab_icon()` carries an icon for exactly the registered set. Unreachable rather than broken, but it is three lines claiming a screen exists. The docblock said the function returns "one of 'tools', 'connection', 'context', 'changelog'", which was never the full set either.

- **A docblock sent the reader to endpoints that are not in this plugin.** `get_active_ability_names()` said it was used by "the AI Chat /execute-ability and /abilities endpoints". The method is very much alive — the dispatcher mode's `list-tools` and the admin bar both call it — so the code was right and only its explanation was wrong, which is the kind that costs a search before it costs anything else.

### Added

- **`readme.txt` now has to carry a changelog entry for the version it declares, and every heading in it has to be one WordPress can parse.** The release ritual writes each change up twice — in full in `CHANGELOG.md`, summarised in `readme.txt` — and only the first half had a test behind it. The second is the half a person reads, since WordPress renders it on the plugin screen, so a missing entry shows the previous release's summary under the new version number. A heading that is subtly off (`=1.2.3=`, `== 1.2.3 ==`, a stray trailing space) is not rendered as a heading at all, and its release silently merges into the one above while looking correct in the file. Both are now `VersionTripleTest`'s problem, alongside the version triple it already guarded.

## [1.39.0]

Closes the write path that let one mistyped key destroy an element's styles and report success, and says out loud what the previous release's dimension advisory can get wrong.

### Fixed

- **A `styles` or `editor_settings` that was not an object replaced the whole map, and the call returned success.** Both are always objects — the factory seeds them as empty arrays and Elementor reads them as arrays — but the hoisting that moves them from the payload to the element root assigned any non-array straight onto it. So `{"styles": "oops"}` did not fail, and did not partially apply: it replaced every local style class on that element with the string `oops`, wrote it to the document, and answered `success: true`. The `settings` input is declared as a plain object with no sub-schema, which is correct — the keys are Elementor's, not ours — so nothing upstream could have caught the type either.

  A malformed value is now dropped before anything reads it, the stored map is left exactly as it was, and the key comes back in a new `rejected_keys` field alongside the type that arrived. Dropped rather than refused outright, because refusing abandons a payload whose other twenty keys are fine and, in a batch, leaves the page half written; reported rather than dropped quietly, because a drop nobody is told about is the failure this whole family of advisories exists to prevent. `update-element`, `update-container` and `update-widget` carry the field; `batch-update` reports it per element.

  There is deliberately no way to clear one of these maps this way. `null` would be the obvious spelling and no caller uses it, so it stays a mistake rather than becoming a deletion idiom that the one shape meaning "wrong type" would have to share.

- **The same malformed value behaved differently depending on whether a Navigator label came with it.** 1.38.0's label routing read `editor_settings` only when it was an array and rewrote the key regardless, so an identical bad payload was harmless with a label beside it and destructive without one. The shape is now settled in one place, before the routing runs, which is what makes the two cases the same case. `KarMCP_Data::SIBLING_ROOT_KEYS` names the pair once, since the shape check, the hoisting and the merge all have to agree on it.

- **A Navigator label that is not text is rejected instead of stored.** `{"_title": {"nested": 1}}` went into the settings as an array, where it looks like a label that merely refuses to render. A label is a string, or a `null` meaning delete; anything else is reported under the spelling it arrived in.

### Changed

- **`partial_dimensions` now admits what it can get wrong.** Its sibling `unknown_keys` has always said so — "Advisory — dynamic tags and addons legitimately produce names we cannot see" — and this one had no equivalent while having the same kind of blind spot: a dimension is recognised by the shape of its value rather than from a control schema, so a third-party control whose selector uses a single side is flagged with nothing wrong with it. The trade is still the right way round, since the alternative misses every container and every widget the introspection cannot reach, which is where the defect actually happens — but an advisory that does not say it is fallible is read as a verdict.

## [1.38.0]

Three silent-write defects around Elementor layout: a Navigator label that went to the key half the elements do not read, a dimension value that quietly applies nothing, and a grid that lays itself out in two rows without saying so.

### Fixed

- **The Navigator label never appeared on a classic element.** Elementor keeps that label in two places and neither side falls back to the other: `settings._title` on a classic section, column, container or widget, and the root-level `editor_settings.title` on a v4 atomic one. `set-element-label` — and the `editor_settings` route through `update-element` and `batch-update` — wrote the v4 spelling for every element type. On a classic element that stored a root key nothing reads: the call returned `success: true`, the value read back exactly as sent, and the Navigator went on showing "Container". Since classic elements are most of what exists on most sites (atomic elements need the V4 editor and only cover what was built after it), the tool did not work for the majority of its uses and reported that it did.

  The label is now routed by element type in `KarMCP_Data::update_element_settings()`, in both directions: send `editor_settings.title` or `_title`, and it lands in the one this element reads. The reverse case mattered too — `_title` on an atomic element is not a prop of anything and would sit in its typed settings unread. If a payload carries both spellings the one native to the element wins, since that is the value the caller chose for it rather than the one being translated. A label an earlier version left in the wrong key is cleared when a new one is written, so an element does not end up carrying two with the invisible one on top.

  A deletion works from either spelling too. Sending `null` clears the label, and on an atomic element the hoisting merge cannot delete — it can only add or overwrite — so the stored key is cleared explicitly. Handling only the translated spelling would have left a literal `"title": null` behind on the deletion an agent is most likely to write, which is a key that should not exist reporting success.

  The predicate behind the routing is now a named one, `KarMCP_Data::is_atomic_element()`. The test it encodes has to cover both halves of the tree — an atomic container is named by its own `elType` (`e-flexbox`, `e-div-block`), an atomic widget by its `widgetType` — and it existed as a local variable in the middle of a 150-line method, which is why nothing else could ask the question.

- **`set-element-label` now reads the label back off the saved page before reporting success**, and says in a new `stored_in` field which of the two keys it went to. A success assembled from the tool's own arguments is exactly what it returned throughout the life of the bug above.

- **`get-page-snapshot` showed no label for atomic elements, and derived none from their content.** `element_label()` read only `settings`, so a Navigator label at the element root was invisible, and it tested candidates with `is_string()`, which every atomic prop fails — those are `{$$type:…, value:…}`. An atomic page came back as an unlabelled tree of `e-flexbox` and `e-heading`. It now prefers the author's label and unwraps atomic props for the derived fallback.

  It reads that label from the key the element type uses, not from whichever key happens to be present, and the difference is not academic: every write before this release put the label at the root on classic elements too, and those elements do not repair themselves. Preferring whatever was found would have made the snapshot report, on exactly the population this release is about, a label the Elementor Navigator has never displayed — a fresh wrong answer from the tool whose job is to say what the page looks like.

### Added

- **Writes warn when a dimension is left with some sides blank, under `partial_dimensions`.** This is worse than it looks. Elementor renders a dimension control through a selector template — `padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} …` — and in `Base::add_control_rules()` a placeholder that resolves to an empty string throws; the catch around the selector loop returns, abandoning **every** rule of that control. So `padding` with only `top` set does not apply padding to the top: it applies no padding at all. An absent side is the same as a blank one, because `Control_Base_Multiple::get_value()` fills what is missing from the control default and a dimension default is empty on all four sides.

  Nothing said so before. The write succeeded, the value read back exactly as sent, and only the browser knew the rule had been dropped — the same shape as the CSS class key and the shadowed global, and the reason those are reported too. `update-element`, `update-container`, `update-widget`, `add-container`, `add-free-widget` and `add-pro-widget` carry the advisory in their response; `batch-update` reports it per element, which is where it matters most, since one summary count of successes says nothing about which of twenty elements came out unstyled; and `build-page` folds it into its `warnings`, since a whole page is built in one call and the padding that never applied is otherwise found much later.

  The check is pure and shape-based rather than schema-based: it identifies a dimension by its own vocabulary, so it covers containers and third-party widgets — which is where padding and margin are actually written — and works on sites Elementor cannot be introspected on. Repeater rows are scanned too, one level down, and reported as `icon_list[1].item_padding`; a per-row padding breaks exactly like a top-level one, and a checker that stopped at the top would have been silent about it while looking complete. A v4 atomic dimension is excluded by construction, since each of its sides is its own CSS property and a partial one is normal there.

### Changed

- **`add-container` now documents Elementor's grid defaults instead of only naming the controls.** A grid container defaults to 3 columns and **2 rows** (`includes/controls/groups/grid-container.php`), and both defaults surprise: four children land three-across plus one below rather than four across, and an agent that sets only the columns still gets a second `1fr` row splitting the container height under its content, with nothing saying why. The description now gives the slider shape, the single-row setting (`grid_rows_grid: {"unit":"fr","size":1}`), the mobile column default of 1, and the `custom` unit for a raw grid-template value.

## [1.37.2]

Makes the token endpoint able to read a JSON request body, which is what several MCP clients send.

### Fixed

- **A client that posts the token exchange as JSON could never get a token.** `WP_REST_Request::get_body_params()` fills only for `application/x-www-form-urlencoded` (and multipart); a JSON body lands in `get_json_params()`. The token endpoint read only the first, so a JSON exchange arrived with an empty `grant_type` and was answered `unsupported_grant_type`. RFC 6749 §4.1.3 does specify form encoding, but the clients that send JSON are not going to stop, and the registration endpoint has read both encodings since it shipped — only this one had not.

  What made it expensive to find is that it does not look like what it is. The discovery documents resolve, registration returns 201, the browser flow completes, the user approves, and a valid authorization code is issued — and then the client reports nothing more specific than `Unauthorized`, because from its side the token request simply failed. Application Passwords keep working throughout, which points the search at OAuth as a whole rather than at the one endpoint that could not read its own request body. Both handlers that take a body — token and revocation — now read either encoding, with form winning a collision since that is the encoding the spec mandates.

## [1.37.1]

Fixes OAuth sign-in for anyone who was not already logged into WordPress.

### Fixed

- **The login round trip destroyed the client's callback URL.** A user who reaches the authorize endpoint without a WordPress session is sent to `wp-login.php` with the whole authorize request as `redirect_to`. That URL was rebuilt with `sanitize_text_field()`, which strips every percent-encoded octet — so a client's `redirect_uri=http%3A%2F%2F127.0.0.1%3A33418%2Fcallback` came back from the login screen as `http127.0.0.133418callback`. Authorize compared that against the URI the client had registered, correctly found they did not match, and refused. The client never received a code and the connection sat on `Unauthorized`, with nothing on the server side looking wrong: discovery resolved, registration returned 201, and the 401 challenge carried the right `resource_metadata`. The URL is now assembled by `build_return_url()` and escaped with `esc_url_raw()` — the sanitizer for a URL, which leaves the encoding alone.

  It only ever bit a logged-out browser, which is why it lasted. Connecting from a browser that already held an admin session skips the login redirect entirely and worked every time; an IDE opening a fresh browser profile hit it on the first attempt.

### Changed

- **The suite's `sanitize_text_field()` stub now strips percent-encoded octets, as core does.** It modelled only the whitespace half, so the corrupted URL round-tripped intact through every test and the suite reported green while sign-in was broken in production. A stub gentler than core tests a WordPress that does not exist.

## [1.37.0]

Replaces the Slim SEO integration with one for KarSEO.

### Changed

- **The SEO adapter now targets KarSEO.** `KarMCP_SlimSEO_Integration` became `KarMCP_KarSEO_Integration`, and its two dispatcher tools are `karmcp/karseo-read` and `karmcp/karseo-write`. The Tools card, the `audit-page-seo` description and the finding the audit emits when no SEO plugin is installed all name KarSEO now.

- **Detection moved to `KAR_SEO_VER`.** KarSEO defines `SLIM_SEO_VER` as well, as a back-compat alias, so that constant cannot tell the fork from the plugin it forked from — a check on it would light up for either. `KAR_SEO_VER` exists only in KarSEO. The admin's availability helper had a second clause testing for a `\SlimSEO\Plugin` class that has never existed in either tree; it went with the rest.

- **Stored data is deliberately untouched.** KarSEO rebranded its namespace, text domain and constants and left its storage alone, so per-post and per-term SEO still lives in the `slim_seo` meta array and site settings in the option of the same name. Renaming the key to match the new brand would have read every post as empty while reporting success, which is the failure mode this repo keeps writing tests against — so the key stays, with the reason in the code.

- **Upgrades keep the write tool switched off.** `karmcp/slimseo-write` shipped seeded disabled since defaults v22. Renaming it would have left an upgraded site carrying a slug that names nothing while `karmcp/karseo-write` — the tool that writes now — arrived with no seed line at all, which is to say enabled. Defaults v42 strips the retired pair from the stored option and seeds the new write tool off. `AbilitySeedTest` covers it, via a third retirement list the scanner now reads.

## [1.36.0]

Makes the Tools screen agree with what the plugin actually implements, and stops the guardrails refusing reads they were never meant to refuse.

### Fixed

- **Three implemented tools were registered but impossible to reach.** The Tools screen drops any category marked as belonging to a tier this build does not have, which is correct — offering a toggle for a tool that can never register is a lie. What nothing watched was whether that mark still matched reality. WooCommerce and the SEO page audit had since been implemented and stayed marked, so `woo-read` shipped **enabled with no way to switch it off**, and `woo-write` and `audit-page-seo` shipped **disabled with no way to switch them on**. All three are now on the screen. This is the same trap the Widget and Block Builders were in until 1.12.0, and it is now held by a test rather than by attention.

- **Twelve toggles on that screen did nothing.** The mirror image of the same drift: the GeneratePress, Blocksy and Brand Kits groups list tools whose integrations are not in this build, and were not marked, so the screen offered switches for them. They are marked now and no longer appear.

- **Five read-only tools were being treated as writes.** A tool declares whether it is read-only, and the write guard runs whenever that declaration is missing — which is what "missing" evaluates to. Five tools never declared it, so read-only mode, the freeze window and the protected-content rules all refused `list-changes`, `get-change`, `get-page-snapshot`, `list-content-exports` and `search-content`, and the compact tool mode left them out of its read-only pass-through. Nothing broke and nothing errored anywhere visible: a ledger or a snapshot the operator was entitled to read simply came back refused. Every registered tool now declares it, and a test keeps it that way.

  Two of those five needed more than a declaration to be honest about it. `export-content` reads like a query and sits next to `list-content-exports`, but it writes JSON files to disk, so it is declared a write. And `search-content` is covered below.

### Changed

- **`search-content` no longer creates the search index; it asks you to.** It used to install the index table on its first call, which made a tool documented as read-only perform a schema write — the exact thing that stopped its declaration from being true. The install now belongs to `reindex-search`, which is the tool that owns it, and searching an index that has never been built returns an `index_not_built` error naming that tool.

  Refusing is better than what it did before. The table was empty on a first call anyway, so the old behaviour answered "no matches" to a question it had not been able to ask — indistinguishable from a genuine no-match, with no way for the caller to learn it needed to build an index first. If you have never run `reindex-search`, run it once.

- **The `Pro` badge is gone from the two page audits.** `audit-page-seo` and `audit-page-a11y` are implemented in this build; the badge is read by the first-run defaults and renders as an upsell for a tier that does not exist here. Both still ship switched off.

### Documentation

- **This file's user-facing copy in `readme.txt` was two releases behind.** 1.34.0 and 1.35.0 never got their entries there, so the plugin screen's changelog stopped at 1.33.1 while this file carried both. Backfilled.

- The translation count in the README said 2,184 strings; it is 2,154.

## [1.35.0]

Rewrites the PHP snippet validator's findings so a routine snippet no longer looks alarming, and closes a way a dangerous call could pass as a mere warning.

### Changed

- **Snippet findings now come at three levels, and ordinary code no longer looks like a problem.** Anything that blocks a snippet still blocks it. Things worth a reviewer's attention — writing a site option, sending email, defining a constant — are warnings. Things that are ordinary in working code — stopping the request after a redirect, reading request input, firing a hook, defining a callback — are notes.

  This matters because a snippet can only ever be created as a draft: a human has to activate it, so the reviewer is the actual safety boundary. Almost every well-written snippet is built from constructs that were being reported as warnings, so a good snippet arrived covered in them — and a reviewer told that ten routine things are warnings learns to skim, which is how the one that mattered gets waved through. Every snippet now leads with a plain verdict such as "Safe to activate. 3 notes, all ordinary in working code."

- **The admin screens follow that verdict rather than colouring any finding as an error.** The PHP Templates screen in particular used to show a red box whenever a template had any finding at all, including a single routine note. Notes are now shown in grey, warnings in amber, and only a blocking finding turns the box red.

- **A closure is no longer reported as a redeclaration risk.** The "defines a function or class" note only applies to *named* definitions now. `add_action( 'init', function () { … } )` is the most ordinary line in a snippet and was being flagged on every one of them.

### Security

- **A dangerous function passed as a string callback now blocks instead of merely warning.** `array_map( 'system', $commands )` runs a shell command without the scan ever seeing a call to `system`, and that was reported as a warning — which does not block, so the one finding that mattered was exactly the one that could be waved through. The callback argument is now inspected: a string literal naming a blocked function is itself blocking. Ordinary callbacks (`'trim'`, a closure, a first-class callable) are notes, so the change removes noise and adds enforcement at the same time. A callback held in a variable is unchanged and does not need to be: building one is a variable function call or a dynamic invocation, both already blocking.

## [1.34.0]

Rebuilds how raw SQL is checked, and fixes an atomic text element being quietly emptied in the editor by an ordinary edit. Please update.

### Security

- **The read-only SQL guard now inspects a token stream instead of pattern-matching text.** The old design normalized a query into a plain string and ran regular expressions over the result, which is only ever as good as the normalizer's agreement with MySQL — and an audit found four places where the two disagreed: `--` treated as a comment where MySQL requires a space after it, a backslash assumed to escape regardless of the server's `NO_BACKSLASH_ESCAPES` setting, backticks stripped before scanning so the inside of a quoted name was re-read as SQL, and double quotes always read as text even though `ANSI_QUOTES` makes them a name. Three of the four let a query reach the user table while looking harmless to the guard. The failure was always the same shape: the scanner mis-read a byte and produced a plausible string, so the rules above it inspected something the server would never run.

  The guard now splits a query into typed pieces and inspects those, so a keyword inside quotes is text and a name is a name whatever characters surround it. Anything it cannot account for is refused outright rather than guessed at, and a query is allowed only if it is safe under **every** way the server could read it — both settings that change how a query splits into pieces are tried, so the guard never has to know the session's actual configuration. Some deliberately malformed or exotic queries that previously slipped through are now refused; ordinary reporting queries are unaffected.

- **Server system tables are off limits.** The guard protected the WordPress user tables but not the database server's own account tables, so a query naming them directly went through. Reading `mysql`, `information_schema`, `performance_schema` and `sys` is now refused.

- **Assigning a variable inside a read-only query is refused**, since that changes state rather than reading it, as is `SELECT ... INTO` in any of its forms.

- **Delay and lock functions are refused.** `SLEEP`, `BENCHMARK`, `GET_LOCK` and their relatives consume server resources without reading anything. A column that merely shares one of those names still works, because only the function call is matched.

- **The row cap is enforced by the database.** Previously the whole result was fetched into PHP and cut down afterwards, so a wide query could exhaust memory and run unbounded before the limit ever applied. A query that carries no bound of its own is now given one, and a query whose own `LIMIT` is larger than the cap is **refused** rather than silently rewritten — editing raw SQL around literals is the exact trick this guard exists to stop. A bound that only appears inside a subquery no longer counts as bounding the outer result, and a server-side statement timeout is applied and then restored.

### Fixed

- **Editing the text of an atomic element containing an inline tag emptied its rich-text structure.** An atomic text value stores the same text twice: as markup, and as a node tree the editor's rich-text control reads from. Updating the text rebuilt the markup but always wrote an empty tree, so the two halves disagreed. The page kept rendering exactly as before and the tool reported success, so the only symptom was that the element opened empty in the editor — which is why this could go unnoticed across a whole site.

  The two halves now come from a single parse of the markup, matching how Elementor's own editor builds them, so they cannot drift apart. Ids you have already set are kept, ids the parser has to invent are written into both halves, and nested formatting is preserved. All three paths that could trigger the flattening are closed. Elements already damaged are not repaired automatically: re-apply the text once and the structure is rebuilt.

  Accented text and emoji survive the round trip, which is not automatic — the underlying HTML parser reads its input as Latin-1, so Spanish copy is the first thing that breaks without care. Both are covered by tests. On a host missing the DOM or mbstring extension the parse falls back to storing the text as-is rather than failing the write.

### Changed

- **`REPLACE()`, `INSERT()` and `TRUNCATE()` keep working in a `SELECT`.** Each shares a spelling with a write statement, and denylisting the bare word refuses ordinary analysis queries while telling the agent its `SELECT` contains an unsafe keyword — which is not something it can act on. The three are recognised as the read-only functions they also are. The statement forms remain blocked.

## [1.33.1]

Everything a same-day audit of 1.33.0 found, fixed. Ten findings, none breaking a well-formed upload; the three that mattered are the first three below.

### Fixed

- **A stray `=` after a complete base64 quantum was misdiagnosed as a disk error.** The validator's remainder check measured the payload with its padding stripped while the re-pad step measured it with the padding still on; a payload like `YWJj=` — one `=` too many, a real off-by-one in client encoders — passed validation, got inflated to `YWJj====`, and died in the stream filter as `temp_write_failed` ("check the temp directory is writable") plus an unsuppressed PHP warning per attempt. The agent then debugs a disk problem that does not exist. The rule is now the one strict `base64_decode()` actually enforced: a payload that carries `=` must be a complete multiple of 4, refused as `invalid_base64` up front. This also closes the quieter half of the same gap — `YQ=` (half-padded) was being silently *accepted* where every strict decoder refuses it.

- **The 1.33.0 memory claim is now true.** The validity check's `rtrim()` copied the whole multi-megabyte payload just to measure its padding, putting the new path's peak *above* the old decode-in-memory path for the common padded case. The pad count now comes from `substr_count()` on the last two characters — the same idiom `decoded_payload_size()` already used — so the streaming design's actual win (peak ≈ the encoded string plus one 1 MiB chunk) materializes.

- **The executable-filename refusal now covers all four sideload intakes, from one canonical list.** 1.33.0 shipped it on `upload-media` and `sideload-image` only; the featured-image sideload (`create-post`/`update-post`) and `upload-svg-icon`'s URL path — the one that deliberately loosens the SVG MIME checks, where the rule matters most — still accepted `logo.php.jpg` via core's silent rename. Same input, contradictory answers between sibling tools. The check moved to a new `KarMCP_Filename_Guard` (`includes/class-filename-guard.php`), outside the abilities layer where every intake — and any future one — can reach it, and all four paths now call it.

- **The malware scanner and the front door can no longer disagree about what "executable" means.** The plugin carried three independently-hardcoded executable-extension lists, and they had already drifted: `upload-media` refused `shell.php8` while `scan-security`'s regex — built to catch exactly that file under uploads — did not know `php8` or `phar` existed. Both of the audit's regexes are now built from `KarMCP_Filename_Guard::PHP_FAMILY`, and a test walks the whole family through `is_misplaced_php()` so the next added extension cannot drift.

- **`krakow.pl.jpg` is a photo, not a Perl script.** `pl` was on the 1.33.0 list, and as a two-letter inner segment it collides with the Poland ccTLD and ordinary abbreviations — a screenshot named `onet.pl.jpg` was refused with a security error. Dropped; a `.pl` handler on an uploads directory is rare enough that the false positives outweighed it, and a test now keeps it from creeping back.

### Changed

- **`sideload-image` and `upload-svg-icon` refuse an executable filename *before* downloading.** The stored name derives from the URL alone, so running the check after `safe_download()` spent up to 30 seconds and the full transfer on a request that was always going to be refused — the attack/mistake path was the most expensive one. The featured-image path got the same ordering from the start.

- **The `temp_write_failed` error is built in one place** (`temp_write_error()`, the `too_large_error()` pattern this class already used) instead of four verbatim copies whose translatable string could silently fork on the next edit.

## [1.33.0]

Two hardenings on the media-intake path — the one route where bytes chosen by someone outside the site become files on its disk.

### Changed

- **`upload-media` now decodes the base64 payload inside a stream filter, on the way to disk.** "Decode incoming data, write it to a file" is the exact shape of a PHP backdoor, and hosts' pattern-based malware scanners flag that shape on sight — they cannot see the capability check and the type allowlist in front of it. A plugin file sitting in quarantine is truncated to zero bytes without being deleted, so the `require` succeeds, the class never exists, and the site fatals far from the cause (the same failure mode that made `class-security-malware-audit.php` assemble its patterns at runtime). The decode now happens inside PHP's `convert.base64-decode` filter as the bytes travel to the temp file, so the decoded content never exists as a string next to the write. It also drops the second in-memory copy the old `base64_decode()` round-trip held — an upload near the size limit no longer peaks at encoded + decoded at once.

> **Validity moved up front, because the filter fails silently.** `base64_decode()` in strict mode refused garbage with an error an agent could act on; the stream filter skips foreign bytes and hands you a corrupt file. `normalize_upload_payload()` now settles alphabet, padding placement and length before anything touches disk, so a path or a JSON blob sent as `data` still gets the same `invalid_base64` answer it always did — and the size ceiling is still enforced from the encoded length, before the decoded copy exists anywhere.

### Added

- **`upload-media` and `sideload-image` refuse a filename carrying an executable extension anywhere in the name, not just at the end.** WordPress judges `photo.php.jpg` a JPEG by its final extension, but Apache's `AddHandler` matches *any* extension in the name — on a host configured that way the file executes as PHP. Core's `sanitize_file_name()` already defuses this by silently renaming the inner extension (`photo.php_.jpg`), which is why the new check runs on the name **as it arrived, before sanitizing**: checking afterwards would never see it. The refusal replaces core's silent rename with an `executable_filename` error that names the offending extension — an upload that succeeds under a name the caller did not send is worse than an error that says what was wrong with the one they did.

> **Only the inner segments are judged.** A final `.php` is already resolved against the site's allowed types, whose refusal carries the better message (and, for SVG, names the module that would allow it); a leading `php.` is a base name, which no server reads as an extension. `payload.php` and `php.jpg` answer exactly as before.

## [1.32.0]

The third and last of the family: a typography you declare without naming its font family keeps the family of whatever was there before, and now says so.

### Added

- **`inherited_typography`: the parts of a typography group that survive a declaration are now reported.** Elementor stores a group control as a flat set of prefixed keys, and a merge can only add or overwrite — so turning typography on and sending a size, without naming the family, leaves `..._font_family` exactly as the previous design left it. The write reports success, reads back correctly, and one stray heading goes on wearing a font nobody chose. It is the failure that survives a rebrand and is found by the client rather than by you.

  `update-element`, `update-container`, `update-widget` and `batch-update` return `inherited_typography`, mapping the group prefix to the surviving keys **and their values**, so an inherited `Archivo` is visible rather than merely in effect. A new `reset_typography: true` drops the parts you did not name, making your declaration the whole declaration.

> **The trigger is the activator, not any typography key.** A caller sending only `..._font_size` is tweaking one thing on purpose and keeping the rest — legitimate, common, and silent. A caller sending `..._typography` is declaring the group, and a declaration that inherits half of itself is worth saying out loud. Switching the group off is silent too: the element goes back to the kit and inherits nothing.

> **This one is not an override, and its name says so.** `shadowed_globals` and `shadowed_responsive` report something that *beats* the write; this reports something that *outlives* it. Hence `inherited_` rather than `shadowed_`, and `reset_typography` rather than a third `clear_*` — it drops what you did not send, not what defeats you.

  Group prefixes are resolved per group, so a widget with several text parts (`title_typography_*`, `text_typography_*`) reports only the one being declared. Responsive members of the group (`..._font_size_mobile`) count as survivors, because they are.

## [1.31.0]

The write tools now say when a value saved but will not render because a breakpoint overrides it — the same report 1.30.3 added for global bindings, on the axis that decides how a page looks on a phone.

### Added

- **`shadowed_responsive`: a desktop write that a breakpoint override beats is now reported.** Elementor stores a control per breakpoint — `padding`, `padding_tablet`, `padding_mobile` — and at that width the override is what renders; the desktop value beside it is never read there. So setting a padding on an element that carries a mobile override saved, read back exactly as sent, and left the phone layout untouched, with nothing to say so. It is the same silent no-op as a global binding, one axis over, and on an ordinary website it is the more expensive of the two: a page is judged on a phone, and every block copied from a design that had been made responsive arrives carrying its breakpoints.

  `update-element`, `update-container`, `update-widget` and `batch-update` return `shadowed_responsive` mapping each written key to the siblings that beat it — `{"padding": ["padding_mobile"]}`. A new `clear_responsive: true` drops those overrides so the value applies at every width.

> **Which siblings count is a decision, not a scan.** For a desktop key, every breakpoint that declares its own value is reported: each one renders at its own width, above desktop or below. For a key that is itself a breakpoint value, only the max-width chain below it is answered — `_widescreen` and `_laptop` are min-width and sit above desktop, so "narrower than this" has no honest answer for them without modelling the whole cascade, and a half-modelled cascade reports overrides that are not overrides. A warning that cries wolf is one nobody reads.

> **An emptied override is not an override.** A dimension or slider that was touched and cleared keeps its `unit` and nothing else, and a unit with no size paints nothing — reporting it would be the false positive that teaches people to ignore the field. A zero *is* a real value and is reported: `padding_mobile: 0` genuinely beats a desktop 80.

### Changed

- **`update_element_settings()` now takes a named report and a flags array** instead of one out-param and one boolean. Adding this second axis would have made it a seven-parameter method, and the third member of this family — a typography that survives being overwritten — would have made it nine. The MCP responses are unchanged: `shadowed_globals` looks exactly as it did in 1.30.3.

## [1.30.3]

One fix: writing a colour on an element still bound to a global saved, read back correctly, and rendered the global.

### Fixed

- **A literal value written over a global binding is now reported, and can be made to win.** Elementor stores a control's value and, beside it, an optional `__globals__` binding to a kit colour or typography. The binding is what it resolves; the literal next to it is never read. So `update-element`, `update-container`, `update-widget` and `batch-update` all accepted a colour, returned `success: true`, stored it, read it back byte for byte on the next call — and the element went on painting the kit's colour. The only way to find out was to open the page and look, which is the expensive half of building anything: the write says it worked, so the search starts somewhere else. Real symptom, from a course build: a hero that had to be blue kept arriving black, because the container's `background_color` was bound to the kit's BLACK.

  The four tools now return `shadowed_globals` — the written keys whose binding overrides them, with the binding — whenever it happens. A new `clear_globals: true` drops those bindings so the literal applies, in the same call.

> **Reporting is the default and clearing is opt-in, on purpose.** A white-label course works by binding every colour to a global and swapping the kit once, so an element quietly unbound behind the caller's back would break the mechanism the rebrand depends on — and it would break it the same silent way this fix exists to end. The caller who means the literal to win says so. Sending `__globals__` blanked for those keys still works exactly as before, and a caller doing that is not reported.

> The check runs on the stored key name, after the CSS-class and flex rewrites, so a binding is still matched when the caller spells the key the other way (`_css_classes` on a container). Deleting a key with `null` is not a shadowed write and is not reported.

## [1.30.2]

One fix: the MCP Log tab was half full of rejected client probes.

### Fixed

- **Requests for methods this server does not implement no longer take a row in the MCP Log.** Connectors probe: Claude's sends `server/discover` — a method in no MCP specification, in no adapter handler and in nothing KarMCP ships — before the handshake and again before individual calls. The server rejects each one, correctly, with a 400. But every rejection was recorded, and the log keeps only the last 100 entries, so half the visible history was noise and the real calls scrolled out of it twice as fast. Each row is also a read plus a rewrite of an option holding up to 100 serialized records, so the probes doubled that work as well. The log now records the methods the server can actually act on, plus notifications; anything it cannot positively identify as unroutable — a batch, an unparseable body — is still recorded exactly as before.

> The 400 itself is not a fault and is unchanged. It reads oddly because the adapter validates the session before it routes, so a method it has no handler for is reported as a missing `Mcp-Session-Id` header rather than as method-not-found.

## [1.30.1]

One fix: the recovery screen and its tool reported plugins as paused after they had been switched back on.

### Fixed

- **A plugin reactivated by hand kept being reported as paused.** When the fatal-error handler deactivates a plugin it takes it out of `active_plugins` and writes a record in `karmcp_fatal_paused`. Only the `resume-plugin` tool cleared that record, so reactivating the plugin any other way — the Plugins screen, WP-CLI, another plugin — left it behind, and from then on two of KarMCP's own tools disagreed: `list-plugins` reported the plugin active while `list-paused-plugins` reported it paused. Nothing broke, which is why it went unnoticed; the only symptom was an AI agent being handed a fact about the site that was no longer true. Both tools and the Security tab now answer from what is actually active, so a plugin that is running is never described as paused. The stale record itself is cleared when a plugin is activated.

## [1.30.0]

A front-end page view loads a quarter of the PHP it used to, and a REST request that is not an MCP request loads an eighth. Nothing about the plugin's behaviour changes.

### Changed

- **A REST request that cannot reach the MCP endpoint no longer builds the MCP server.** `rest_api_init` fires on every REST request, not only on ours, and building the server is not cheap: the adapter resolves each ability name at construction, which forces the lazy Abilities API, which loads the 76 tool classes and registers ~200 abilities with their JSON schemas. Opening the block editor, every autosave, and every REST call made by any other plugin on the site paid that in full — dozens of times in an editing session. It is now built only for requests under the `mcp/` namespace, for the `/wp-json/` discovery index (so clients that find the endpoint there still do), and for WP-CLI. Measured on `/wp-json/wp/v2/posts`: **3,020 KB across 238 files becomes 402 KB across 43.** The `karmcp_needs_mcp_server` filter forces it on for a client that arrives by some other route.

- **The adapter's own default server is declined on those same requests,** which is what actually saves the work. It is created on `mcp_adapter_init` at priority 10 — before our own hook can decline anything — and creating it calls `wp_get_abilities()` twice for resource and prompt discovery. Skipping only our server would have saved nothing at all. A request that does reach the `mcp/` namespace still gets both servers, so the adapter's default endpoint keeps working.

- **The plugin loads what a request uses, instead of everything.** `KarMCP_Bootstrap::load_classes()` ran 153 `require_once` calls on every request, front-end page views included — 1,548 KB of PHP parsed to declare the malware scanner, the SEO audit, the stock-image clients, the OAuth server, the sandbox generators and the WP-CLI runner, none of which an anonymous visitor can reach. A hand-kept list cannot know what a given request needs; only use can. A generated class map (`includes/classmap.php`) plus `KarMCP_Autoloader` now resolves each class the first time something names it. Measured on the same page view: **1,548 KB across 157 files becomes 402 KB across 43** — the modules registry, the stores whose post types register on `init`, the loaders, and the two files that declare a global function. Sites with more modules switched on load their classes too, which is the point: you pay for what is on.

- **The load-order rules in the bootstrap are gone, because the autoloader keeps them.** The dispatch trait before the integrations that use it, each abstract base before its subclasses, the store and loader pairs that had to arrive together — all of that was maintained by hand in the order of the require list, and a wrong line was an immediate fatal. PHP resolves a parent when it declares the child, so the autoloader gets it right by construction.

- **The class map is generated and committed, not built at runtime.** `php bin/generate-classmap.php` rewrites it; `--check` fails if it is stale, and `bin/check.ps1` runs that. Building it per request would mean stat-ing the whole tree, which is the cost this removes. `ClassmapTest` pins the three properties the change rests on: the map matches the tree, no class file does work when it is included, and only the two known files declare a global function next to their class.

## [1.29.0]

Two admin tabs come out, the Get Help menu goes with them, and the section rail stops spilling its labels across the page when you collapse it.

### Removed

- **The Prompts and Brand Kits tabs are gone,** along with the two modules that existed only to switch them on and off. Both were tab-only features: the admin screen *was* the whole feature, so the module toggles in the Modules tab were a switch with nothing behind them but a link back to the screen you were already on. Removing the screens removes the modules, their two entries in the Modules list, their stat cards and quick-action cards on the Dashboard, the six bundled landing-page blueprints in `prompts/` and the ten bundled kits in `assets/brand-kits/`.

- **Editing the Elementor kit over MCP is unaffected.** The tools that read and write global colors, typography and global classes were never part of these tabs and are untouched: `karmcp/get-global-settings`, `karmcp/update-global-colors`, `karmcp/update-global-typography` and the global-classes tools all still work, as do the gated kit writer and the kit backup store behind them. What went is the visual browser for the bundled kits — the card grid, its Apply button and its confirmation modal — and its two AJAX endpoints, `karmcp_apply_brand_kit` and `karmcp_restore_brand_kit`, which nothing else called.

- **The Get Help menu at the foot of the section rail is gone,** with its Documentation and Support dropdown. Both links are still on the Dashboard, under its own help block, which is where a first-time reader actually looks; carrying a second copy in a 46px-wide rail earned nothing.

### Fixed

- **Collapsing the section rail left its text on top of the page.** The rail collapses to a 46px icon strip, and the sections hid their labels correctly — but the brand name, the version badge and the four rail-foot rows (MCP Log, History, Changelog, Get Help) kept their full-width wording inside that strip, so the words ran straight over the content beside them. The rule that hides them existed, but only inside the `max-width: 782px` step, so it fired on a narrow window and never on the toggle. Collapsing now hides everything in the rail, at any width, exactly as the narrow-window step already did.

## [1.28.0]

Five fixes: one that could take a site down, and four in the sign-in path that stopped AI apps connecting or left them with nothing to act on. Please update.

### Fixed

- **Saving an Elementor document could hang the site and fill the error log.** Opening or saving a document the builder had not converted yet, such as a Floating Buttons library item, sent the search indexer into a loop: indexing reads the document, reading it makes Elementor convert and save it, and that save fired the indexer again. It went round until PHP ran out of memory. Two requests were enough to produce a 22 MB error log and a 512 MB exhaustion. The indexer now refuses to re-enter itself, and releases the guard in a `finally` so an exception cannot leave indexing switched off for the rest of the request.

- **A connected app whose tokens lapsed could never reconnect.** Housekeeping deleted any registered app that currently had no tokens and was more than a day old, on the theory that it was an abandoned registration. But an app whose tokens had simply expired (a refresh token lapsing after 30 days idle, or anything else that cleared them) also has no tokens, so its registration was thrown away while the app still had it saved. Every reconnect then failed with "Invalid client", permanently, and the app kept reopening the sign-in page on a loop. An app that has completed sign-in once is now stamped and kept, so its tokens lapsing just means signing in again. Only registrations that never completed sign-in are still cleaned up. Existing connections are protected automatically on update: the upgrade stamps every client that currently holds a token before the next sweep runs.

- **Command-line AI apps could not finish signing in, failing with "Invalid client or redirect URI".** The app registered fine and reached the sign-in page, then the page refused it. The cause was the check on the return address the app comes back to. A command-line app listens on your own machine, and it can spell that machine three ways (`localhost`, `127.0.0.1`, `::1`) on a port it picks fresh each run. The check accepted a changing port but insisted the spelling match exactly, so an app that registered one spelling and signed in with another was turned away even though both point at the same place. All three spellings are now treated as the same machine, and a trailing slash on the return path no longer counts as a difference. Return addresses that leave your machine, including every `https` one, are still matched exactly.

- **The sign-in error page now says which of the two things went wrong,** the app not being recognised or its return address not matching, and shows the requested and registered addresses side by side. The old page reported both cases with one message, which left nothing to act on. Neither value is a secret: the caller supplied one and registered the other.

- **OAuth discovery returned 404 on a WordPress installed in a subdirectory.** On a site under a path such as `example.com/gpt-build/`, the discovery document clients need (`/.well-known/oauth-protected-resource`, RFC 9728) was advertised at the correct subfolder URL but not served there, because the request path was matched against the site-root path without accounting for the subdirectory. Connecting over OAuth from any standard MCP client dead-ended before it could get a token, and no tools appeared. The install's home-path prefix is now stripped before matching, which is what the authorize endpoint already did. Root installs are unchanged.

### Changed

- **The OAuth tables move to schema v3,** adding `authorized_at` to the clients table. The upgrade runs on the first request after the update and backfills every client that holds a token.

## [1.27.1]

### Fixed

- **The auto-deactivation setting on the Security tab could be ticked but never saved on its own.** "Recovery from fatal errors" offers one choice — deactivate a plugin after it fatals three times in ten minutes — and the only buttons under it were *Install handler* / *Reinstall handler* / *Remove handler*. The checkbox was written to the option purely as a side effect of those three actions, so changing it meant reinstalling the drop-in, and anyone who ticked it and looked for a save button found none: the setting reverted on the next page load. There is now a **Save changes** button that persists the setting and leaves `wp-content/fatal-error-handler.php` exactly as it is. The handler action also stopped treating any unrecognised value as "install": only `install` installs, only `uninstall` uninstalls.

## [1.27.0]

### Added

- **A Spanish translation, and the toolchain that keeps it honest.** The plugin has called `load_plugin_textdomain()` since 1.2.0 and has ~3,400 translatable strings, but `languages/` was empty: there was no POT, so nobody could translate it, and nothing checked that the strings stayed translatable. This release ships `languages/karmcp.pot`, `languages/karmcp-es_ES.po` and the compiled `languages/karmcp-es_ES.mo` — **2,184 strings, every one a person can see**: the whole admin, the Themer, the sandbox screens, the SEO and accessibility audit findings, the OAuth consent screen, the Guardrails messages and every error a tool returns.

- **`tools/make-pot.php`, `tools/make-po.php` and `tools/make-mo.php`.** Neither WP-CLI nor the GNU gettext binaries are a given on a Windows dev box, and both were missing here, so the three steps are self-contained PHP: extract, merge (what `msgmerge` does — existing translations are kept, dropped strings go, new ones arrive empty), compile (what `msgfmt` does). Extraction runs on `token_get_all()`, not a regex, so `'a' . 'b'` concatenation, both quote styles and their escapes all resolve, and a gettext call inside a comment or a string never matches.

- **`TranslationFilesTest`**, eight tests over the shipped files: the PO holds exactly what the POT does, every user-facing string is translated, every plural has all its forms, every translation carries the same printf placeholders as its original, and the MO is genuinely the compiled form of the PO next to it — read back by a second implementation of the format, so a bug in the writer cannot agree with itself. `bin/check.ps1` gained a POT freshness step, because nothing inside the suite can tell whether the POT still matches the source: a newly added `__()` would simply never reach a translator.

### Changed

- **The 1,192 strings the AI agent reads are deliberately left in English**, and the POT says so on each of them. They are the `label` and `description` of every MCP tool, and they travel in the tool schema — the admin never shows them, because the Tools page renders its own curated catalogue. They are operating instructions whose wording was tuned against real agent behaviour; translating them would change what the agent is told, on Spanish sites only, with nobody re-testing the result. An empty `msgstr` makes gettext return the English original, which is the intended behaviour, and the test above fails if anyone fills them in.

### Fixed

- **Eleven error messages in the sandbox screens could not be translated.** The inline `<script>` blocks behind the block, widget, extension and PHP-snippet tables fell back to a hardcoded `'Failed.'` (and one `'Request failed.'`) when an AJAX call returned no message of its own — English on every site, whatever its locale. They now come from `__()` through `wp_json_encode()`, like the confirmation prompts next to them already did.

## [1.26.0]

### Changed

- **The plugin stopped charging every visitor for work only wp-admin and the MCP server do.** An audit of its own per-request cost — written up in [docs/AUDIT-RENDIMIENTO-PLUGIN.md](docs/AUDIT-RENDIMIENTO-PLUGIN.md), with the measurements and the method — found three costs that were paid on requests that could never use what they bought. This release takes the three that needed no change of architecture; the two structural ones (a generated classmap autoloader, and not registering the whole MCP tool surface on REST requests that are not MCP requests) are written up there with their trade-offs and are not in this release.

- **Three or four database queries per page view, to ask a question whose answer changes only when the plugin is updated.** The four stores that own a table — the search index, the change-ledger blobs, OAuth, and redirects — each checked "is my table already at the current version?" on `init` of every request, including a page load by an anonymous visitor. Each answered from its own `karmcp_*_db_version` option written with autoload **off**, so on a site without a persistent object cache that is one `SELECT` per option per request, forever, for four booleans.

  They now share one autoloaded map, `karmcp_schema_state` (`KarMCP_Schema_State`), which arrives inside the `alloptions` WordPress already fetches, and the comparison happens in PHP. Zero extra queries. It is a per-store map and not a single "installed for build X" stamp on purpose: redirects is a module, so its table has to be able to install when someone switches the module on months later, and one global stamp would skip it forever. No migration runs — a site upgrading has an empty map, so each `maybe_install()` executes once more, `dbDelta()` on an already-correct table is a no-op, and the map is written from then on.

- **The WebP rewriter called `stat()` roughly a hundred times on an image-heavy page.** It hooks `wp_calculate_image_srcset`, which hands it the whole srcset, and it asked the filesystem whether a `.webp` sibling existed once per candidate — then again for the same attachment's `src`, and again for any lazy-load attribute. It also re-sanitized the request's `Accept` header on every one of those calls, over a string that cannot change mid-request. Both are now memoized per request. On local disk this was cheap and invisible; on network storage it was not.

- **`admin-ajax.php` no longer loads the admin screens and the entire tool layer.** `is_admin()` is true for AJAX too, so the heartbeat tick of an open editor — plus every autosave, and every AJAX call made by any *other* plugin on the site — was parsing ~2.9 MB of this plugin's PHP for a request that touches none of it. The admin bootstrap now runs on an AJAX request only when the action is one of ours, which is a complete test because every handler this plugin registers there is prefixed `karmcp_`. `admin-post.php` is not an AJAX request, so the `admin_post_karmcp_*` handlers are unaffected.

### Added

- **A performance audit of the plugin itself**, [docs/AUDIT-RENDIMIENTO-PLUGIN.md](docs/AUDIT-RENDIMIENTO-PLUGIN.md): what each kind of request costs, measured with `opcache_compile_file()` over the exact file set each path loads, seven findings with their fix and their effort, the commands to reproduce every number — and the list of obvious suspects that turned out to be innocent, so nobody spends a day optimizing them. `get_tool_catalog()` is the headline there: 1,880 lines and 251 entries, and **0.21 ms** per call. It is a maintainability problem, not a speed one.

## [1.25.3]

### Fixed

- **Both Antigravity recipes on the Connection tab produced a config that could not connect.** The OAuth recipe told the user to run `npx mcp-remote <endpoint>`. Antigravity's MCP client sends a `server/discover` request of its own *before* `initialize`; a stdio server answers "method not found" and the client moves on, but through mcp-remote that request becomes an HTTP POST with no `Mcp-Session-Id` — mcp-remote's http-first probe had initialized a *separate* test transport, so the real one never got a session — and the WordPress MCP adapter refuses it with HTTP 400 (`Missing Mcp-Session-Id header`, as the spec says it should). The client transport raises instead of returning a JSON-RPC error, mcp-remote logs it and forwards nothing, and Antigravity waits until `context deadline exceeded`. Reproduced verbatim on a live site.

  Antigravity does not need the proxy: it speaks Streamable HTTP natively and does OAuth by Dynamic Client Registration, which KarMCP already publishes. The OAuth recipe now emits `{ "serverUrl": "<endpoint>" }` and nothing else — the client registers itself and opens the browser to authorize. The application-password recipe was wrong in a second way: it emitted the Claude/Cursor shape (`type`, `url`, `headers`), and Antigravity's `mcp_config.json` rejects `url` outright — its docs say only `serverUrl` is accepted. That variant now emits `serverUrl` + `headers`. Other clients keep their formats.

- **"Generate" on the Connection tab reported every failure as "Could not create an application password."** The PHP handler always sends a specific message, so that generic text only ever meant one of two things the JavaScript was throwing away: admin-ajax answered `-1` (the page's nonce had expired — the tab had been open too long, or the user had signed in again elsewhere) or `0` (the handler never ran). The script now reads the HTTP status and the raw body and says which it was, and what to do: reload the page, check the plugin is active and nothing blocks `admin-ajax.php`, or look at the fatal log for a 4xx/5xx.

## [1.25.2]

### Fixed

- **Editing a skill by hand was corrupting it.** The `karmcp_skill` post type declared `editor` support with `show_in_rest`, which means the block editor — and a skill is Markdown, not HTML. Gutenberg escaped it: every `<` and `>` came back as an entity, and again on the next save, so the damage compounded silently. Found on this site's `montar-curso` skill, 112 KB of guide that had already been through it once: 113 `&lt;` and 218 `&gt;` where the author had typed angle brackets, and one further edit turned those into `&amp;lt;` and `&amp;gt;`. The code examples stop reading as code and the block quotes stop being block quotes, in a document whose whole job is to be read literally by an agent.

  The post type no longer declares `editor` support. `KarMCP_Skill_Editor` puts a plain monospaced textarea in its place and writes the body through `wp_insert_post_data`, after the `_save_pre` filters, so what was typed is what is stored. Nonce and `manage_options` on the way in — the same bar the post type already sets for touching a skill at all.

### Added

- **`skill-write`**, so a skill can be written through the API instead of through a browser. This goes against what the read side says in as many words — that letting an agent rewrite its own instructions is a governance hole dressed up as a feature — and that objection is right, so the tool gets the treatment this plugin already gives everything powerful enough to be dangerous: it **ships disabled**, an administrator turns it on under KarMCP → Tools, it needs `manage_options` **and** `unfiltered_html`, replacing a whole body needs `confirm`, and every write leaves a WordPress revision.

  Three operations, and the important one is `edit`: a search and replace over the body, with the uniqueness contract `edit-file` uses — `old_string` must match exactly once unless `replace_all` is set, so an ambiguous edit is refused instead of applied to a guess. That contract is the whole point on a document of this size, where a wrong replacement is a silent corruption nobody reads back. It is also the only operation that works at scale: a 120 KB skill cannot be re-sent whole in a tool call, so `update`'s whole-body replacement stops working at exactly the size where it is needed. `edit` is additionally what can repair a skill the editor already escaped, which by hand was impossible — the editor put the escaping straight back on save.

  `unfiltered_html` is required for a specific reason rather than out of caution: without it WordPress runs the body through kses on the way in, which escapes the angle brackets in every code example and reproduces the exact corruption this release exists to end.

## [1.25.1]

### Fixed

- **`duplicate-post` corrupted `_elementor_data`, and a later `batch-update` then emptied the post.** One bug, three symptoms, and it destroyed content: on a live course build a duplicated popup came out at 37,529 bytes against the source's 38,632, `get-page-structure` reported `structure: []` for it, and the next `batch-update` — which failed every operation with "element not found" — left `_elementor_data` as `[]`. Three posts lost their contents this way, silently, while every response read as an ordinary failure.

  The cause was one call. `copy_meta()` passed each value straight to `add_post_meta()`, and the metadata API runs `wp_unslash()` on what it is handed, because it is written for values arriving slashed from a form post. A value read out of the database is not slashed, so every backslash in it was eaten. Invisible for most meta; fatal for `_elementor_data`, which is JSON made largely of `\/`, `\uXXXX` and `\"` escapes. Measured on the affected post: the healthy value carries 1,105 backslashes, the copy carried **zero**. The rest of the plugin has always slashed this meta on the way in — the duplicator was the one path that did not.

  Two more changes so a read failure can never again be mistaken for an empty page. `get_page_data()` returns a `WP_Error` (`unreadable_elementor_data`, with the byte count) when the stored data is present but does not decode, instead of the empty array that made "I cannot read this" indistinguishable from "there is nothing here" — the specific confusion that let the overwrite happen. And `batch-update` no longer saves when no operation matched: a save that cannot change anything has no business running. Its response now carries `saved` so the caller can tell a no-op from a write.

- **Elementor's cached CSS was only invalidated on the fallback save path.** When `Document::save()` reported success we trusted it to invalidate its own caches — while this same method already documents that a native save in a REST/CLI context can report success and drop what it was given. The failure mode is quiet and looks like a rendering bug: the data is correct and the page still paints with the stylesheet of the version before it, so a background goes missing or a container keeps column widths it no longer has. Both paths now clear `_elementor_css`, the 4.2 element cache and the `post-<id>.css` file.

- **`upload-svg-icon` accepted an SVG with no intrinsic size and let it render at 0×0.** A viewBox-only SVG — what authoring tools produce, and what a client logo downloaded from their own site usually is — reports `naturalWidth: 0`; Elementor's `max-width: 100%` then resolves against a parent sized by its content and the element measures nothing at all. No error, nothing visible, and when the logo is the link back to the index it is a navigation control that silently stopped existing. Width and height are now derived from the viewBox and written into the file, and the response reports the dimensions plus a notice when it had to do it. An SVG that already declares a size, or declares one in percentages, is left exactly as it was.

- **`unknown_keys` invented typos in third-party widgets.** Introspection returns the whole control set for Elementor's own widgets, and does not for an addon that builds its controls from its own definition. On Unlimited Elements' `ucaddon_item_menu`, `border_top_width` and `border_top_height` — live on the page, visible on screen — came back flagged, with `minimum_height` offered as the correction for `border_top_height`. A wrong warning costs more than a missing one, so the report is now limited to widgets whose class lives in Elementor's namespaces. The test is positive: anywhere the origin cannot be established, the existing reporting stands.

- **`get-widget-schema` sent callers down the long way round.** For a widget outside the curated catalog it suggested `full: true`, which on an addon widget returns several hundred controls and can still omit the keys the widget uses. It now names the route that actually resolves it — `find-element` on a page already using the widget, then `get-element-settings`.

- **`render-page` reported a login screen as a successful render.** `scope: "full"` fetches over a loopback request, a loopback carries no session, and a site behind an access wall answers it with its sign-in form and a 200 — so the one tool meant to show what a visitor gets confirmed a page nobody had seen. The digest now raises `access_wall` (an error under `full`, a note under `content`) and carries the flag.

## [1.25.0]

### Removed

- **Templates.** The module was metadata and nothing else: `is_available()` returned `false` unconditionally, because the library backend shipped in an upstream Pro overlay that is not part of this plugin. What reached the user was a locked card reading "Not included in this version" - an advertisement for something that cannot be bought, since this build has no licensing at all. Gone with it: a 240-line view whose Pro branch never ran, the submenu entry and routing, the dashboard card and stats block, 117 lines of CSS, and a 77-line JS handler that POSTed to two AJAX actions no PHP here registers. The `save-as-template` / `apply-template` / `list-templates` tools are untouched - they operate on Elementor's own saved templates and share only the name.

- **KarMCP Cloud.** `DEFAULT_BASE_URL` was empty on purpose, so nothing in the subsystem could reach anything unless someone defined `KARMCP_CLOUD_URL` against a host that was never published. Roughly 1,700 lines - an OAuth client, token store, sync layer, gateway credential, five MCP tools and a full connect/disconnect UI - for an account no one can create. Removed with it, because Cloud was their only source: the app-bar announcements bell (`KarMCP_Notifications` read from the Cloud endpoint, so the drawer could only ever say "No announcements"), the Sandbox "Save to Cloud" buttons and Cloud Library panels on all four sandbox screens, settings push/pull, and the Cloud sub-tab in Connection. `KarMCP_Sandbox_Cloud_Abilities` stays: despite the name it is local, backing `export-sandbox-artifact` / `import-sandbox-artifact`, and never talks to a remote.

- **The dead Pro branches in Prompts and Brand Kits.** Both views carried a premium-library arm gated on a class that does not exist here, so about half of each file was unreachable. The bundled prompts and the ten bundled brand kits are unaffected.

### Fixed

- **Applying a brand kit did nothing at all.** The tab rendered ten kits with previews and an Apply button that posted `karmcp_tools_apply_pro_brand_kit`; the plugin's rename to the `karmcp_` prefix never reached that call site, and no handler by either name existed. WordPress answers an unregistered action with `0`, so the `fetch` resolved, no error surfaced, and the palette simply never changed - invisible in review and invisible at runtime.

  Everything required was already present and unused: `KarMCP_Free_Brand_Kits::find_kit` resolves the kit, `KarMCP_System_Kit_Writer::apply_kit` writes colors, typography, custom colors and theme-style defaults, and `KarMCP_Kit_Backup_Store` snapshots and restores. All gate on `manage_options`, not on a licence. Only the two AJAX handlers were missing. Applying now backs up before it writes, so an apply that fails halfway still leaves a way back to the previous palette.

- **"Generate password" on the Connection tab**, broken by the same half-finished rename - it posted `karmcp_tools_create_app_password` while the handler registers `karmcp_create_app_password`.

- **The Context tab's live preview never bound**, because the toggle was queried by an input name still carrying the old prefix.

- Removed prompt-copy telemetry that posted to an action with no handler and read attributes off cards the Pro branch used to render.

### Added

- **`LOCAL_NEWS_SITE`**, a finished sample prompt (hyperlocal news article with Google News structured data and image SEO) that shipped in every release zip while the Prompts tab's hand-written metadata map had no entry for it, leaving it unreachable. The tab lists six samples now.

- **`AjaxActionContractTest`**, asserting that every AJAX action the admin JS posts has a registered handler. Three separate features were dead from this exact mismatch and nothing was checking it. **`BrandKitBundleTest`** asserts the ten bundled kits satisfy what `apply_kit()` demands - a complete four-slot palette with parseable hex - since one bad slot aborts the whole apply by design.

## [1.24.0]

### Fixed

- **`strip_defaults` was reading the wrong half of the page.** Measured against the template it was written for, 1.23.0 removed 8 settings and saved **260 bytes of 28,499** — 0.9%. It answered controls for widgets only, on the reasoning that containers resolve their defaults elsewhere and an unknown default must never authorise a deletion. The reasoning was sound; the premise was wrong. The weight is **on the containers**: Unlimited Elements registers three background sliders on every container, one pre-filled with six Unsplash photographs, so those three containers carried **24,714 of the 28,499 characters — 87%**. Site-wide, 10,992 containers hold the same sample rows, around **80% of all the Elementor data stored**. Containers are now resolved through Elementor's element registry, which is where `get-container-schema` already read them from, so this is a lookup rather than a guess.

- **No repeater could ever equal its default.** Elementor stamps a fresh `_id` on each repeater row every time it saves: three containers built from one factory default hold byte-identical rows under three different ids, while the declared default carries none. Verified on the live data — the addon's own `_generated_id` is shared by 625 pages, Elementor's `_id` by only the 115 that were duplicated together. That single bookkeeping key defeated every comparison, which was precisely the case the feature exists for. It is now ignored when comparing, and nothing else is: a row differing anywhere a person could have touched — the addon's own row ids included — still keeps the whole repeater.

## [1.23.0]

### Added

- **`apply-template { strip_defaults: true }`** drops every setting the template carries that already equals its control default. That is where the weight is: a template copied from a real page keeps every control the original ever touched, and third-party widgets ship theirs pre-filled — the navigation block on one site is **5 elements and 28,499 characters**, almost all of it sample rows nobody sees, and it is applied to every module of every course and travels into the SCORM export.

  **Safe by construction, not by care:** a widget resolves an absent setting to that same default, so removing a value identical to it cannot change what renders. It only stops the value being stored and shipped. Anything a person actually chose differs from the default and stays — one changed row keeps a whole repeater.

  Deliberately NOT folded into `strip_media`, which was the obvious request. Media stripping is a judgement about content — *these pictures belong to the previous brand* — and it cannot tell a sample slider from one somebody wanted. This makes no judgement at all, which is exactly why it can be trusted with a repeater it has never seen.

  Where the defaults cannot be read — a container, a widget this site does not have, Elementor absent — nothing is removed. An unknown default must never authorise deleting a stored value.

- **`bytes_saved`** on the response, because the point is the size and it should be visible rather than inferred.

### Fixed

- **`media_removed: 0` claimed the template carried no imagery.** It cannot know that: `strip_media` reaches the known media keys on an element and nothing inside a third-party repeater, which is exactly where the Unsplash photographs live. A zero was read as "clean" on a block carrying twelve pictures. The description now says what the number does and does not cover, and points at `strip_defaults` for the rest.

## [1.22.1]

### Fixed

- **Two sections could share an id, which made one of them unreachable.** Found on the first real call: this site's manual has `4. Bloques con solución` and `4 bis. Multimedia` as separate chapters, and both reduce to `4`, so every request for section 4 landed on the first and the second could not be addressed at all. Nothing in the outline revealed that — it listed the id twice and looked fine.

  A collision now falls back to the title slug, and only takes a counter if that collides too. The first claimant keeps the plain number, so the ids a manual cross-references itself by stay stable.

## [1.22.1]

### Fixed

- **Two sections could share an id, which left one of them unreachable.** Found on the first real call: this site's manual has `4. Bloques con solución` and `4 bis. Multimedia` as separate chapters, and both reduce to `4` — so every request for section 4 landed on the first, and the second could not be addressed at all. Nothing in the outline revealed it; the id simply appeared twice and looked fine.

  A collision now falls back to the title slug, and only takes a counter if that collides too. The first claimant keeps the plain number, so the ids a manual cross-references itself by stay stable.

## [1.22.0]

### Changed

- **`get-skill` returns an outline when the skill is too long to return.** A skill is a manual and manuals grow: this site's is **90 KB** and gains length with every fix it documents, which put it past what a tool response can carry. That is not a truncation — the call fails, and the reader has to dump the JSON to a file, pull the headings out with a regex and slice it by character offsets before reading a word. Every agent that starts work paid that, and the skill is required reading before touching anything.

  Now a body over 20 KB comes back as its sections, each with the id to ask for and its size. Measured on the real manual: **50 sections, 6 KB of outline against 92 KB of body** — and it says what to fetch next instead of failing.

- **`get-skill { section }` returns one part.** The id is the heading's own number when it has one, because that is how a manual cross-references itself and therefore what a reader tries first — `section: "1.5"` gives 987 characters instead of 92,000. A title or the start of one resolves to the same section, since an agent reading an outline will copy whichever is at hand.

  Asking for a chapter brings its subsections with it. Without that, "read section 1" returns a single paragraph and the reader concludes the manual is empty.

- **A wrong section id returns the outline in the error**, so a caller that guessed can correct itself from the response rather than fetching the whole skill to find out what it should have asked for — which is the trip this change exists to remove.

- `full: true` still returns everything, for a caller that wants it and can take it.

## [1.21.3]

### Changed

- **`search-files` now accepts a single file, not only a directory.** Narrowing a search to one file is the obvious move once you know where to look — checking a generated stylesheet for one rule, confirming which of two files declares a control — and the answer was `Not a directory.`, with no hint that a directory was wanted or that `read-file` exists for reading one outright. It cost a failed call plus a wider search to filter by eye, twice, while auditing the widget catalog.

  An `extensions` filter no longer vetoes a file named outright: that filter exists to narrow a sweep, and it has no business overruling an explicit choice — otherwise a leftover `["php"]` from a previous call makes searching a `.txt` return nothing at all.

  A path that is neither file nor directory now says so, and says what it accepts.

- The tool's own description says what it is *for*: checking what a plugin really does rather than what its documentation claims. That is what it was used for throughout this week's catalog work, and it was not what the description suggested.

## [1.21.2]

### Fixed

- **Eight wrong entries in the catalog for the three widgets this site actually builds with**, each checked against the registered control rather than against the audit's name suggestion — which is a similarity guess, and following it blindly would have written keys that exist and mean something else.

  `image`: **`object_fit` is registered as `object-fit`, with a hyphen** (`image.php:379`) — the catalog had normalised it to an underscore, so it stored and never rendered. `max_width` was removed: `width` already writes `max-width` on the image (`image.php:351`), so there was never a second control. `hover_opacity` was removed too; the widget registers no hover state for opacity at all.

  `heading`: `text_stroke_stroke_width` does not exist — **`text_stroke_text_stroke` IS the width** (`text-stroke.php:59`), and it is a slider taking `{size, unit}`, not the on/off switch the catalog described. There is no separate toggle: setting a width is what enables the stroke. And `title_text_shadow_text_shadow` loses its prefix; the widget applies the Text Shadow group to itself, so the control is `text_shadow_text_shadow`.

  `blockquote`: neither `box_color` nor `button_color` exists. The tweet button's colours are `button_text_color` and `button_background_color`, each with a `_hover` sibling (`blockquote.php:426`, `:442`). The boxed skin has no background control of its own.

- **Ranges the descriptions never mentioned**, from the same audit: image `width` (1-100 %, 1-1000 px), `height` (1-500 px), `opacity` (0.1 to 1 in steps of 0.01 — a plain `0` is out of range), and the text stroke (0-10 px).

- A duplicate `button_text_color` key in the blockquote entry, whose second copy described the text colour as the background. PHPStan caught it on the way in.

### Note

Prioritised by measured use, not by finding count. The audit's biggest single group is `hotspot` with 13 — a widget with **zero** instances across the 45 courses on this site — while `image`, `heading` and `blockquote` are in daily use. The remaining ~52 findings are real and documented, and worth doing when someone reaches for those widgets.

## [1.21.1]

### Fixed

- **`get-widget-schema` reported a multiple `select2` as a string.** It takes and stores an **array** of option keys, and Elementor Pro uses that shape for some of the things most worth setting from an agent: a form's submit actions, a countdown's expiry actions, the heading tags a table of contents collects. So the schema was telling agents to send a string where an array belongs — the same class of defect the catalog audit exists to find, sitting in the mapper that feeds it.

- **And it made the audit report the catalog for being correct.** In its first run against a real site, four of the sixty-six findings were exactly that: entries documented as arrays, which is right, flagged because the mapper claimed string. A repeater documented as an array is also correct however the plugin names its control type — Elementor Pro's form fields arrive as `form-fields-repeater` — and is no longer flagged either.

- **A `calc()` in a second, derived rule is no longer read as a transformation.** Plenty of controls drive an extra rule off the same value while using it untouched elsewhere; only a value that is *never* used as given is worth reporting, which is the `quote_size` case the check was built for.

## [1.21.0]

### Removed

- **The dark bar across the top of the panel is gone**, and with it the last inverted surface: the panel now sits inside wp-admin instead of presenting itself as a separate application.

  Everything it carried moved into the rail rather than disappearing with it — the mark and version at its head, and MCP Log, History, Changelog, Get Help, notifications and the cloud status at its foot. That part was not optional: those three pages are deliberately kept out of both the section list and the WordPress menu, so removing the bar without relocating them would have left them reachable only by typing a URL.

- **The site-wide "KarMCP found N critical security issues" admin notice.** The Security tab already opens with the same two numbers and the age of the scan, which is where anyone acting on them is heading anyway; a red banner on every screen in wp-admin, that cannot be dismissed, gets read once and then stops being read. The scan itself is untouched — it still runs, still scores, still records.

### Changed

- The controls that moved were dressed for a dark bar (translucent white hovers, an icon-only bell with a floating badge). They are rows on a light surface now, with their labels visible and the unread count and cloud dot at the end of the row.

- The narrowing rules collapsed with the bar: there is nothing left to narrow at the top, so the only remaining step is the rail going to icons below the WordPress admin breakpoint — where the new tools follow the sections and drop their labels too.

## [1.20.3]

### Fixed

- **A WebP that came out bigger than its source is now discarded instead of kept and served.** WebP being smaller is the premise of generating it, not a guarantee: on photographic JPEGs already saved at a sensible quality the re-encode regularly grows. Measured on one site's library, **ten photographs and every one of their generated sub-sizes came out larger, from +15% to +43%** — so each upload left a second file on disk, and the rewriter, which prefers the sibling whenever it exists, then served the **larger** of the two to every visitor. A loss twice over.

  `KarMCP_Webp_Generator` compares the two sizes after encoding and deletes a sibling that saved nothing, returning `webp_not_smaller` with both byte counts. Nothing else needed changing: the rewriter already falls back to the original when the sibling is absent, and the optimizer already tolerated an error from the generator.

  The optimizer now also returns `webp_discarded`, deliberately: **a library where every image lands there is telling you the encode quality is wrong for that material**, and that only becomes visible if the number is reported. This check fixes the outcome at upload time; it is not a verdict on WebP, and the quality setting is a separate conversation.

  The default stays `convert_webp: true`. Inverting it would have hidden the cause behind a switch, and would give up the real savings WebP does deliver on flat graphics and screenshots.

## [1.20.2]

### Added

- **`audit-widget-catalog`**, which checks the curated catalog against the controls the widgets on this site actually register. Three defects of this kind arrived in two days — `button_padding`, `insert_url`, `quote_size` — and they share a shape rather than a cause: the key exists, the value is stored, nothing warns, and the result is not what the description promised. Since the catalog is what an agent reads *before* writing, disproving it costs opening Elementor's source.

  Four checks, run per widget: a param the widget registers no control for; a documented type that describes something else (scalar vs composite, the mismatch that makes an agent send the wrong payload); a bounded range the description omits; and a value the widget transforms on render, detected by reading the control's own `selectors` for `{{SIZE}}` inside an operation.

  Widgets the catalog knows but this site does not have — Pro on a free install, Woo with no shop — are reported as `unavailable` rather than as defects, or every partial stack would drown in false findings.

  `KarMCP_Catalog_Audit` is pure: controls in, findings out, no WordPress. That is what lets the three real cases be pinned as tests without an Elementor install, the same split `KarMCP_Seo_Audit` uses.

### Fixed

- **`video` documented `insert_url` as the self-hosted video URL. It is a switch for using an external one** (`video.php:243`, `Controls_Manager::SWITCHER`, labelled "External URL") — the opposite of what the catalog said. And `hosted_url`, the control that actually takes the video file (`video.php:254`), was not published at all, so following the catalog there was **no documented way to insert a self-hosted video**. On a site that exports to SCORM that is the only kind that works: an external URL does not travel in the package. Both are now published, along with `external_url`.

- **`blockquote`'s `quote_size` is a multiplier from 0.5 to 2, not a length.** The widget renders it as `font-size: calc({{SIZE}}{{UNIT}} * 100)` (`blockquote.php:842`), so a value read as pixels is multiplied by a hundred: `62` produced a 3,720px quotation mark and a 4,008px tall block, with no error and no `unknown_keys`, because the key is real and the value is valid.

- **`__globals__` was reported as an unknown setting key.** It holds the bindings from a control to a global colour or font and sits in the same array as the controls, so the exact matching introduced in 1.20.1 flagged it — on every write that used a global, which is the recommended way to theme a course. The warning was firing on correct work. `__dynamic__` is excluded for the same reason.

## [1.20.1]

### Fixed

- **The unknown-key warning added in 1.20.0 caught neither of the two names that motivated it.** It reused `is_group_control_subkey()`, which accepts any key sharing a prefix with a known control — and on the button widget `button_` prefixes half the schema. So `button_padding` and `button_background_color` both passed as valid, and so did `button_pepito_inventado`. A check that fires on `title_color` and stays silent on a pure invention is worse than none: it reads as coverage.

  The leniency was there because Elementor's Optimized Control Loading strips style controls outside the editor, leaving the schema genuinely incomplete. That was already fixed at the source: `KarMCP_Schema_Generator::get_full_controls()` flips `Performance::use_style_controls` while it reads, so `properties` now carries the whole expanded set. Verified against Elementor 4.2.x — the live button schema contains `text_padding`, `typography_font_family` and `button_background_hover_color`, and contains neither `button_padding` (the kit's) nor `button_background_color`.

  Keys are now matched against that list exactly, with responsive variants still allowed by shape since Elementor registers only some of them explicitly. `UnknownSettingKeysTest` runs the eight-case table from the field report: the two kit names, the invention and another widget's control must be reported; the widget's own padding, its hover background, a group sub-field and a responsive variant must stay silent.

  One test from 1.20.0 asserted the old leniency and was rewritten rather than kept. The trade is deliberate and worth stating: on an install where the controls somehow come back partial, a real sub-field would be reported as unknown — a false warning on a call that still succeeds, against missing every wrong name that wears a familiar prefix.

## [1.20.0]

Four fixes from a field report written while building three courses over MCP. Each was measured against real posts; each is pinned by a test that fails without its fix.

### Fixed

- **`update-page-settings` replaced the page's settings instead of merging them.** Elementor's page settings manager ends at `update_metadata( 'post', $id, '_elementor_page_settings', $settings )`, so whatever array it is handed becomes the entire meta — and it was handed only the incoming keys. Sending two background keys to a post took it from eight stored settings to two, and `container_custom_height`, a popup's full-screen height, went with them. It is also the only tool that writes that meta, so there was nothing to fall back to.

  Worse than the loss: the fallback path already merged, so the same call silently either merged or replaced depending on whether the native save happened to succeed. Both paths merge now, matching what `update-element` has always done. `null` still deletes a key.

- **The curated catalog published `button_padding` for the button widget, which is not one of its controls.** The widget's control is `text_padding` (`button-trait.php:496`); `button_padding` is the *kit's* global button style (`theme-style-buttons.php:241`) — a different document and a different scope. Writing it was accepted, changed nothing, and left four buttons on factory padding, discovered in a browser rather than in any error.

  `WidgetCatalogControlNamesTest` now checks the button entry control by control against what the widget registers. The first version of that test banned the kit's names outright and had to be rewritten: `button_padding` is a perfectly real control on call-to-action, on WooCommerce add-to-cart and on a dozen Jet widgets. The name is not wrong — the pairing is.

- **A `full` render answered with the login page reported it as the post.** The loopback request carries no session, so a site behind an access wall returns its login screen at 200 OK, and every collector then analysed that: a published course came back titled "Login", canonical `/login/`, with `no_h1` and `empty_container` warnings that read as defects of the course. Findings that look real about the wrong document are worse than a failure.

  `render-page` now compares the rendered canonical against the post's permalink and, when they differ, reports `render_mismatch` (severity `error`) with `render.matches_post: false` — **dropping** the content warnings, since every one of them describes a page nobody asked about. Comparison is on host + path + query, not path alone: with plain permalinks every post shares the empty path and a path-only check would never fire.

### Added

- **Writes report the setting keys they did not recognise.** `update-element` and `batch-update` return `unknown_keys` when a setting matches no known control, each with `did_you_mean` suggestions. The write still happens — it has to stay advisory, since dynamic tags, third-party addons and Elementor's own incomplete headless control list all produce valid names we cannot see.

  This is the general form of the bug above. Writes accept any key, store it in `_elementor_data` and answer `success`, so a wrong name reads as applied everywhere except on the page. Two in one session: `button_background_color` (the control is `background_color`) left white text on a white button and the module was written off as broken; `button_padding` cost the four buttons. Suggestions match on trailing segment rather than edit distance, because `button_padding` → `text_padding` is seven edits apart and no spelling metric would ever connect them.

  The accounting already existed inside `KarMCP_Settings_Validator` and was only being written to the debug log; it is now returned. The validator was already wired into `add-free-widget` and simply absent from the update path.

- **`render-page` accepts `query_args` for the `scope: "full"` loopback**, so a site that gates access behind a preview key can be rendered as a visitor sees it.

### Note

The same report described `render-page` and `audit-page-a11y` disagreeing about an identical `scope: "content"` render, and proposed that render-page adopt the audit's rendering path. There is only one path: both call `KarMCP_Content_Extractor::extract()` with the same arguments. Whatever caused that difference, it is not the routing, so nothing was changed on a guess.

## [1.19.1]

### Fixed

- **Clicking "KarMCP" in the sidebar opened the Skills list instead of the panel.** WordPress points a top-level menu at whatever sits *first* in its submenu array, and post types declaring `show_in_menu => karmcp` — Skills does — are added on an earlier `admin_menu` pass than `KarMCP_Admin` runs on. So Skills held index 0 and the menu followed it.

  This was true before 1.19.0 and merely untidy: the submenu was open, so you could click Dashboard yourself. Hiding those rows in 1.19.0 turned the wrong first entry into the only way in. `order_submenu()` now runs at priority 99 and promotes the Dashboard row, late enough to hold however many post types attach themselves later.

- **Skills disappeared from the sidebar entirely.** The rule hiding the duplicated section rows was positional — everything except the first — which assumed the first row was the Dashboard. It wasn't: it hid Skills, the one entry with nowhere else to live, since the panel's own rail only lists panel sections. The rule now matches the `page=karmcp-…` sections by href, so it hides exactly the duplicates and leaves anything else attached to the menu reachable.

- `AdminSubmenuOrderTest` pins both: that the Dashboard leads even when a post type registered first, that the remaining entries keep their registration order, and that a submenu with no Dashboard row (or none at all) is left alone rather than emptied.

## [1.19.0]

### Changed

- **The sections moved out of a horizontal strip and into a collapsible rail beside the content.** Twelve sections in a row could only ever scroll or drop their labels; a column shows all of them at once, and collapses to a 46px icon strip when the screen is worth more than the labels. The collapse state is per-user, like WordPress's own folded menu — two admins on one site want different things from the same screen.

  **The state is resolved in PHP, from user meta, not read from `localStorage` on load.** Deciding it client-side paints the rail expanded on every page and snaps it shut a frame later; this is the same reason core renders its folded-menu class server-side.

- **The app bar keeps only the brand and the actions**, so it stays a fixed strip no matter how many sections the plugin grows. The three log/history links carry their label in a span that the CSS drops below 1400px, keeping them identifiable through their `title`.

- **The sidebar no longer repeats the section list.** Every section was listed twice — once in WordPress's menu, once in the panel — in two different orders. The WordPress menu now shows just the `KarMCP` entry.

  The rows are **hidden, not unregistered**: `remove_submenu_page()` drops the page from `$submenu`, which breaks `user_can_access_admin_page()` and the render hook, so every section would 'Cannot load'. Two rows are deliberately kept — `.wp-submenu-head`, which is the label the collapsed sidebar shows on hover, and `.wp-first-item`, core's own link back to the parent page.

### Removed

- The horizontal nav's overflow arrows and drag-to-scroll, in CSS and JS. A column that fits does not need either.

## [1.18.1]

### Fixed

- **The Connection tab's status cards rendered as giant black discs.** 1.18.0 removed the whole `elementor-mcp-status-*` family from the stylesheet while the markup still emitted it. Losing `.karmcp-status-card-icon`'s explicit `28px` box, and the `svg { width: 16px }` inside it, let the inline SVGs expand to the width of the page and paint solid black through `fill: currentColor`. The grid and the card, label and value styles went with them.

  The cause was a prefix match, not a bad judgement call: the dead-family list named `elementor-mcp-stat`, and `startswith()` matched `elementor-mcp-status-*` too. The verification pass then re-used the same list, so it confirmed its own mistake and reported nothing.

  The check that would have caught it, and that now stands as the rule for this stylesheet: **compare the classes the markup actually emits against the classes the stylesheet declares** — never trust a hand-written list of what is safe to delete. Run against every view, that comparison finds no other regression; the only other lost sizing rule, `.karmcp-stat-icon svg`, belonged to the stats bar, which nothing renders.

## [1.18.0]

### Changed

- **The admin panel was rebuilt on a token system, and repainted.** Every colour in `assets/css/admin.css` now comes from a variable: the stylesheet declares primitives (the raw ramps), semantics (`--mcp-surface`, `--mcp-border`, `--mcp-text` — what a colour is *for*), and geometry (radii, spacing, elevation, motion), and nothing below that block names a hex value. Before this release 305 colours were written literally throughout the file, so any change to the palette repainted part of the panel and left the rest behind.

  The visual direction changed with it: flat surfaces separated by borders instead of cards floating on shadows, tighter radii (3/5/7px, was 6/10/14), a grey ramp tinted toward the brand indigo so every surface is related to the mark, an inverted app bar, and section navigation as pills on a sunken rail. The brand indigo itself is unchanged.

- **Nine variables were being used without ever being declared** — `--mcp-radius-md`, `--mcp-amber-50/200/600/800`, `--mcp-blue-50/200/800` and `--mcp-line`. Each fell through to a hardcoded fallback, which meant those rules silently ignored the design system and would have kept the old geometry through any restyle. All nine now resolve to real tokens.

- **The duplicate page header is gone.** `.elementor-mcp-header` and its parts were still styled but no longer rendered by anything: the app bar had taken over, and the two were competing for the top of every screen.

### Removed

- **494 lines of stylesheet for screens that no longer exist**: the stats bar, the Skills page, the dashboard promo cards and the announcements slider. None of their classes are emitted by any PHP or JS file. Verified by diffing the declared class list before and after, to confirm nothing live was caught in the sweep.

### Internal

- **The legacy `elementor-mcp-*` class prefix is renamed to `karmcp-*`** across PHP, CSS and JS — 18 files, zero occurrences left. This includes the shared asset handle, now `karmcp-admin`, which is both the enqueue handle and the target of `wp_localize_script`; those move together or the admin JS loses its localised data.

  **The `elementor_mcp_*` option keys are deliberately untouched.** They are underscore-separated, they hold data from older installs, and `KarMCP_Migration` reads them to carry settings forward. So are the references to the legacy plugin folder in `class-migration.php` and `karmcp.php`, which identify a different plugin and are not ours to rename.

## [1.17.2]

### Fixed

- **The Login Guard module took the entire REST API down on any site where it was enabled.** Every `/wp-json/` request — core routes, the MCP server, anything — died with `Uncaught TypeError: Cannot access offset of type string on string`, while the front end and wp-admin kept working normally, because nothing on a page load runs the filter that broke.

  `restrict_user_endpoints()` treats each entry of a route in the `rest_endpoints` filter as a handler. It isn't: `WP_REST_Server::register_route()` does `$route_args['namespace'] = $route_namespace` before storing the route, so every route carries a `namespace` **string** alongside its numeric handler entries. Writing a permission callback into that string is fatal. The read on the line before was not the problem — `??` uses isset semantics on an illegal string offset and quietly yields null — it was the write.

  Present since 1.4.0 and **not tied to any version**: any site with the module on is affected, and reinstalling or downgrading the plugin does not clear it, because the module's state lives in the `karmcp_active_modules` option. That combination is what made it look like a deployment problem for a while — the same plugin files were healthy on two other sites that simply had the module off.

  `LoginGuardRestEndpointsTest` reproduces core's real structure, `namespace` key included. Without the guard the test fatals rather than fails, which is deliberate: it reproduces the outage instead of describing it.

## [1.17.1]

### Changed

- **`karmcp_content_allowed_protected_meta` now receives the post type and post id**, so a site can scope its answer instead of authorising a meta key everywhere.

  The filter is the escape hatch for plugin CPTs that keep their entire configuration in protected meta — a JetPopup wrapper is `_settings`, `_styles`, `_content_type`, `_conditions` and `_relation_type`, and without them you get a `jet-popup` post that is not a popup. With no context, the only possible answer was global: allowing `_settings` for one CPT allowed it on every post type, and on reads as well as writes, because both call sites share the filter. `_settings` and `_styles` are unprefixed names that other plugins use too, so the blast radius was considerably wider than the key list suggested. A callback can now answer per post type; existing one-argument callbacks keep working unchanged.

  Worth knowing before using it at all: **duplicate-post already solves this without opening anything.** It copies a working wrapper byte for byte, so the protected meta being written is state a human already approved rather than something an agent composed — which is the guarantee the guard exists to protect. Paired with 1.17.0's `post_id` on build-page, creating a course item is two calls with the guard fully closed: duplicate an existing item, then `build-page` into it with `mode: replace`.

## [1.17.0]

Six findings from building two full courses through MCP on a live site. The first three are the expensive kind: the write returns `success: true`, the data reads back correct, and the rendered page is wrong — so there is nothing to debug from, only a page that looks broken for no visible reason.

### Fixed

- **A post filled by MCP could end up never marked as built with Elementor**, and then Elementor does not enqueue its CSS: the page renders with no containers, no padding, everything stacked in one column and widget templates showing their raw placeholders. For a CPT whose own document class gates on the flag — a JetPopup used as a course module — it renders as an empty strip instead. Opening it in the editor once "fixed" it, which is what made it look like a data problem when the data was always fine.

  `_elementor_edit_mode` **is** `Document::is_built_with_elementor()`, and `Document::save()` never writes it: Elementor only sets it when the editor is opened, when the editor itself saves, from the classic-editor metabox, or when it creates a document. None of that happens on an MCP write. The flag was written here, but from inside the branch that only runs when the native save fails — so the successful path, the normal one, wrote everything except the flag. It is now written on both paths, and because it runs on every Elementor write it also repairs posts an earlier version left unflagged.

  Pages created with create-page, create-popup, create-theme-template and build-page were never affected: those tools seed the flag themselves. Anything reached another way — create-post, or a post that already existed — silently was.

- **CSS classes on a container were stored where Elementor never reads them.** `_css_classes` is the widget spelling; containers, sections and columns read `css_classes`, with no underscore — the prefix is what `Widget_Common` adds when it injects the shared Advanced controls into widgets, not a general convention. Elementor stores whichever key it is handed, so the class read back correctly from `_elementor_data` and simply never reached the HTML, taking every stylesheet rule that hung off it. Either spelling is now accepted for either element type and rewritten to the right one. Atomic (v4) elements are left alone: their classes live in the typed `classes` prop.

- **The background normalizer downgraded an existing gradient to a flat colour.** It injected the missing `classic` activator by looking only at the incoming payload, so an update sending just `background_color` at a container whose stored activator was `gradient` looked background-less, got `classic` written over it, and lost the gradient — an ajuste the caller never mentioned. The activator is now decided after the merge, on the element's real settings.

### Added

- **`null` in an update-element or page-settings payload now deletes the key** instead of storing a literal null. Settings were merged, so a setting could only be overwritten, never removed: undoing one meant guessing the value that neutralises that particular control — `{url:'',id:'',size:''}` for an image, `{size:0}` for an overlay's opacity, `''` for a filter — and guessing wrong left it in place without saying so. Deletions resolve after the key rewrites, so removing an aliased key (`justify_content`, `_css_classes`) removes the one actually stored.

- **`strip_media` on apply-template**, which inserts a template block without the source's imagery: background images and their responsive overrides, background overlays, widget images, image filters, and any global bound to those keys. A stylesheet cannot undo them — an element's own `background-image` outranks any rule aimed at it — so re-skinning a copied block meant clearing it element by element. Off by default; the copy stays verbatim. Classic elements only.

- **`post_id` on build-page**, with `mode: append | replace`, to write a structure into a post that already exists. Building a course item used to take build-page into a scratch page, save-as-template, apply-template, and a manual delete — leaving orphan pages behind whenever something failed halfway. The post type now comes from the target, so build-page reaches CPTs it cannot create. `mode` is required rather than defaulted, because guessing "append" duplicates a layout and guessing "replace" destroys one, and `replace` additionally requires `confirm: true`. Permission is checked against `edit_post` on the target, not the page-creation caps.

## [1.16.4]

### Fixed

- **1.16.3 shipped `karmcp.php` with a UTF-8 byte order mark, which broke every JSON response on the site.** Install it and the MCP server becomes unreachable — the client reports `Invalid JSON: expected value at line 1 column 1` — the OAuth discovery documents and the authorize endpoint 404, and connecting a client fails at registration. Meanwhile the site itself loads perfectly, which is what makes it so hard to place.

  A BOM is three bytes *outside* `<?php`, so PHP emits them as output. `karmcp.php` loads on every request, so those bytes go in front of every response the site produces: JSON stops parsing, and `header()` calls in endpoints that emit their own documents come too late.

  The file was rewritten to bump the version with PowerShell's `Set-Content -Encoding utf8`, which on Windows PowerShell 5.1 means "UTF-8 **with** BOM". The file stayed valid PHP, so nothing caught it — not the 969 tests, not PHPCS, not PHPStan, not `php -l`. Only the live site failed, pointing nowhere near the cause.

  `NoByteOrderMarkTest` now fails on any BOM in the tree, with the entry point asserted separately because it is the one loaded on every request. It was verified by planting a BOM'd file and watching the test name it, rather than by assuming a passing test proves anything.

  **Everything in 1.16.2 and 1.16.3 was fine.** The deferred load, the `%i` conversions and the three bug fixes were all unaffected — the failure was three bytes at the front of one file.

## [1.16.3]

### Changed

- **The static-analysis ruleset is clean and now blocks.** PHPCS went from 87 errors and 17 warnings to **zero**, and `bin/check.ps1` fails on any new one. The ruleset was written a while ago and deliberately narrow — injection, unescaped output, nonces, prepared SQL, i18n, global prefixes, PHP 8.1 compatibility — but until 1.16.2 nothing had ever run it, so the findings had been accumulating unread. The first ratchet in `phpcs.xml.dist` is now closed; the next block to add is Docs.

  Most of the work was separating the two kinds of finding, because they need opposite treatments and a baseline would have flattened both:

- **`%i` for every table name.** 37 findings were `$wpdb->prepare( 'SELECT … FROM ' . self::table() . ' …' )` — values properly bound, table name concatenated, which the sniff cannot verify. Rather than silence them, they now use `%i`, the identifier placeholder WordPress has had since 6.2 (this plugin requires 6.9). Two of those were not cosmetic: `describe-table` and the database guard's before-image build SQL from a **caller-supplied** table and column names, and were escaping them with a hand-rolled backtick strip. That is now WordPress's job.

- **What is genuinely intentional says so, at the line.** An OAuth `redirect_uri` must go to an external host — that is the protocol, and `wp_safe_redirect()` would break every sign-in — so it stays `wp_redirect()` with the reason written down and a pointer to the `redirect_registered()` check that makes it safe. The `Authorization` header is not sanitized because `parse_bearer()` already restricts it to the base64url alphabet, and a sanitizer that quietly rewrote a character would turn a valid token into an unexplained failed login. A PHP template's source cannot be sanitized without destroying it. Third-party hooks (WPML's, the adapter's, core's `the_content`) cannot be prefixed without ceasing to be those hooks.

  Each of those is a place where the obvious "fix" is the wrong one, and each now carries the argument in a comment rather than reading as an oversight to whoever looks next.

- **Real fixes among them:** the login redirect to our own login screen now uses `wp_safe_redirect()`; `$_SERVER` reads across OAuth, the WebP rewriter and the login guard are sanitized; `$slots` in the Themer canvas template is prefixed, because that file is included by the template loader in the **global** scope where a bare name really can collide (the other view partials are included from inside a method and are genuinely local); a redundant `syntax_check()` override on the block store that only called its parent is gone; and eleven `translators:` comments now tell whoever translates the strings what the placeholders are.

### Fixed

- `bin/check.ps1` crashed when PHPCS reported nothing — it called `.ToString()` on the null from a `Select-String` that matched no total line. The clean path had never run before, which is the point.

## [1.16.2]

### Changed

- **The tool classes no longer load on requests that never ask for a tool.** All 76 files under `includes/abilities/` — 1.1 MB of source, several MB once PHP has it in memory — were required on `plugins_loaded`, on every request, front-end page views included. A visitor reading a blog post paid for the whole MCP surface.

  They now load at the moment something first asks for a tool: `wp_abilities_api_init` (which is lazy — it fires on the first `wp_get_ability()` call, i.e. the MCP server registering its tools), wp-admin, and the two runtime callers that name a tool class in a signature. On a host with a 128 MB limit this is the difference between the site working and not.

  The registrar is built on first use for the same reason; it lives among the classes being deferred.

  **What makes this safe is checkable, so it is checked.** `DeferredAbilityLoadTest` pins that no runtime file depends on a class that may not be loaded yet — a list of six references, each with its reason written down — that every deferred path exists, that `load_classes()` keeps none of them, and that each abstract base still loads before its subclasses. All four fail silently in production and none of them would have failed in the suite.

### Fixed

- **Inserting a Kadence or Spectra block at a position that doesn't exist reported success.** `KarMCP_Block_Tree`'s mutators are total: handed a path that doesn't resolve they return the tree untouched. That is right for a pure transform, and it means the save that follows succeeds and the tool answers `added` for a block it never inserted. The Gutenberg tools checked the path first; `add-block` and `insert-pattern` on both integrations did not.

  The check now lives in the primitive as `position_error()` and every caller runs it. It also covers what none of them checked: an unrecognised `position.mode`, which fell through to a splice with an empty path — so a typo in `inside` was a silent no-op too.

- **`move-block` reported a move it had not performed.** Verifying that both paths resolve isn't enough: `move()` also declines a block moved onto its own position, and a target inside the subtree being moved (which would remove the node and then fail to re-insert it, losing it). Both resolve fine. `move_error()` names all four cases, and a test pins that every rejection is one `move()` genuinely won't perform — so the guard can't drift into refusing moves that work.

- **`detect-elementor-version` failed validation on sites without Elementor Pro.** It returned `null` for `elementor_pro_version` against an output schema declaring a string, so a strict client rejected the response — from the one tool the documentation tells an agent to call first. It returns an empty string.

- **OAuth sign-in never started on a WordPress installed in a subdirectory.** The authorize endpoint is advertised from the public base, which carries the subdirectory, so the request arrives as `/blog/karmcp-oauth/authorize` while the matcher compared it against the bare `/karmcp-oauth/authorize`. The endpoint we published was never served and sign-in dead-ended on the site's 404 page — with nothing anywhere reporting an error. The path is matched against the installation's own prefix, and the bare path still matches for a proxy that strips it. The metadata endpoint already did this correctly; only authorize didn't.

## [1.16.1]

### Fixed

- **The contrast check went silent when it had nothing to look at.** Run against a real Elementor page the day 1.16.0 shipped, it found zero text declaring a colour inline — Elementor writes its colour into a generated stylesheet — and the report simply said nothing about contrast at all.

  Silence is ambiguous, and the ambiguity flatters the tool: a reader cannot tell "contrast is fine" from "contrast was never looked at". It now reports `contrast-not-checked` and says outright that this **is not a pass**, along with the reason.

  The underlying limitation stands and is the next piece of work: on a builder page the colour lives in the builder's data, not in the markup. That is where it will have to be read from.

## [1.16.0]

### Added

- **`audit-page-a11y`: the accessibility half of the loop.** Image alt text, link and form-field accessible names, heading outline, declared language, page title, landmarks, duplicate ids and text contrast — each finding carrying a WCAG success criterion and a recommendation, scored 0-100 with a letter grade on the same curve as the SEO audit.

  **What it says about itself matters as much as what it finds.** An automated check catches a minority of WCAG failures, and a tool that hides that is selling a certificate it cannot issue. This one states the limit in its own output: it sees a missing alt attribute, not whether the alt text describes the image; a skipped heading level, not whether the headings mean anything. Reading order, focus order and keyboard traps need a browser and a person.

- **`KarMCP_Color_Contrast`** — the WCAG contrast maths, exact where the specification is exact and silent where it is not.

  Contrast is the one check where a false pass does real damage, because it closes the question. So the rule set before a line was written was: what cannot be resolved is reported as unresolved, never as a pass. A colour from a stylesheet, a CSS variable, a gradient, a background image, a translucent overlay — each of those comes back `inconclusive` with the reason attached.

  One case is better than that. When the font size is unknown, which is the normal case since sizes live in stylesheets, two thirds of the range still have a rigorous answer: below 3:1 the text fails at any size, at or above 4.5:1 it passes at any size, and only the band between them genuinely turns on the size.

- `KarMCP_Content_Extractor` collects the text elements that declare a colour in their own `style` attribute, walking up for the nearest declared background and refusing gradients and images rather than guessing what is behind the text. This is the honest ceiling of a contrast check that has markup but no browser — and page builders emit a lot of inline style, so it is not nothing.

- `get-page-snapshot` now fills its `a11y` section too. Both halves of the seam it reserved from the beginning are finally answered.

### Fixed

- The readability recommendation always blamed sentence length. Both factors feed the same formula, and a real page had 11-word sentences — short — graded hard purely on vocabulary, while the advice told the owner to shorten the sentences. It now names whichever factor actually weighs.

## [1.15.1]

### Fixed

- **Readability was measuring the furniture, not the copy.** 1.15.0 scored the page's whole visible text, and a page's visible text is mostly navigation, button labels and headings — none of which end in a full stop. A real homepage came back at **8 out of 100, "muy difícil", averaging 45 words per sentence**, off copy that reads like *"Te creamos una web que vende o agenda citas por ti"*. Plain Spanish, graded unreadable, with a recommendation telling the owner to shorten sentences that were already short.

  What the formula was actually being fed: the skip link, then the navigation menu twice — desktop and mobile — then headings, then feature lists. Roughly 22 terminators across 988 words.

  Readability now measures **prose only**: the text of `<p>` elements outside `nav`, `header`, `footer`, `aside` and `form`. When there is not enough prose it reports `insufficient` rather than falling back to the full text, because that fallback is the bug.

- **Sentences are counted per paragraph, with a minimum of one.** A paragraph that ends without a full stop is still a sentence; run together in one blob, a page of short paragraphs reads as a single enormous sentence and the score collapses. The extractor hands over paragraphs separated by newlines so the counter can tell them apart.

- `KarMCP_Content_Extractor` exposes `text.prose` and `text.prose_words` alongside the existing `text.excerpt` and `text.words`, so `render-page` returns both views: everything a visitor can read, and the running prose. Additive — nothing that read the old keys changes.

## [1.15.0]

### Added

- **Readability, in the formula that matches the page's language.** `audit-page-seo` now reports reading ease alongside everything else — score, band and average sentence length.

  The reason this is worth more than a line item: **Flesch Reading Ease is calibrated on English.** Run Spanish prose through it and you get a number that looks perfectly valid and is not, because Spanish averages more syllables per word, so every page comes back "difficult". That is the worst kind of wrong: nothing errors, and somebody rewrites good copy to chase a score that was never measuring their language.

  Spanish is scored with **Szigriszt-Pazos** and read on the **INFLESZ** scale; English keeps Flesch. A language with no calibrated formula gets no score and says so, rather than borrowing one.

  The language is resolved **per post, not per site** — Polylang and WPML are asked first, since a multilingual site has a locale per page and that is exactly where the wrong-formula mistake would land.

  The Spanish syllable counter is exact rather than heuristic. Vowel groups, diphthongs, hiatus, the accent that breaks a diphthong (`día`, `país`), the silent `h` that does not (`ahijado`), `ü`, and `y` behaving as a vowel after one (`rey`, `muy`) are all implemented from the rules — Spanish is regular enough to allow it, where English needs a dictionary and every implementation settles for a heuristic. 28 words are pinned in the suite one by one, because a silent drift of one syllable per word moves the score by tens of points and nothing else would catch it.

  Reported, never scored: a dense text is a legitimate choice for a legal or technical page, so the finding states the number and leaves the judgement to whoever knows the audience. Pages under 100 words get no score at all — thin content is already its own finding, and a reading score off forty words is noise.

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
