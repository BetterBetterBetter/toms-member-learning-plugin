# TSOL Library Content

This feature is the permanent WordPress back office for the standalone Library.
It is deliberately separate from the legacy MemberPress Courses system.

## Ownership boundary

- WordPress owns canonical Library titles, descriptions, taxonomy, speaker profiles, curriculum,
  media references, resources, status, and stable content UUIDs.
- MemberPress owns live access rules, memberships, drip, and expiry.
- The Next.js Library stores a rebuildable catalogue projection plus its own
  sessions and member-created activity. It does not own editorial or membership
  truth.
- The 124 published legacy `mpcs-course` posts and 23 source Pages remain
  unchanged and continue serving the existing WordPress frontend.

## Catalogue change delivery

Every exportable editorial save is committed to the plugin-owned monotonic
change journal before delivery is attempted. Changes made during one WordPress
request are coalesced into a durable outbox wake-up containing only a delivery
UUID and latest cursor. WordPress signs the exact timestamp and JSON body with
HMAC-SHA-256 and posts it to the fixed Library wake endpoint. Failed attempts
back off from 10 seconds to one hour and remain recoverable; the Library worker
also polls the journal every 60 seconds, so webhook delivery is an acceleration
path rather than a second source of truth.

Configure `TSOL_LIBRARY_CATALOGUE_WEBHOOK_SECRET` as a host-managed WordPress
constant and use the same dedicated 32-byte-or-longer secret in the Library app.
Do not reuse the Library authentication client secret. The Library URL remains
the validated Library Authentication origin. Ensure production WordPress cron
runs at least once per minute in addition to the plugin's immediate cron spawn.
The webhook never contains catalogue, media, member, or MemberPress data and
cannot grant access; the app always pulls the existing protected change feed.

## WordPress model

- `tsol_library_course`: a private, admin-visible course.
- `tsol_library_series`: a private, admin-visible recurring sequence.
- `tsol_library_item`: a private, admin-visible lesson, session, webinar,
  event, call, orientation, book club, or standalone recording.
- `tsol_library_speaker`: a private, admin-visible presenter profile. The post
  title is the full name, the native classic editor owns About, and Featured
  Image owns the headshot. Selecting or uploading an image advances through
  WordPress's native crop screen with a required 1:1 selection; WordPress keeps
  the original and sets a new cropped attachment as the headshot. Job title,
  organisation, website, and repeatable social links are post metadata.
- `tsol_course_collection`: course-only commercial/editorial grouping, exposed
  as a native MemberPress rule target but never as a WordPress frontend route.
- `tsol_topic`: reusable topic vocabulary.

The three content post types and the Speaker profile type are non-public and
unavailable through generic REST routes. Speaker profiles are not MemberPress
rule targets. Course, Series, and Content records receive immutable
`_tsol_library_uuid` values used by watch URLs; Speakers receive separate
immutable profile UUIDs and are embedded in related catalogue records.

WordPress `post_name` is the canonical public slug for Courses, Series,
Content, and Speakers. Because these post types intentionally have no WordPress
frontend, their edit screens provide a dedicated **Library path** control beneath
the title instead of enabling WordPress permalinks. New records derive the slug
from their title, while established slugs change only through that explicit
control. Content previews resolve their path from placement: Course lessons use
`/courses/{courseSlug}/{lessonSlug}`, Series episodes use
`/series/{seriesSlug}/{episodeSlug}`, and standalone Content uses
`/recordings/{recordingSlug}`. Editors change only the final segment; parent
slugs remain owned by the parent record. The hostname is deliberately omitted
because it belongs to deployment configuration, not editorial content. Changing a published slug requires a
redirect review because prior sharing URLs do not redirect automatically.

Course curriculum uses a canonical ordered section registry on the Course in
`_tsol_library_course_sections`. Series uses the equivalent
`_tsol_library_series_groups` registry. Each entry has a stable parent-local
key, title, and contiguous position. Children retain their Course/Series ID,
stable group key, contiguous item position, and compatibility copies of group
title/position. The compatibility fields support migrated records and older
readers; administrators do not edit them directly. Media and resources are
ordered arrays. Administrators provide a stable media URL; server-side
normalization infers Vimeo/YouTube provider identity, Vimeo privacy hash,
WordPress attachment identity, playable kind, and position.

Creating a Course, Series, or Content record in its dedicated post type makes
it Library content automatically. There is no separate editorial inclusion
switch. Technical catalogue export still requires a content type, immutable
UUID, and authorization pointer. The protected projection retains draft,
pending, private, and scheduled records for administrator preview; WordPress
publication status controls whether ordinary members can see them in the
Library application. `_tsol_library_include` remains registered only as a
frozen-import compatibility marker and is not a runtime or editor gate.

Courses and Series store their ordered Speaker relationships as repeated
`_tsol_library_speaker_id` post metadata. Content stores an explicit
`_tsol_library_speaker_mode`: inherit from its saved Course/Series, choose a
direct ordered override, or show no presenter. Inheritance is resolved
dynamically and never copies parent IDs onto the child. Existing Content with
direct IDs remains a direct override; parented Content without a stored mode
defaults compatibly to inheritance. These relationships are editorial
catalogue information only, never copied into a taxonomy and never used to
grant access. Draft Speaker profiles can be assigned while editing but are
omitted from catalogue output until the profile itself is published;
publication still creates no WordPress frontend route.

