# Tom's School Of Life Plugin

Dedicated WordPress plugin for Tom's School Of Life.

This plugin is intentionally site-specific. It should contain behavior that belongs to this website and should not be added to the shared Access Platform SSO plugin.

## Optional Access SSO integration

Access Platform SSO is an optional upstream WordPress login source. The TSOL plugin and Library authentication bridge continue to load when it is absent. MemberPress, not Access SSO, is the protected-content authorization authority.

Supported development/deployed plugin basenames:

- `wp-access-sso/access-platform-sso.php`
- `wp-access-sso-1.1.3/access-platform-sso.php`
- `access-platform-sso/access-platform-sso.php`

## Structure

- `tomschooloflife-plugin.php` - Minimal plugin entrypoint, constants, file loading, and activation hooks.
- `includes/class-plugin.php` - Main loader and feature registration.
- `includes/class-dependencies.php` - External dependency checks.
- `includes/contracts/interface-feature.php` - Contract for isolated features.
- `includes/class-admin-settings.php` - Admin settings/status page.
- `includes/features/accountability-modal/class-accountability-modal.php` - Accountability page modal feature.
- `includes/features/accountability-modal/class-accountability-modal-admin.php` - Accountability modal admin tabs.
- `includes/features/accountability-modal/class-accountability-modal-repository.php` - Accountability modal data access.
- `includes/features/accountability-modal/class-accountability-modal-renderer.php` - Accountability modal form markup.
- `includes/features/accountability-modal/class-accountability-modal-settings.php` - Accountability modal display/content settings.
- `includes/features/accountability-modal/class-accountability-modal-submission-handler.php` - Accountability modal AJAX submission and user meta storage.
- `includes/features/cookie-consent/class-cookie-consent.php` - Cookie consent frontend feature.
- `includes/features/cookie-consent/class-cookie-consent-admin.php` - Cookie consent admin tabs.
- `includes/features/cookie-consent/class-cookie-consent-settings.php` - Cookie consent settings and sanitization.
- `includes/features/library-auth/` - Narrow WordPress identity, MemberPress content-authorization, and Library-session security bridge for the standalone Library.
- `includes/features/library-auth/class-library-account-security.php` - MemberPress Account Security tab and confirmed all-device Library-session revocation action.
- `includes/features/library-content/` - Private TSOL-owned courses/content/Speaker profiles, public Course landing fields, editorial UI, catalogue projection, and guarded MemberPress Access Groups management.
- `includes/migrations/library-catalogue-import/` - Guarded local clone-only importer from the locked legacy inventory.
- `assets/admin/` - Admin page assets.
- `assets/features/accountability-modal/` - Accountability modal assets.
- `assets/features/cookie-consent/` - Cookie consent assets.
- `plugin-update-checker/` - Bundled GitHub update checker library used for WordPress dashboard updates.
- `CHANGELOG.md` - Version history and release notes.
- `UPDATER_GUIDE.md` - Release workflow for dashboard updates.

## Feature Pattern

Each client-specific feature should live in its own folder under `includes/features/{feature-name}/` and implement `TSOL_Site_Feature`.

Feature assets should live in `assets/features/{feature-name}/`. Register the feature in `TomsSchoolOfLifePlugin::register_features()`.

## Accountability Modal

The accountability modal is automatic, not shortcode-based. It renders only on selected singular content locations for logged-in users who are not currently in an accountability group and have not already submitted the intake form. The modal opens after the visitor scrolls down the page.

The intake form stores responses in user meta and lists only published MEC accountability events mapped to Groups children of the accountability parent group. Events marked with `group_full` truthy values are treated as waitlist-only and excluded from the modal choices.

Admins can manually open the modal from the WordPress admin bar while viewing a selected display location.

In-progress answers can be saved locally in the user's browser and are cleared after a successful submission. If a user closes the modal before submitting, a branded square-aspect circular launcher appears in the configured screen corner so they can reopen and finish the form. The launcher uses the uploaded site-icon mark as a centered white mask and can show a short progress bubble once the user has completed more than one step.

The display locations, scroll threshold, local draft behavior, resume launcher behavior, admin preview button, member/submission hiding rules, modal copy, and question flow can be managed in WordPress under `TSOL > Accountability Modal`.

Gemini configuration is isolated under `TSOL > Accountability Modal > AI Matching`. That tab owns the Accountability-specific API key status, model, enable switch, strong-fit threshold, fallback explanation, and data-sharing notice. The generic TSOL settings page deliberately does not present Gemini as a site-wide integration, and the TSOL Library does not use this credential.

