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

## Environment migration

**TSOL Library → Migration** exports and imports only this WordPress-owned
Library model and its portable Access Groups configuration. Relationships use
content UUIDs, membership assignments use MemberPress product slugs, and media
uses upload-path references; binary files are not bundled. The standalone app
database and member-created app data are outside the package boundary. Imports
are previewed, conflict-blocking, exact-confirmation protected, rollbackable,
and leave Access Groups unpublished until their production access matrix is
separately verified.

## Catalogue change delivery

Every exportable editorial save is committed to the plugin-owned monotonic
change journal before delivery is attempted. Changes made during one WordPress
request are coalesced into a durable outbox wake-up containing only a delivery
UUID and latest cursor. WordPress signs the exact timestamp and JSON body with
HMAC-SHA-256 and posts it to the fixed Library wake endpoint. Failed attempts
back off from 10 seconds to one hour and remain recoverable; the Library worker
also polls the journal every 60 seconds, so webhook delivery is an acceleration
path rather than a second source of truth.

Normal browser saves send a non-blocking wake immediately after the outbox row
is committed. A separate recurring one-minute watchdog confirms deliveries and
recovers work even if a one-off WordPress cron event is consumed during a
failure. Post create/update/status/delete, projected metadata, relationships,
Speaker changes, curriculum changes, and assigned Collection or Topic
create/rename/delete events all advance affected catalogue records. Creating an
unused term does not advance the journal because it changes no projected page.

Configure `TSOL_LIBRARY_CATALOGUE_WEBHOOK_SECRET` as a host-managed WordPress
constant and use the same dedicated 32-byte-or-longer secret in the Library app.
Do not reuse the Library authentication client secret. The Library URL remains
the validated Library Authentication origin. Ensure production WordPress cron
runs at least once per minute in addition to the plugin's immediate cron spawn.
The webhook never contains catalogue, media, member, or MemberPress data and
cannot grant access; the app always pulls the existing protected change feed.
Administrators can compare the WordPress and School cursors, pending deliveries,
retry state, last confirmed delivery, worker state, and schema under **TSOL
Library → Settings → Sync Status**. The same local delivery assessment is
registered in WordPress Site Health. Its signed status request exposes only
allowlisted operational state and is private and `no-store`.

## WordPress model

- `tsol_library_course`: a private, admin-visible course.
- `tsol_library_series`: a private, admin-visible recurring sequence.
- `tsol_library_item`: a private, admin-visible lesson, session, webinar,
  event, call, orientation, book club, or standalone recording.
- `tsol_library_speaker`: a private, admin-visible presenter profile. The post
  title is the full name, the native Excerpt owns the plain-text Short bio, the
  native classic editor owns About, and Featured Image owns the headshot. The
  Short bio is the preferred Course-instructor summary; when it is empty,
  catalogue schema `20260821.2` projects a 50-word plain-text fallback from the
  sanitized About body. Selecting or uploading an image advances through
  WordPress's native crop screen with a required 1:1 selection; WordPress keeps
  the original and sets a new cropped attachment as the headshot. Job title,
  organisation, website, and repeatable social links are post metadata.
- `tsol_course_collection`: course-only commercial/editorial grouping, exposed
  as a native MemberPress rule target but never as a WordPress frontend route.
  Its native Description owns the short public introduction; term metadata owns
  a sanitized long-form overview, optional hero artwork, and optional featured
  assigned Course for the Library Collection landing page.
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

Record classification is structural rather than an editorial choice. Course
and Series derive their catalogue type from their post type; Content derives a
Course lesson from Course placement and otherwise emits the recording
compatibility value while Series context identifies an episode. The protected
catalogue no longer requires stored content-type metadata to export a valid
record.

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
switch. Technical catalogue export requires an immutable UUID and authorization
pointer. The protected projection retains draft,
pending, private, and scheduled records for administrator preview; WordPress
publication status controls whether ordinary members can see them in the
Library application. `_tsol_library_include` remains registered only as a
frozen-import compatibility marker and is not a runtime or editor gate.

Content playback availability is separate from WordPress publication status.
`available` is the default; `coming_soon` lets a published lesson or episode
appear in its parent structure while every catalogue reference remains
non-interactive. Direct Library watch requests return to the public parent, and
protected video features remain unavailable to members and administrators. It
may carry an optional date-only release date. For compatibility with the
catalogue timestamp contract, WordPress stores the start of the selected date
canonically in UTC and the Library renders only the calendar date. The date is
informational and does not schedule an unlock. Administrators must attach
stable media and manually change the record to `available`; a passed date
remains coming soon and is
called out as overdue in the Content editor and Structure Builder. Publishing
an available Content record requires normalized media, while an authorized
coming-soon child may be published before media is attached.

