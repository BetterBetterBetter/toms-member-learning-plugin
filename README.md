# Member Library Platform

WordPress plugin providing the member **Library** back office and the
authentication/entitlement/catalogue bridge consumed by the Next.js Library
app. One canonical multi-brand core; brand differences are per-install
configuration, not forks. It powers Tom's School of Life and Liberty
Classroom (and future brands).

See the workspace consolidation record in
`docs/plans/plugin-consolidation-plan.md` and ADR
`docs/decisions/0001-one-canonical-plugin-brand-via-config.md`.

## What this plugin is (and is not)

- **Is:** the Library content model (courses, series, items, speakers,
  collections), the WordPress↔app OAuth bridge (`library-auth`), the catalogue
  projection + signed webhooks, native MemberPress access groups, member
  announcements, and the active Liberty LearnDash importer.
- **Is not:** site-specific features. TSOL-only features (accountability
  modal, cookie consent) and TSOL's legacy one-shot data migrations live in
  the separate **TSOL companion plugin** (`tomschooloflife.com/plugin`).

## Brand configuration

Brand values resolve **PHP constant → wp_option → default** via
`MemberLibrary_Brand`; defaults preserve historical TSOL behavior, so an
unconfigured install is unchanged. A brand (e.g. Liberty) sets constants in
`wp-config.php`:

- `TSOL_LIBRARY_BRAND_NAME`, `TSOL_LIBRARY_BRAND_LIBRARY_MENU_LABEL`
- `TSOL_LIBRARY_BRAND_LOGO_URL` (auth interstitial; its host also drives the CSP)
- `TSOL_LIBRARY_BRAND_CLIENT_ID`

## Machine identifiers are a frozen contract

The REST namespace `tsol-library/v1`, all lowercase `tsol_*` hooks/options/
meta/cron/admin-post actions, the `tsol_library_*` CPT and taxonomy slugs, the
`tsol_library_catalogue_webhook_outbox` table, the `X-TSOL-*` headers, and the
`TSOL_LIBRARY_*` wp-config constants are a cross-brand compatibility contract
consumed literally by the app and stored in every site's database. They are
**not branding** and are never renamed (see
`library/docs/architecture/wordpress-plugin-contract.md`). The plugin's
internal PHP symbols use the `MemberLibrary_` prefix; the text domain is
`member-library`.

## Optional Access SSO integration

Access Platform SSO is an optional upstream WordPress login source. The
Library authentication bridge still loads when it is absent. MemberPress, not
Access SSO, is the protected-content authorization authority. Recognized
basenames: `wp-access-sso/access-platform-sso.php`,
`wp-access-sso-1.1.3/access-platform-sso.php`,
`access-platform-sso/access-platform-sso.php`.

## Structure

- `member-library-plugin.php` — entrypoint: constants, file loading, activation.
- `includes/class-plugin.php` — `Member_Library_Plugin` loader + feature registration.
- `includes/class-brand.php` — `MemberLibrary_Brand` per-install brand config.
- `includes/features/library-auth/` — OAuth bridge, tokens, revocation, account security.
- `includes/features/library-content/` — content model, catalogue, webhook, access groups, environment migration.
- `includes/features/library-notifications/` — member announcements.
- `includes/migrations/liberty-learndash-import/` — Liberty LearnDash import (CLI, host-guarded).
- `tests/` — WP-CLI contract scripts (run via `tools/run-contract-tests.sh`).

## Testing

The tests are WP-CLI contract scripts, not PHPUnit:

```
tools/run-contract-tests.sh --path=/absolute/path/to/wordpress
```

Each also runs standalone: `wp eval-file tests/<name>.php --skip-themes`.

## Releases

See `RELEASE.md`. CI (`.github/workflows/ci.yml`) lints PHP on every push;
`release.yml` builds a versioned ZIP on a `vX.Y.Z` tag.