The `Display Rules` tab includes a searchable content picker with selected-location review rows and filters for content type, publish status, and selected/unselected state.

The `Content` tab includes an ACF-style accordion question repeater. Admins can add, remove, reorder, enable/disable, and require questions. Question keys are generated automatically from the question title. Supported field types are text field, text area, number, number slider, select dropdown, checkbox select, radio select, and the dedicated joinable accountability calls selector.

The `Submissions` admin tab reads the saved user meta in review cards with user actions, intake answers, selected call availability, and recommended group fits. It supports search, status/call/date filters, sorting, pagination, CSV export for either all submissions or the current filtered/sorted result set, and nonce-protected deletion of a user's saved intake submission. Availability is always enforced before optional Gemini ranking; unavailable or weak AI results fall back to open-call choices. A separately validated join action can enroll a member when the accountability enrollment engine is available.

Accessibility support includes dialog labeling, described-by copy, Escape close, focus trapping, focus return, keyboard-accessible launcher, progressbar semantics, live status messages, and reduced-motion handling for launcher animation. It should still receive real screen-reader/browser QA before being treated as formally audited for WCAG conformance.

The target content IDs can still be changed with the `tsol_site_accountability_modal_page` filter.

## Cookie Consent

The cookie consent feature renders an on-brand TSOL banner and preference center for public visitors. It stores consent in a first-party cookie and expiring localStorage fallback, supports a floating cookie settings button, respects Global Privacy Control for marketing choices, removes known first-party cookies after rejection, and can be opened by admins from the frontend admin bar.

The feature prints Google Consent Mode v2 defaults early in the page head and updates Consent Mode after the visitor chooses. Analytics and marketing scripts added under `TSOL > Cookie Consent > Scripts` are loaded only after the relevant category is accepted.

Important: this feature cannot stop scripts that are hard-coded by another plugin, theme, HFCM snippet, or GTM tag before consent. Non-essential tracking should be moved into the cookie consent script buckets or configured inside GTM to obey Consent Mode. Third-party and HttpOnly cookies cannot be removed by frontend JavaScript, so their originating tracker must be prevented from loading when consent is denied.

The official Tapfiliate plugin's `tapfiliate-js` handle is captured automatically and emitted as inert Marketing-category scripts. This preserves its WooCommerce conversion data while allowing the consent frontend to control when the vendor code executes. Additional registered WordPress script handles can opt into the same mechanism with the `tsol_site_cookie_consent_managed_script_handles` filter.

The existing WPCode Tapfiliate handlers (snippet IDs `102804` and `102816`) are also converted to inert Marketing-category script tags. The IDs can be changed with the `tsol_site_cookie_consent_wpcode_marketing_snippet_ids` filter.

Legacy HFCM snippet `4` (Google), Vimeo snippets `14`, `21`, `26`, `28`, and `37`, RocketChat snippet `24`, and retired Kissmetrics snippet `57` are intercepted at the HFCM render hook. Their script tags are made inert under Marketing or Analytics consent while preserving each snippet's display rules; the ID/category map can be changed with `tsol_site_cookie_consent_hfcm_snippet_categories`.

Server-rendered Vimeo and YouTube iframes are held behind Marketing consent, and Elementor video-lightbox links open the cookie preference dialog when Marketing is not yet allowed. The host/category map can be changed with `tsol_site_cookie_consent_embed_hosts`.

## Library Authentication

Configure the bridge under `TSOL > Library Authentication`. It uses an OAuth-style authorization-code flow with mandatory S256 PKCE, exact callback matching, one-use 60-second hashed codes, five-minute bearer tokens, server-authenticated content-access checks, rate limits, no-store responses, explicit `DONOTCACHEPAGE` and WP Rocket exclusions, and signed, audience-bound, one-time cross-application logout.

The server-authenticated `/wp-json/tsol-library/v1/header-navigation` endpoint
reads the Elementor header menu as an anonymous visitor and returns its safe
public links for the Library Explore menu. Account, login, logout, and
Library-home entries are excluded, so the same Explore menu is available to
signed-in and signed-out visitors.

The plugin also registers a **TSOL Library Footer** menu location under WordPress navigation settings. Its server-authenticated `/wp-json/tsol-library/v1/footer-navigation` endpoint returns up to 12 safe HTTP(S) links in menu order. If no menu is assigned, it returns an empty list and the Library omits the WordPress-managed footer section.

