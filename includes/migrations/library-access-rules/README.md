# TSOL Library MemberPress rule translation

This local-only migration translates the legacy rule outcomes into native
MemberPress targets for the private TSOL Library model. It never edits or
deletes a legacy rule.

The normalized policy is intentionally small:

- one Collection rule for the access shared by all five Masterclasses;
- one residual single-Course rule per Masterclass for standalone purchases;
- one single-Course rule for Freedom OS;
- one shared rule for all six Series;
- lessons inherit their Course and sessions inherit their Series.

Staging creates eight **draft** MemberPress rules. Draft rules do not participate
in MemberPress authorization, and every TSOL record continues delegating to its
legacy source. Verification evaluates every WordPress user without emitting an
identity and rejects any allow-to-deny transition.

Activation is deliberately separate. It fails closed until every TSOL target is
published and the two known Course-root inheritance corrections are explicitly
approved. Activation then publishes only the migration-owned rules and changes
only TSOL authorization pointers. Rollback restores every recorded pointer and
removes only rules carrying the migration ownership marker.

```bash
wp tsol library-access-rules preview
wp tsol library-access-rules stage --confirm=stage-tsol-library-memberpress-rules
wp tsol library-access-rules verify
wp tsol library-access-rules activate \
  --confirm=activate-tsol-library-memberpress-rules \
  --approve-differences=approve-course-root-inheritance-corrections
wp tsol library-access-rules rollback --confirm=rollback-tsol-library-memberpress-rules

php -d memory_limit=1G /usr/local/bin/wp eval-file \
  /absolute/plugin/tests/library-access-rules-runtime-matrix-contract.php \
  --skip-themes
```

The Course-root differences are:

- Social Media standalone purchasers currently reach lessons but not the
  landing page. Parent inheritance grants them the complete Course.
- The AI Advantage child rule contains one member-specific exception that is
  absent from the landing rule. Parent inheritance places that exception on the
  complete Course.

Neither correction is active while the migration is staged.

## Current local rehearsal

On 2026-08-26, all 156 normalized records were published locally and the eight
migration-owned rules were activated after explicit approval. The complete
5,273-user matrix evaluated 812,042 decisions with zero allow-to-deny and 18
approved Social Media Course-root deny-to-allow transitions. Exact rollback to
legacy pointers and prior content statuses passed, as did restaging,
republication, and reactivation. The 22 legacy rules remain published and
unchanged. Production remains a no-go pending its own snapshot, matrix,
authenticated browser QA, and approval.

The runtime-matrix contract evaluates MemberPress's real legacy decision and
the staged native conditions for every local WordPress user and all 156 TSOL
targets. It reports aggregate transition counts only and fails if an existing
allow becomes a deny, an administrator is denied, a WordPress-only non-admin is
granted protected content, or the person-specific exception disappears.
