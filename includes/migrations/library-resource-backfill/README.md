# Legacy resource-link backfill

The original catalogue importer recognized only PDF, Office, and ZIP URLs.
This guarded local migration audits every mapped legacy MemberPress lesson and
Course body, treats every user-facing web or email hyperlink as a Resource, and
keeps playable video/audio in the separate Media field. Course-body links are
placed on the Course's first lesson because protected Resources belong to
content items, not public Course descriptions.

Existing Resources and their editorial labels are preserved. Missing URLs are
appended, duplicate URLs are ignored, and every changed Resource array is
archived in post metadata before the write.

```bash
wp tsol library-resource-backfill preview --skip-themes
wp tsol library-resource-backfill apply \
  --confirm=backfill-all-legacy-body-links-as-resources --skip-themes
wp tsol library-resource-backfill verify --skip-themes
```