Every authenticated WordPress account can enter and browse the Library. Access to each protected course or lesson is decided from that content's existing MemberPress rules, including drip and expiry behavior; unprotected published content is available to signed-in users. WordPress users with `manage_options` retain full administrator access. The plugin does not maintain a second membership or staff allowlist.

Administrators create named reusable authorization packages under **TSOL
Library → Access Groups** and define the broad Library areas, Collections,
Courses, or Series each package unlocks. A **Library Access Groups** panel on
each MemberPress membership assigns one or more of those packages to the
product. The saved mapping is only a draft: MemberPress remains the runtime
authority. Publishing first
creates inactive native MemberPress rules and compares every current WordPress
user against every current Library authorization target. The operation is
blocked if any current user would lose access, requires the exact confirmation
phrase `publish-access-groups`, detects source-rule changes, and retains a
one-step rollback to the previous native rules. Memberships, subscriptions,
transactions, legacy MemberPress Courses, and non-Library MemberPress rules are
not rewritten by the grouping layer. Every published rule affecting TSOL
Library content must be owned by Access Groups before a new policy can be
checked or published. A guarded reconciliation can absorb separately shipped
TSOL-owned Library rules without changing live access; arbitrary MemberPress
rules are never changed automatically.

The MemberPress Account page includes a **Security** tab for signing the member
out of the standalone Library on every device. The authenticated action uses a
POST request, WordPress nonce, and explicit confirmation before placing the
existing `user.sessions_forced_logout` event in the durable signed revocation
outbox. It revokes Library sessions only; the current WordPress, MemberPress,
or upstream Access login may remain active. A narrow MemberPress view filter
keeps the Security link available when an older child-theme account override
omits the standard custom-navigation hook.

The URL and client ID can be managed in the plugin UI. Development and staging
may use the write-only client-secret option, which never renders the stored
value. Production requires the host-managed `TSOL_LIBRARY_CLIENT_SECRET`
constant and ignores the editable option. `TSOL_LIBRARY_APP_URL` and
`TSOL_LIBRARY_CLIENT_ID` remain optional host overrides. Use separate clients
and secrets for development, staging, and production.

Bridge request limits use an atomic WordPress database table shared across PHP
instances. Valid server traffic is scoped by endpoint/client ID and WordPress
user where applicable instead of only by a common egress address. Invalid
client traffic uses the directly connected address; configure the
`tsol_library_auth_client_ip` filter only when a trusted proxy overwrites and
validates its forwarding header.

Configure `TSOL_LIBRARY_AUTH_REVOCATION_SECRET` only as a host-managed
WordPress constant and use the same dedicated value in the Library secret
manager. It must be at least 32 bytes and must not equal the client or catalogue
webhook secret. Password changes or resets, user deletion, role changes, and relevant
identity changes enter a durable signed outbox that revokes all matching
Library sessions. Suspension and security tooling can request an allowlisted
event after committing its canonical state:

```php
do_action('tsol_library_auth_revocation_requested', $user_id, 'user.suspended');
```

Delivery retries with bounded backoff, maintains a one-minute recovery
watchdog, and treats the Library's `409` replay response as acknowledgement of
a previously applied one-time `jti`.

Run the WordPress contract checks from the plugin directory with:

```bash
wp eval-file tests/library-auth-contract.php --skip-themes
wp eval-file tests/library-auth-revocation-contract.php --skip-themes
wp eval-file tests/library-account-security-contract.php --skip-themes
```

## Library Content

The standalone Library has a dedicated top-level `TSOL Library` admin menu.
Its private `tsol_library_course` and `tsol_library_item` types own the new
editorial structure. Native MemberPress Courses and legacy Pages remain
untouched; MemberPress Rules remains the sole live access authority.

The local clone importer currently produces six draft courses and 144 draft
content records while delegating all 150 access mappings to their unchanged
legacy sources. Watch routes use immutable content UUIDs, kept separate from
authorization pointers. The native Course body is the public **About this
course** source; protected downloads and links belong in lesson Resources.
Every Library/Speaker body sent to the application passes through the shared
strict semantic HTML sanitizer. See `includes/features/library-content/README.md` and
`../plans/tsol-library-content-model.md` for the model and verification record.

## Development

Symlink this directory into a WordPress site's `wp-content/plugins` directory, then activate "Tom's School Of Life Plugin" in WordPress.

## Releases

Dashboard updates are powered by GitHub releases through the bundled Plugin Update Checker library. See `UPDATER_GUIDE.md` before publishing a new version.
