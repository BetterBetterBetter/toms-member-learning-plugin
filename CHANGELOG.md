# Changelog

## Unreleased

## 0.3.1 - 2026-08-26

- Allowed production sites without source or host configuration access to save the Library bridge client secret through the existing write-only WordPress setting, while continuing to prefer and protect a server-managed `TSOL_LIBRARY_CLIENT_SECRET` constant when available.

## 0.3.0 - 2026-08-26

- Added **TSOL Library → Access Groups**, where administrators create named reusable Library access packages and define the Courses, Series, collections, or broad Library areas each package unlocks.
- Added a **Library Access Groups** panel to MemberPress membership editors so new and existing products can receive one or more packages without duplicating access conditions.
- Added guarded compilation to native MemberPress rules, full current-user/content access comparison, explicit publication confirmation, concurrent-operation locking, source-change detection, and one-step rollback.
- Imported the complete current policy—including the separately shipped New Marketer Workshop rule—into eight editable groups while preserving the non-membership exception.
- Made Access Groups the ownership boundary for TSOL Library rules: plugin-owned stragglers can be reconciled without changing live access, arbitrary rules are never auto-modified, and publishing is blocked while any Library rule remains unmanaged.
- Retired the completed Import & Legacy browser tab while preserving its guarded WP-CLI verification and rollback tools, and updated Library Settings to direct administrators to Access Groups instead of raw MemberPress rules.

## 0.2.3 - 2026-08-26

- Updated the guarded Library normalization, catalogue, publication, and MemberPress access manifests for the latest production snapshot, including Medicine Cabinet Sessions 3 and 4 and the production Social Media course slug.

## 0.2.2 - 2026-08-26

- Made MemberPress migration verification identify approved Course-root access corrections by stable WordPress slug instead of mutable titles.
- Added a MemberPress Account Security tab with an explicitly confirmed, nonce-protected action that queues durable all-device Library session revocation without implying that WordPress, MemberPress, or Access sessions are also cleared.
- Made the native Course body the single public **About this course** source, retained ordered learning outcomes as structured fields, and removed the duplicate Course WYSIWYG.
- Added a strict semantic HTML boundary for every Library and Speaker WYSIWYG synchronized to the standalone application, stripping pasted styling, layout wrappers, embeds, scripts, and unsafe links on save and export.
- Added a guarded Course-body publication migration that privately archives legacy resource-only bodies, moves their downloads and links into protected lesson Resources, and retires duplicate public-description metadata.
- Added durable, HMAC-signed catalogue wake-ups with request coalescing, bounded retries, and the existing incremental journal as a polling fallback; no catalogue or MemberPress data is sent in the webhook.
- Replaced the Speaker headshot attachment-editor workaround with WordPress's native select-then-crop media workflow, requiring an interactive 1:1 crop while retaining the original upload.
- Removed the redundant editable Library inclusion checkbox: the dedicated Course, Series, and Content post types now establish Library identity automatically, while WordPress status controls member visibility and non-published records remain available for administrator preview in the protected projection.
- Replaced the plain Speaker multiple select on Library editors with a searchable visual profile picker, ordered selected cards, keyboard controls, responsive presentation, and a native no-JavaScript fallback.
- Replaced the retired Library Speakers taxonomy with private Speaker profiles using the classic About editor, native Featured Image headshots, role/organisation/website/social fields, and direct Course/Series/Content relationships that never participate in MemberPress authorization.
- Moved Gemini configuration from the generic TSOL settings screen into a dedicated Accountability Modal AI Matching tab without changing the stored credential or option names.
- Consolidated the AI enable switch, model, fit threshold, fallback explanation, and privacy notice under the feature that uses them.

## 0.2.1 - 2026-08-09

- Added a dedicated TSOL Library Footer menu location and authenticated navigation endpoint for the standalone Library.
- Return an empty navigation list when no menu is assigned so the Library can omit the WordPress-managed menu cleanly.

## 0.2.0 - 2026-08-07