The catalogue projects `availability` and nullable RFC 3339 `release_at`.
Courses and Series derive **Coming soon** when every published child is coming
soon and **Currently releasing** when published available and upcoming
children are mixed. The derived rollout is display-only and never changes an
authorization pointer, MemberPress Rule, taxonomy, or parent publication
status.

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

The native Excerpt is the short member-facing introduction. The native body is
the long-form Description for Series and Content, the public **About this
course** source for Courses, and the public preview description for Lessons. It
synchronizes automatically; Course and Lesson records project the same
sanitized value into `overview_html` and the explicitly public
`public_description_html` field, while public application queries select only
the latter. Series, Episode, and standalone Recording Description HTML remains
protected member data and is selected only after the live WordPress/MemberPress
access decision.
Structured Media remains the only playback source.

Course, Series, and Content Excerpt editors show a live **160 recommended**
character count because the same text is used for compact cards, page
introductions, sharing, and preferred search descriptions. This is deliberately
advisory: WordPress does not truncate or reject longer editorial copy. Speaker
Short bio uses the same advisory count but a compact-display warning because it
is an instructor summary, not the Speaker profile's current search description.

All native WYSIWYG bodies on Course, Series, Content, and Speaker records cross
one strict semantic HTML boundary both before WordPress stores a new edit and
again during catalogue export. The allowlist is deliberately small:

- paragraphs and line breaks;
- H2–H4 headings (`h1` becomes `h2`; H5/H6 become `h4`);
- ordered/unordered lists and list items;
- strong/bold and emphasis/italic text;
- block quotes; and
- HTTP(S), `mailto:`, safe root-relative, or page-fragment links with only
  `href` and optional `title`. Schemeless domain names are normalized to HTTPS.

The sanitizer removes pasted `style`, `class`, `id`, `data-*`, and event
attributes; layout wrappers; images; forms; embedded/executable elements and
their content; block-editor comments; shortcodes; unsafe/relative link
destinations; and visually empty blocks. Application CSS therefore owns all
presentation. Never widen this allowlist merely to preserve copied styling;
add an intentional semantic application component instead.

The dedicated **Course landing page** panel now owns only the ordered **What
you’ll learn** outcomes. Its native WordPress-style builder exposes an editable
title and optional supporting sentence, plus working drag, keyboard move, add,
and remove controls. WordPress combines each row as `Title — supporting text`
so the catalogue remains an ordered plain-text array and existing projected
data needs no migration. Empty outcomes are ignored, duplicates are removed,
and the public learning section is omitted until at least one outcome is saved.
Downloads, bonus links, passwords, and member-only instructions must be added
to a lesson’s structured Resources panel, never a Course or Lesson body.

Before catalogue schema `20260813.3` is synchronized in an environment that
contains the legacy imports, run the guarded `tsol
library-course-body-publication apply` command. It privately archives the three
resource-only Course bodies, moves their usable links into the first protected
lesson, and archives/removes the retired duplicate public-description metadata.
Its underscore-prefixed archives are migration recovery data and are never
part of the catalogue contract.

`published_at` is the WordPress publication date. `last_updated_at` is
automatic: Content uses its own WordPress modified time, while a Course or
Series uses the newest of its own modified time and its published children.
Draft children cannot change the public parent date. Child saves and deletes
journal the affected parent so the rebuildable projection can refresh the
aggregate without polling every record. Editors do not maintain a second date
field manually.

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
- Homepage
- Settings, with capability-aware tabs for:
  - Authentication
  - Sync Status
  - Access

The completed catalogue import and legacy transition are no longer exposed as
everyday browser settings. Their guarded WP-CLI verification and rollback
commands remain available for recovery work.

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

Course editors have no generic Details box. Series editors have a focused
`Series settings` box, and Content editors have a focused `Library placement`
box. Record type, parent display position, and the former per-record Featured
checkbox are not editorial controls. The dedicated Homepage screen owns four
ordered Course/Series rails—Featured, Courses, Masterclasses, and Series—with
pointer drag, Up/Down controls, rail selects, search, and explicit save.
Rail choices are type-safe: Series can use Featured/Series, Masterclasses can
use Featured/Masterclasses, and ordinary Courses can use Featured/Courses.
Stale-tab protection rejects an overwrite when another administrator saved a
new layout after the current screen loaded.

