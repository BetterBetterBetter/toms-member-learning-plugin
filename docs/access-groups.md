# Access Groups: states, invariants, and the published snapshot

Canonical behaviour of `MemberLibrary_Access_Groups` and its admin page.

## Three states an administrator sees

| State | Meaning | Where it lives |
|---|---|---|
| **Live** | The groups and membership assignments members are governed by now. | `configuration['published']` snapshot (`groups`, `assignments`, `exceptions`, `revision`, `activated_at`), written by `activate()`. |
| **Draft** | The saved configuration (`groups`, `assignments`). Saving never changes member access. | `tsol_library_access_groups` option. |
| **Review** | Generated MemberPress rules exist but are unpublished; the access comparison has run. | `tsol_library_access_groups_stage` option, phase `staged` (`staging`/`failed` = review did not finish). |

`changes_since_publish()` diffs Draft against Live and is the single source for
the "N changes not yet live" heading, the change list, the per-group badges
(`group_states()`: live / changed / new / draft), and the membership editor's
"Draft differs from live" note.

## Review baseline ("what members have today")

For each Library item the review compares old vs new access for every user:

1. Published MemberPress rules on the item's authorization post → `old` =
   those rule conditions (TSOL model).
2. No such rule and the authorization post is a LearnDash course → `old` =
   LearnDash enrolment (`learndash_get_users_for_course`), with `open`/`free`
   price types meaning everyone. This is the Liberty Classroom model, where the
   MemberPress → LearnDash integration enrols members and no MemberPress rule
   exists. Without it every non-member counts as "losing access".
3. Neither → open to everyone.

`matrix.baseline_sources` records how many items used each source;
`matrix.losing_users`, `losses_by_membership`, and `losing_sample` explain a
block in people, not combinations.

## One-step publish

`publish()` = `stage()` then `activate()` only when `allow_to_deny` is 0.
Otherwise the review stays `staged` with the block explanation; the admin
chooses "Back to editing". "Preview the check first" runs `stage()` alone.

## Invariants

- **Publishing is blocked server-side** when the review matrix shows any
  current member losing access (`allow_to_deny > 0`). The UI disables the
  button, the service throws regardless of UI.
- **No typed phrase.** The form posts `ACTIVATE_CONFIRMATION` itself; the
  browser asks with a plain confirmation dialog. The constant remains a
  server-side guard against accidental posts, not a UX step.
- **Snapshot lifecycle.** `activate()` records the snapshot. Editing a live
  configuration keeps it (installs that went live before the field existed get
  it backfilled from the live configuration on first edit). `rollback()` of a
  live publish removes it (nothing from this configuration is live any more);
  discarding a review keeps it.
- **Editing is paused during Review.** The group form is disabled while a
  review awaits Publish or Back to editing, so what was reviewed is what gets
  published.
- **Vocabulary.** Administrators only ever see Live, Draft, Review, Publish,
  Undo, Back to editing. The stage/staged/active phase names are internal.

## Brand independence

Scopes come from `definitions()` (Entire Library, All Series, each Course and
Series, and a collection scope only when the brand has that term). No
precondition may name a brand-specific collection
(docs/errors/2026-09-03-liberty-migration-masterclasses-hardcoded.md).

Contract: `tests/library-access-groups-publish-state-contract.php`.
