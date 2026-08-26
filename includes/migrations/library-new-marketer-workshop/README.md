# New Marketer Workshop additive import

This local-only migration adds the legacy **The New Marketer Workshop** bonus
page to the TSOL-owned School catalogue without changing the legacy page or its
custom-URI MemberPress rule.

It creates:

- one published `tsol_library_course` with seven ordered editorial sections;
- 52 ordered published `tsol_library_item` lessons, each with one Vimeo asset;
- one published MemberPress `single_tsol_library_course` rule;
- an authorization pointer from the Course and all lessons to that Course.

A separate guarded forward editorial migration replaces the legacy or
transcript-derived lesson labels with the reviewed canonical 52-title list,
regenerates every lesson slug from its canonical title, attaches a
SHA-256-pinned 16:9 video thumbnail derived from the School's original 2023
portrait artwork without the surrounding product-device mockup, and
creates and assigns a complete Charles Terrence Harper speaker profile. That
profile includes a SHA-256-pinned opaque headshot derived only from the verified
first-party publisher portrait, professional biography, credentials, website,
and verified LinkedIn profile. Redirects are
intentionally omitted because the School application is not live.

The native rule copies all 28 `membership is` conditions from legacy rule
`100171`. The source page, source rule, lesson count, Vimeo identities, and
access conditions are protected by fingerprints that match both the local site
and the read-only production clone. A mismatch stops before writes.

The migration is deliberately separate from the locked 2026-08-09 catalogue
import and 2026-08-10 access migration. Those historical verifiers continue to
cover their original 156 records and eight rules; this module owns and verifies
its additional 53 records and one rule.

## Commands

Run from the local WordPress root with a 512 MB PHP memory limit:

```sh
php -d memory_limit=512M $(command -v wp) tsol library-new-marketer-workshop preview --skip-themes
php -d memory_limit=512M $(command -v wp) tsol library-new-marketer-workshop apply --confirm=import-new-marketer-workshop-with-exact-legacy-access --skip-themes
php -d memory_limit=512M $(command -v wp) tsol library-new-marketer-workshop verify --skip-themes
php -d memory_limit=512M $(command -v wp) eval-file /Users/ryan/Projects/tomwoods/tsol/plugin/tests/library-new-marketer-workshop-contract.php --skip-themes
```

Sites that already contain the original flat import can apply the guarded
forward-only structure migration. It verifies the legacy page, the legacy
rule, all 52 owned lesson titles/media/relationships, and the old flat
curriculum before changing only the Course registry and each lesson's section
and local position:

```sh
php -d memory_limit=512M $(command -v wp) tsol library-new-marketer-workshop restructure --confirm=split-new-marketer-workshop-into-seven-sections --skip-themes
```

Apply the reviewed canonical titles, slugs, flat artwork, and speaker profile
after restructuring:

```sh
php -d memory_limit=512M $(command -v wp) tsol library-new-marketer-workshop editorialize --confirm=apply-canonical-new-marketer-workshop-titles-slugs-and-thumbnail --skip-themes
```

Rollback is available only while all importer-owned targets remain byte-for-byte
unchanged. It permanently removes only the 53 importer-owned records and its
one importer-owned rule; it never removes or edits the legacy page or rule:

```sh
php -d memory_limit=512M $(command -v wp) tsol library-new-marketer-workshop rollback --confirm=remove-unchanged-new-marketer-workshop-import --skip-themes
```

Production execution remains a separate approved maintenance operation. The
host guard permits writes only on `tomschooloflife.test`.
