# Library Series and Collections migration

This local-only, reversible migration groups all 121 non-course content drafts
into six private Series: Sessions, Live Events, Unconference 2025, New Member
Orientation, Limitless Book Club, and Member Calls. Sessions and
Live Events use year groups; every item has an explicit, contiguous position.

It also creates the course-only `Masterclasses` Collection and assigns
the five imported Masterclass courses. Freedom OS remains an ordinary course.
Collections are native MemberPress rule targets, but this migration does
not create or edit a MemberPress rule.

It does not edit legacy posts, MemberPress Courses, MemberPress Rules, access
conditions, products, transactions, subscriptions, media, or resources. The
normalized draft titles lose redundant Series prefixes where the name and
position already express them. The retired mixed-content `tsol_collection`
terms are removed. Rollback restores the exact prior titles, relationship
metadata, and retired terms.

```sh
wp tsol library-series-import preview
wp tsol library-series-import apply --confirm=group-normalized-library-items-into-series
wp tsol library-series-import verify
wp tsol library-series-import rollback --confirm=remove-normalized-series-structure
```

The command refuses to mutate any host other than `tomschooloflife.test`.
Verification is phase-aware for authorization pointers and continues to guard
the exact imported identities, groups, positions, labels, and ongoing state.
Publication status is now independent editorial state: published, draft,
pending, private, and scheduled records remain reviewable without weakening
the migration-owned structure checks. No production transition is authorized.
