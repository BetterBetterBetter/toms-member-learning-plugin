# Course body publication migration

Catalogue schema `20260813.3` designates the native Course body as public
**About this course** copy. Three legacy imported Course bodies contained only
member resources, and one retired metadata field duplicated the new public
source. This guarded migration must run before that schema is synchronized.

It resolves records by immutable migration key, verifies that each destination
lesson still belongs to its Course, privately archives every original body and
resource array, appends resources without duplicate URLs, clears only the three
resource-only bodies, and archives/removes the retired duplicate description.
It does not alter access rules, authorization pointers, media, or public status.

Run locally or in the intended environment from the WordPress root:

```bash
php -d memory_limit=512M /usr/local/bin/wp tsol library-course-body-publication status --skip-themes
php -d memory_limit=512M /usr/local/bin/wp tsol library-course-body-publication apply \
  --confirm=publish-native-course-bodies-and-move-protected-resources \
  --skip-themes
php -d memory_limit=512M /usr/local/bin/wp tsol library-course-body-publication verify --skip-themes
```

Only after `verify` passes should the Library run a full catalogue sync for
schema `20260813.3`. The private archives are intentionally retained so an
operator can inspect the pre-migration values without exposing them through
the application.
