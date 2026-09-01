# School announcement audience foundation

Status: Phase 1 audience resolution and the Phase 2 private draft editor are
implemented locally. Publishing, scheduling, self-test delivery, recipient
snapshots, delivery queues, fanout, School notification persistence, email,
and production enablement remain unavailable.

The canonical product and rollout plan is
[`../../../../plans/school-announcements-and-notifications.md`](../../../../plans/school-announcements-and-notifications.md).

## Audience contract v1

The PHP and TypeScript implementations normalize and hash the same JSON object:

```json
{
  "schemaVersion": 1,
  "groups": [
    {
      "all": [
        {
          "type": "CAN_ACCESS_CONTENT",
          "contentUuid": "00000000-0000-4000-8000-000000000000"
        }
      ]
    }
  ],
  "exclude": []
}
```

- Groups are OR; conditions inside one group are AND; exclusions always win.
- The contract accepts one schema version, 1–10 groups, and 1–5 distinct
  condition types per group.
- At most 20 unique membership IDs and 100 unique specific WordPress user IDs
  may occur across the complete definition.
- Conditions are `AUTHENTICATED_SCHOOL_USER`, `CAN_ACCESS_CONTENT`,
  `ACTIVE_MEMBERSHIP`, `ACTIVE_RELATIONSHIP`, and `SPECIFIC_USERS`.
- Only `SPECIFIC_USERS` is accepted as a v1 exclusion.
- `WORDPRESS_ROLE`, arbitrary capabilities, recursive groups, `NOT`, raw query
  arguments, callbacks, unknown fields, string IDs, duplicate condition types,
  and unsupported schema versions fail closed.
- Numeric ID lists are sorted and deduplicated; conditions and OR groups are
  sorted, while duplicate condition types and duplicate groups are rejected.
  A locked cross-language SHA-256 fixture prevents PHP/TypeScript drift.
- Explanations expose counts, content UUIDs, and opaque group tokens only. They
  never include names, emails, logins, membership names, or MemberPress rules.

## Split authority

WordPress evaluates only facts it owns:

- `CAN_ACCESS_CONTENT` calls
  `MemberLibrary_Auth_Entitlements::for_content()` for the selected native Course
  or Series, preserving administrator bypass and live MemberPress behavior.
- `ACTIVE_MEMBERSHIP` calls `MeprUser::active_product_subscriptions()`.
- `SPECIFIC_USERS` compares bounded numeric WordPress IDs.
- School-owned account and relationship conditions pass through as opaque
  group tokens for the School to evaluate.

The School then joins candidates through the unique Better Auth
`tsol-wordpress` account provider. It evaluates active Course enrollment or
Series following and requires `inAppNotifications = true`. It does not copy
MemberPress rules or use email addresses as identity keys.

## Private preview transport

`POST /wp-json/tsol-library/v1/announcement-audience/candidates` is a
server-to-server, no-store endpoint protected by the existing configured
Library client credentials, browser-origin rejection, and atomic rate limits.
Its JSON request is capped at 64 KiB. Each call scans 1–200 users by ascending
WordPress ID and returns only:

- the normalized definition hash;
- cursor and scan counts;
- numeric WordPress IDs that passed the WordPress half;
- opaque matching group tokens;
- exclusion and administrator booleans; and
- an aggregate-safe generation time.

The School consumes all pages, maps only the current page to linked accounts,
and discards candidates after producing aggregate counts. Any authority,
contract, cursor, hash, duplicate, database, or network failure discards all
partial counts and returns an allowlisted unavailable state.

For a local aggregate-only preview:

```bash
npm --prefix ../library run announcement-audience:preview -- \
  --definition-file=scripts/fixtures/announcement-audience-all-linked.json
```

The command outputs counts and a definition hash only. It does not persist a
recipient list.

## Private draft editor

The default-off Phase 2 feature registers `tsol_announcement` as a private,
non-queryable, non-exportable, non-REST post type under **Library >
Announcements**. Editors receive only `edit_tsol_announcements` and may revise
draft copy. Administrators receive the separate audience, preview, publication,
schedule, and delivery-report capabilities. Publication and scheduling remain
server-forced to draft while their independent flag is off.

The guided screen provides bounded subject, summary, semantic body, canonical
Course/Series destination, five audience presets, specific-user exclusions,
local-time expiry, aggregate review, and an identity-minimized bounded audit.
A protected destination always inserts `CAN_ACCESS_CONTENT` for that exact
content UUID; neither UI requests nor stored data can turn a message audience
into access. Editors can see the approved audience wording but cannot change
it or inspect preview/delivery counts.

Destination-dependent presets are disabled until a currently published Course
or Series is selected. Changing any destination, preset, membership, recipient,
or exclusion immediately marks the saved preview stale and disables preview
until the draft is saved again. Membership targeting is capped at 20 published
MemberPress products and explicit users/exclusions at 100 unique identities.
Conditional validation is cleared when the administrator leaves a preset, so a
hidden Membership or Specific Users field cannot block a later save. Visibility
expiry is administrator-owned and cannot be forged by an editor.

WordPress requests aggregate previews from the School through
`POST /api/internal/announcement-audience/preview`. The route uses the existing
server credentials only for this read-only phase, rejects browser origins,
requires exact bounded JSON, compares credentials in constant time, applies a
durable 10-per-minute server rate limit, returns private no-store aggregate
counts only, and exposes no partial results or member identities.

The local authoring and preview flags are separate from the publish and
self-test flags. Production ignores the database option and requires an
explicit host constant for each feature. The self-test button is deliberately
disabled until School notification persistence exists; Phase 2 does not fake a
successful notification.

## Required checks

```bash
php -d memory_limit=512M /usr/local/bin/wp \
  --path=/absolute/path/to/tomschooloflife eval-file \
  /absolute/path/to/plugin/tests/library-announcement-audience-contract.php \
  --skip-themes

php -d memory_limit=512M /usr/local/bin/wp \
  --path=/absolute/path/to/tomschooloflife eval-file \
  /absolute/path/to/plugin/tests/library-announcement-audience-runtime-matrix-contract.php \
  --skip-themes

php -d memory_limit=512M /usr/local/bin/wp \
  --path=/absolute/path/to/tomschooloflife eval-file \
  /absolute/path/to/plugin/tests/library-announcement-admin-contract.php \
  --skip-themes

npm --prefix ../test/e2e run test:library-announcements
```

The runtime matrix emits aggregate counts only. Playwright uses synthetic local
staff accounts and removes its drafts and users. Its admin matrix saves all 13
valid combinations across general, Course, Series, and five presets; proves the
two invalid general/destination combinations are unavailable; exercises real
previews, membership/user bounds, duplicate users, exclusions, expiry,
corrupted definitions, unavailable destinations, editor capabilities,
sanitization, focused accessibility, and responsive layout. Announcement
delivery must remain absent until the later plan phases are separately approved.
