# Changelog

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
