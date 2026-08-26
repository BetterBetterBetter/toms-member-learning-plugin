# TSOL Library Catalogue Import

This is a local-only, clone-only importer from the locked legacy inventory into
the TSOL-owned Library post types.

## Commands

```bash
wp tsol library-catalogue-import preview --skip-themes
wp tsol library-catalogue-import status --skip-themes
wp tsol library-catalogue-import apply import-legacy-content-into-tsol-library-drafts --skip-themes
wp tsol library-catalogue-import verify --skip-themes
wp tsol library-catalogue-import rollback remove-tsol-library-import-drafts --skip-themes
```

Use `php -d memory_limit=512M /usr/local/bin/wp` on this site. Apply and rollback
require their exact confirmation strings and fail outside
`tomschooloflife.test`.

## Safety invariants

- The source manifest fingerprint must be
  `eac2344e9d2cafa392de22bafdb33cf89b0fcbe4a8d820bf9dd2e5a22d0eaab2`.
- Apply starts only with an empty TSOL target model or its own valid state.
- Every created post is a draft and carries importer version/provenance.
- Imported Courses default to direct Speaker attribution; imported Content
  defaults to dynamic Course/Series inheritance without copying Speaker IDs.
- Authorization delegates to the mapped published legacy source when imported.
- Verification compares target counts, immutable legacy-source provenance,
  live legacy rule IDs, MemberPress authority, and legacy source content. It
  also requires every current pointer to match the guarded access-migration
  phase: exact legacy delegation before activation or the exact native
  Course/Series target after activation.
- `_edit_lock` is excluded from the legacy fingerprint because merely opening
  a native WordPress editor changes it; no other legacy metadata is ignored.
- Rollback deletes only IDs recorded in importer state, and stops if a target
  was edited, published, or lost its ownership marker.
- The importer never mutates legacy content or MemberPress authority.

Current local result: six published Courses, 144 published content records,
zero projected mixed Collections, and 150 preserved legacy mappings. The
separately reversible Series structure migration groups all 121 non-course
items into six published Series and creates the course-only `Masterclasses`
Collection for five courses. Native access is active locally and the verifier
passes in that phase; production remains a no-go.
