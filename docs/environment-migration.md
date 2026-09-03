# Environment migration: attachment strategy and Liberty invariants

Canonical behaviour of `MemberLibrary_Environment_Migration` for WordPress
uploads referenced by Library records. Package schema stays at `2`; the
attachment entries carry extra optional keys (see
`docs/drift/environment-migration-schema-v2.md`).

## One of three strategies per referenced upload

| Strategy | When (decided at export) | Package entry | Preview on production | Import on production |
|---|---|---|---|---|
| **Bundled** | Not a video and not offloaded, or offloaded but private | `bundled: true`, `sha256` set, file inside the ZIP | Checksum against any existing production file | Extracted, checksum-verified, registered |
| **Referenced** | `video/*` mime type | `bundled: false`, `sha256: ""` | Production must already own the attachment (size verified locally or by HEAD) — otherwise a blocking error | Untouched |
| **Linked** | WP Offload Media (`Media_Library_Item`) holds a public item whose `source_path` equals the upload path | `bundled: false`, `offload: {provider, region, bucket, path, original_path, source_path, original_source_path, objects, url}` | If production already owns the attachment it is "matched"; otherwise the storage URL must answer a HEAD with the expected type and size, else a blocking error | Attachment record + WP Offload Media item created at the same key; no bytes copied |

Invariants:

- The exporter never hashes a video. Hashing 30 GB of Liberty course videos
  cost ~118 s per export for a checksum the importer never used.
- A linked attachment is only created when production has **no** attachment
  for that upload path. Existing production attachments are never re-pointed.
- Rollback of a linked attachment (`action: linked_attachment`) deletes the
  WP Offload Media row first and suppresses
  `as3cf_remove_source_files_from_provider`, so the shared bucket object the
  source site still serves is never deleted. The contract test proves the
  object is still reachable after rollback.
- The resumable file stage (`prepare_attachment_batch`) iterates
  `staged_attachments()` = bundled + linkable entries; `attachment_files` in the
  preview report is that count and drives the progress bar and the
  "preparation incomplete" guard.
- Without WP Offload Media active on the **exporting** site, no `offload`
  block is ever written, so the package behaves exactly like 0.8.1. Without it
  on the **importing** site, a linked entry fails with an explicit message
  instead of a silent broken image.

## Liberty Classroom facts this was built against (2026-09-03)

- 93 referenced uploads: 21 course videos (30.6 GB, plain local uploads, not
  offloaded), 50 images already in bucket `libertyplatform` (us-east-1), 22
  local-only images/PDFs (2.4 MB). Result: 3 MB ZIP, 0.7 s export.
- 2,430 lesson media references are direct S3 URLs stored as `external`
  provider strings; they are copied verbatim and never verified.

## Two Liberty-only import blockers fixed in 0.9.0

1. `MemberLibrary_Access_Groups::assert_memberpress()` required a
   `masterclasses` collection term (a TSOL concept). Liberty has none, so every
   import threw after all records were written, then re-applied the snapshot.
   → docs/errors/2026-09-03-liberty-migration-masterclasses-hardcoded.md
2. Speakers exported `legacy_authorization` from their legacy source-ID meta,
   which pointed at unrelated posts; the Access Groups transition rejected
   them. → docs/errors/2026-09-03-liberty-migration-speaker-authorization.md

Follow-up (not done): make collections fully dynamic (`collection:{slug}` per
term) so the remaining `collection:masterclasses` special cases in
`expand_group_keys`, `compact_assignments`, `imported_group_name`,
`compact_scope_keys` and `rule_target` disappear.

## Resumable import (0.10.0)

The Migration page never posts one long apply request any more. After the
file stage, the page loops `wp_ajax_tsol_library_migration_apply_step`, and
`MemberLibrary_Environment_Migration::apply_step()` performs one bounded unit
of work per call:

| Phase | Work per call | Idempotent on resume |
|---|---|---|
| `prepare` | Preview re-check, rollback snapshot written **before any write** (`partial: true`, with current fingerprints), terms upserted, unchanged UUIDs computed | Reuses an existing partial snapshot for the same `import_hash` instead of re-snapshotting a half-written site |
| `records` | 100 records: upsert or, when the fingerprint matches production, only map UUID → post ID | Yes (UUID lookup) |
| `relations` | 100 records: parent, portable meta, taxonomies, featured image, authorization; unchanged records only contribute their authorization transition | Yes |
| `finalize` | Term meta, homepage curation, Access Groups draft; rollback snapshot marked `partial: false` | Yes |

State (`apply_state`: phase, cursor, `post_ids`, `term_ids`, `transition`,
`unchanged`, `created`) and a timestamped log live in the pending option for
24 hours. A failed step stores the error and `resume_phase`; the page shows
the log, a Resume button, and the partial-rollback card. `created` is synced
into the rollback snapshot after every step so a hard-killed request still
rolls back completely. The single-request `apply()` remains only for the
no-JavaScript fallback and rollback/recovery, and shares the same
`upsert_record` / `link_record` / `finalize_import` helpers.

Invariant: **an unchanged record is never rewritten.** A repeat import of an
identical catalogue performs zero record writes (contract-tested).

## Measured import cost (local Liberty clone, 1280 records)

Single-request happy path before 0.10.0: ~12 s, ~88k queries (~70 per
record), ~24k rows written to `wp_tsol_library_content_changes`, +70 MB peak
memory, every record rewritten. See the 0.10.0 measurement in the changelog
commit for the stepped equivalent.
