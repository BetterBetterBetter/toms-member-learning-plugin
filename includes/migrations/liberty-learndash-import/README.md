# Liberty LearnDash migration

This importer converts the authoritative live LearnDash course trees into the
Library content model without editing LearnDash, MemberPress products, or
MemberPress rules. It is intentionally specific to the audited Liberty local
site and creates draft targets only.

Run the read-only inventory check first:

```sh
wp liberty library-import preview
```

The guarded apply confirmation is printed by `wp help liberty library-import
apply`. Apply creates 39 Courses, 1,227 Items, 14 draft Speakers, five
Collections, and three draft Access Groups. Verify compares every target with
the source fingerprint and checks the exact Basic/Basic Plus/Master matrix.

The separately guarded `publish` command is available only after verification.
It publishes importer-owned Speakers, Courses, and Items in dependency order;
it does not activate Access Groups or create MemberPress rules.

Rollback is limited to untouched draft posts created by this importer version.
It never deletes or edits LearnDash source content.