Editor panels follow the corresponding Library reading order where there is a
clear rendered counterpart. Courses use Excerpt, What you’ll learn, Curriculum,
then About this course. Content keeps non-rendered Placement separate, followed
by Media, Excerpt, Description, and Resources. Series uses Excerpt, Series
settings, Episodes, then its Description; the Description remains last because
it has no current public Series-page block. Speaker identity/details precede
the About editor. Access, import provenance, taxonomy, publication, and featured
image controls remain in the side column because they are workflow context, not
linear page sections.
Drafts may be arranged but remain invisible to ordinary visitors until
published. Homepage placement never grants access and never changes search
eligibility. Catalogue schema `20260813.3` exposes this as nullable `homepage`
placement metadata and retains `featured: false` only for compatibility with
the current projection.

Course and Series editors use the visual direct-Speaker relationship picker.
On Content, the same panel first offers `Inherit from parent`, `Choose speakers
for this content`, and `No presenter`. Inherited mode shows the effective
profiles plus a link to the parent editor; the Content list labels effective
parent attribution as `Inherited`, and Speaker-list Content counts include
inheriting children. Direct mode searches private profiles by name, job title,
and organisation, shows headshot and status context, and preserves an explicit
multi-Speaker order. The underlying native multiple select remains the
no-JavaScript fallback for direct mode.

The access panel and compact list column remain read-only projections of the
live MemberPress rules. Administrators standardize membership access under
**TSOL Library → Access Groups** rather than maintaining a growing set of
membership conditions by hand. Administrators create and name only the
packages they need, then select the broad Library areas, Collections, Courses,
or Series each package unlocks. A **Library Access Groups** panel on every
MemberPress membership assigns one or more packages. New memberships are
explicitly unassigned until an administrator makes that choice.

Access Groups are a management/compiler layer, not a second runtime authority.
Saving changes creates a versioned draft only. Staging creates inactive native
MemberPress rules, verifies their structure, and compares every current user
against every current Library authorization target. Every published rule that
affects Library content must be owned by the Access Groups baseline;
publication is blocked while any unmanaged Library rule remains. A guarded
reconciliation can bring separately shipped TSOL-owned rules into the draft,
but arbitrary MemberPress rules are never modified automatically. Publication
is also blocked on any allow-to-deny change, requires the exact
`publish-access-groups` phrase, and swaps the complete managed Library rule set.
Rollback republishes the prior rules and deletes the generated set.
Non-membership member/role/capability exceptions found in the imported rules
are preserved. Timed or otherwise unsupported rules fail closed during import
rather than being approximated.

New standalone records authorize against themselves. A new lesson assigned to
a course authorizes against its TSOL course; a Series item authorizes against
its Series. The editor recalculates that source whenever the parent changes.
Imported records delegate to their untouched legacy source until a separately
approved transition. Publishing fails closed when no published MemberPress rule
protects the effective authorization source. All runtime access checks resolve
the authorization pointer in WordPress and then ask MemberPress.

The local `library-access-rules` migration owns eight native MemberPress rules:
one Masterclasses Collection rule, five residual Masterclass Course rules, one
Freedom OS Course rule, and one shared Series rule. The separately shipped New
Marketer Workshop rule is reconciled as the ninth Access Groups baseline rule.
Staging leaves every legacy
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
php -d memory_limit=512M /usr/local/bin/wp tsol library-course-body-publication verify --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-content-tsol-model-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-content-html-sanitizer-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-content-admin-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-url-admin-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-structure-builder-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-speaker-profile-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-content-access-column-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-content-catalogue-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-content-series-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-content-full-access-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-access-groups-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-access-groups-active-edit-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-access-groups-membership-assignment-contract.php --skip-themes
php -d memory_limit=512M /usr/local/bin/wp eval-file /absolute/plugin/tests/library-access-groups-definition-contract.php --skip-themes
```

Focused browser verification is available from `test/e2e` with
`npm run test:library-structure` and `npm run test:library-homepage`;
`npm run test:library-full` also exercises the
imported Course and 96-episode Series summaries/builders without changing their
structure.

The current local inventory is seven courses, six Series, 194 content records
(73 course lessons and 121 Series items), one Collection containing five
Masterclass courses, zero standalone items, and 207 projected records. The
original guarded import retains its locked 150 equivalent source authorization
delegations and 156 owned records; the additive New Marketer Workshop module
owns the remaining Course and 52 lessons.

## Future AI assistance

Transcript-based metadata suggestions may be added later, but generated values
must remain reviewable suggestions. AI must never grant access, publish content,
or choose preview policy.