- Added the TSOL Library authentication bridge with mandatory S256 PKCE, exact callbacks, hashed one-use authorization codes, short-lived tokens, rate limits, and signed logout propagation.
- Separated authentication from course authorization: every authenticated WordPress user can enter the Library, while MemberPress-native per-content rules and WordPress `manage_options` decide protected content access without a second allowlist.
- Added authenticated content-access and readiness endpoints, redacted audit events, no-store security headers, `DONOTCACHEPAGE` and WP Rocket exclusions, and scheduled authorization-code cleanup.
- Made Access Platform SSO an optional WordPress login source instead of a hard plugin dependency.
- Required production bridge secrets to be supplied outside the WordPress database.
- Made Consent Mode defaults cache-safe by always denying optional storage until the visitor's saved choice is applied in the browser.
- Enforced consent expiry in both the cookie and localStorage fallback and applied Global Privacy Control to previously stored marketing consent.
- Removed known first-party analytics and marketing cookies on rejection, then reloaded after withdrawal so already-running consent-managed trackers stop.
- Routed the official Tapfiliate plugin's loader and WooCommerce conversion payload through the Marketing consent category.
- Converted the site's WPCode MemberPress and WooCommerce Tapfiliate handlers into inert Marketing-category scripts until consent is granted.
- Added a compatibility bridge that makes legacy HFCM Google, Vimeo Player API, RocketChat, and Kissmetrics snippets consent-controlled while they are being retired from configuration.
- Gated server-rendered Vimeo/YouTube iframes and Elementor video-lightbox launches behind Marketing consent with a visible settings placeholder.
- Added accessible JavaScript syntax validation to the consent snippet editor so malformed tracker snippets cannot be saved from the admin screen.
- Preserved JavaScript backslashes when saving cookie-consent snippets by removing redundant Settings API unslashing.

## 0.1.7 - 2026-06-17

- Fixed the cookie preferences modal so Reject optional closes the blocking overlay after saving consent.

## 0.1.6 - 2026-06-15

- Iterated the release version for the AI-assisted accountability group matching release.

## 0.1.5 - 2026-06-15

- Added AI-assisted accountability group recommendations using Gemini structured JSON output.
- Added availability-filtered top-three recommendations, Show all groups fallback, and one-click group joining through the Groups API.
- Added soft dependency gating for the accountability enrollment engine to avoid unsynced half-joins.
- Added admin controls for AI matching, Gemini model selection, fit threshold, and per-group matching bio overrides.
- Added joined/requested group metadata to accountability submissions.

## 0.1.4 - 2026-06-12

- Improved Cookie Consent script management with repeater-style URL fields.
- Added accordion-based inline JavaScript snippets with editable saved snippet names.
- Added WordPress code editor support for inline JavaScript snippets.
- Improved script admin UI polish, spacing, focus states, icon-only remove buttons, and single-item remove handling.
- Preserved named empty snippets in the admin while keeping empty JavaScript out of the frontend payload.

## 0.1.3 - 2026-06-12

- Added shared launcher docking so floating TSOL buttons in the same corner stack instead of overlapping.
- Changed the cookie consent floating button to use a cookie icon instead of the site logo.

## 0.1.2 - 2026-06-12

- Added modular Cookie Consent feature with a TSOL admin submenu.
- Added branded frontend cookie banner, preference center, and floating settings button.
- Added Google Consent Mode v2 defaults and consent updates for analytics/marketing choices.
- Added consent-controlled analytics and marketing script loading buckets.
- Added configurable banner copy, legal links, category descriptions, display placement, GPC handling, and consent versioning.
- Added admin implementation notes for migrating hard-coded tracking snippets into consent-aware loading.

## 0.1.1 - 2026-06-11

- Added GitHub Plugin Update Checker support for WordPress dashboard updates.
- Added release documentation for version bumps and GitHub release assets.
- Added configurable resume launcher placement in the accountability modal display rules.
- Added resume launcher progress bubble for users who have completed more than one step.
- Added nonce-protected admin deletion for saved accountability submissions.
- Improved launcher eligibility so submitted users and existing accountability group members do not see the modal launcher.
- Improved launcher icon centering and switched it to the uploaded site icon as a white mask.
- Improved modal accessibility with progressbar labeling, focus handling, and contrast fixes.

## 0.1.0 - 2026-06-11

- Initial site-specific plugin scaffold.
- Added dependency checks for Access Platform SSO.
- Added modular accountability modal feature.
- Added TSOL admin menu with overview, submissions, display rules, and content tabs.
- Added configurable modal questions, page display rules, local draft saving, admin preview, and CSV submission export.
