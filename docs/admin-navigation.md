# Library admin navigation and Dashboard

## Menu order (single source: `MemberLibrary_Admin_Navigation::order_submenu()`)

Content → Curation → Access → System:

1. Dashboard · 2. Courses · 3. Series · 4. Content · 5. Speakers ·
6. Collections · 7. Topics · 8. Homepage · 9. Announcements (when enabled) ·
10. Access Groups · 11. Settings (Authentication, Sync Status, Access) ·
12. Migration. Structure Builder is contextual (hidden from the menu, opened
from a Course or Series). Items registered by other code keep their relative
order at the end. Adding a Library page means adding its slug to that list.

## Dashboard contract

`render_dashboard()` shows one card per subsystem from `dashboard_cards()`.
Every card has the same four fields: `state` (one of live, draft, review,
attention, off, ok), `badge`, `detail`, `action` + `url`. Rules:

- The card must answer "is it live?" without opening the page.
- Detail is one sentence with at most one number.
- Nothing on the Dashboard performs a remote request: the app connection card
  uses `MemberLibrary_Catalogue_Sync_Status::summary()`, which reads the local
  outbox only.
- Vocabulary is Live, Draft, Review, Publish, Needs attention. Internal phase
  names (staged, bootstrapped, cursor, outbox) never appear.

Shared chips: `.tsol-status-chip--{live|ok|draft|review|attention|off}` in
`library-content-admin.css`, loaded on every Library screen.

Contract: `tests/library-admin-navigation-contract.php`.
