# Tom's School Of Life Plugin

Dedicated WordPress plugin for Tom's School Of Life.

This plugin is intentionally site-specific. It should contain behavior that belongs to this website and should not be added to the shared Access Platform SSO plugin.

## Dependency

Requires the Access Platform SSO plugin to be installed and active.

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
- `assets/admin/` - Admin page assets.
- `assets/features/accountability-modal/` - Accountability modal assets.
- `plugin-update-checker/` - Bundled GitHub update checker library used for WordPress dashboard updates.
- `UPDATER_GUIDE.md` - Release workflow for dashboard updates.

## Feature Pattern

Each client-specific feature should live in its own folder under `includes/features/{feature-name}/` and implement `TSOL_Site_Feature`.

Feature assets should live in `assets/features/{feature-name}/`. Register the feature in `TomsSchoolOfLifePlugin::register_features()`.

## Accountability Modal

The accountability modal is automatic, not shortcode-based. It renders only on selected singular content locations for logged-in users who are not currently in an accountability group and have not already submitted the intake form. The modal opens after the visitor scrolls down the page.

The intake form stores responses in user meta and lists only published MEC accountability events mapped to Groups children of the accountability parent group. Events marked with `group_full` truthy values are treated as waitlist-only and excluded from the modal choices.

Admins can manually open the modal from the WordPress admin bar while viewing a selected display location.

In-progress answers can be saved locally in the user's browser and are cleared after a successful submission. If a user closes the modal before submitting, a branded square-aspect circular launcher appears in the bottom-right corner so they can reopen and finish the form. The launcher uses the uploaded site-icon mark as a centered white mask and can show a short progress bubble once the user has completed more than one step.

The display locations, scroll threshold, local draft behavior, resume launcher behavior, admin preview button, member/submission hiding rules, modal copy, and question flow can be managed in WordPress under `TSOL > Accountability Modal`.

The `Display Rules` tab includes a searchable content picker with selected-location review rows and filters for content type, publish status, and selected/unselected state.

The `Content` tab includes an ACF-style accordion question repeater. Admins can add, remove, reorder, enable/disable, and require questions. Question keys are generated automatically from the question title. Supported field types are text field, text area, number, number slider, select dropdown, checkbox select, radio select, and the dedicated joinable accountability calls selector.

The `Submissions` admin tab reads the saved user meta in review cards with user actions, intake answers, selected call availability, and recommended group fits. It supports search, status/call/date filters, sorting, pagination, CSV export for either all submissions or the current filtered/sorted result set, and nonce-protected deletion of a user's saved intake submission. Recommendations are availability-based for now; they do not auto-enroll users.

Accessibility support includes dialog labeling, described-by copy, Escape close, focus trapping, focus return, keyboard-accessible launcher, progressbar semantics, live status messages, and reduced-motion handling for launcher animation. It should still receive real screen-reader/browser QA before being treated as formally audited for WCAG conformance.

The target content IDs can still be changed with the `tsol_site_accountability_modal_page` filter.

## Development

Symlink this directory into a WordPress site's `wp-content/plugins` directory, then activate "Tom's School Of Life Plugin" in WordPress.

## Releases

Dashboard updates are powered by GitHub releases through the bundled Plugin Update Checker library. See `UPDATER_GUIDE.md` before publishing a new version.