The native Excerpt is the short member-facing introduction and the native main
editor is the long-form member-facing Description. Both synchronize
automatically for every Library record. The projection strips legacy
players, scripts, shortcodes, and visually empty layout blocks before applying
the WordPress post-HTML allowlist. Description HTML is protected member data:
the Library application selects it only after the live WordPress/MemberPress
access decision succeeds. Structured Media remains the only playback source.

An item can belong to a Course or Series, never both. Series numbers are
rendered from position rather than duplicated in titles. Series own their
member-facing sort direction and singular/plural item labels. `Sessions` is
configured newest-first and uses the preserved WordPress publication date for
each session. The Structure Builder shows that same visitor-facing order and
converts it safely to canonical stored positions on save. Collections never
determine Series membership or order.

## Admin experience

`TSOL Library` is a dedicated top-level menu containing:

- Dashboard
- Courses
- Series
- Content
- Collections
- Topics
- Speakers
- Settings, with capability-aware tabs for:
  - Authentication
  - Import & Legacy
  - Access Overview

TSOL fields, taxonomies, assets, columns, and editor behavior are registered
only on the TSOL content and Speaker post types. Native MemberPress Courses,
Pages, and other WordPress screens are not filtered or extended.

Courses and Series list tables include a `Content` count column. On watch pages,
the content side-panel tab uses the current Course or Series title rather than a
generic curriculum label.

Course Curriculum and Series Episodes metaboxes are compact summaries with
group/content counts and a link to a dedicated full-width Structure Builder.
That shared screen collapses large structures initially, remembers disclosure
state for the browser session, and supports collapse/expand, status/title search,
inline section/group rename, drag ordering, keyboard move buttons, moving a
child between groups under the same parent, empty groups, contextual add links,
explicit save feedback, validation, and stale-tab conflict protection. It
never moves content between different parents or changes an authorization
pointer. When ordering is dirty, child edit links open in a new tab so the
unsaved structure remains available. Individual Content editors expose explicit Standalone/Course/Series
placement plus a registry-backed section/group select; raw position and copied
group-title controls are hidden from normal administration.

Course and Series editors use the visual direct-Speaker relationship picker.
On Content, the same panel first offers `Inherit from parent`, `Choose speakers
for this content`, and `No presenter`. Inherited mode shows the effective
profiles plus a link to the parent editor; the Content list labels effective
parent attribution as `Inherited`, and Speaker-list Content counts include
inheriting children. Direct mode searches private profiles by name, job title,
and organisation, shows headshot and status context, and preserves an explicit
multi-Speaker order. The underlying native multiple select remains the
no-JavaScript fallback for direct mode.

The access panel and compact list column are read-only projections of the live
MemberPress rules. Administrators create and edit access in MemberPress Rules;
there is no second checklist or copied allowlist in TSOL.

New standalone records authorize against themselves. A new lesson assigned to
a course authorizes against its TSOL course; a Series item authorizes against
its Series. The editor recalculates that source whenever the parent changes.
Imported records delegate to their untouched legacy source until a separately
approved transition. Publishing fails closed when no published MemberPress rule
protects the effective authorization source. All runtime access checks resolve
the authorization pointer in WordPress and then ask MemberPress.

The local `library-access-rules` migration owns eight native MemberPress rules:
one Masterclasses Collection rule, five residual Masterclass Course rules, one
Freedom OS Course rule, and one shared Series rule. Staging leaves every legacy
rule and authorization pointer unchanged. Activation is blocked until all TSOL
records are published, the complete matrix passes, and the two known
Course-root inheritance corrections are approved. The current local rehearsal
is activated and recovery-tested; this does not authorize production use.

## Clone-only import

The guarded importer lives in `includes/migrations/library-catalogue-import`.
It creates TSOL-owned drafts only. It never creates or edits MemberPress
Courses, lessons, sections, rules, products, users, transactions, or
subscriptions, and it never changes a legacy source.

The old MemberPress-native pilot/full writers are retained only as historical
source files. Their command is not loaded or registered, so they cannot be run
through WP-CLI after rollback.

The additive local structure migration lives in
`includes/migrations/library-series-import`. It creates six Series drafts,
groups all 121 non-course items, creates the `Masterclasses` Collection
for five courses, and retires the old mixed-content Collections. Its guarded
rollback restores every prior normalized title, relationship field, and retired
term exactly.

## Verification

Run against the local working site with a 512 MB CLI memory limit:

```bash
php -d memory_limit=512M /usr/local/bin/wp tsol library-catalogue-import verify --skip-themes
php -d memory_limit=512M /usr/local/bin/wp tsol library-series-import verify --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-content-tsol-model-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-content-admin-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-url-admin-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-structure-builder-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-speaker-profile-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-content-access-column-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-content-catalogue-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-content-series-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-content-full-access-contract.php --skip-themes
```

Focused browser verification is available from `test/e2e` with
`npm run test:library-structure`; `npm run test:library-full` also exercises the
imported Course and 96-episode Series summaries/builders without changing their
structure.

The locked local inventory is six courses, six Series, 142 content records (21
course lessons and 121 Series items), one Collection
containing five Masterclass courses, zero standalone items, 148 equivalent
source authorization delegations, 154 projected records, and zero legacy or
MemberPress mutations.

## Future AI assistance

Transcript-based metadata suggestions may be added later, but generated values
must remain reviewable suggestions. AI must never grant access, publish content,
or choose preview policy.
