# Local Library publication rehearsal

This command publishes only the 156 normalized TSOL Course, Series, and Content
records on `tomschooloflife.test`. It records every prior status and can restore
those statuses after native access has been rolled back.

It never publishes or edits a legacy source, MemberPress Course, MemberPress
Rule, membership, user, transaction, or subscription. It refuses to run unless
the native TSOL rules are staged, every target passes hard readiness checks, and
the exact local confirmation is supplied.

```bash
wp tsol library-publication-rehearsal preview
wp tsol library-publication-rehearsal publish \
  --confirm=publish-local-tsol-library-rehearsal
wp tsol library-publication-rehearsal verify

# Roll native access back first, then:
wp tsol library-publication-rehearsal restore \
  --confirm=restore-local-tsol-library-statuses
```

Production is hard-blocked by hostname.
