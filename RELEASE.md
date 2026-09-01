# Release Process

Canonical repo: `BetterBetterBetter/toms-member-learning-plugin` (private).

One plugin core serves both brands (Tom's School of Life, Liberty Classroom);
brand differences are per-install config (`TSOL_Library_Brand`), never separate
builds. See `docs/plans/plugin-consolidation-plan.md` in the workspace.

## Cut a release

1. Bump the version in **two** places to the same value:
   - the `Version:` header in `tomschooloflife-plugin.php`
   - `define('TSOL_SITE_PLUGIN_VERSION', ...)`
2. Add a `CHANGELOG.md` entry.
3. Commit, then tag `vX.Y.Z` and push the tag.
4. CI (`.github/workflows/release.yml`) verifies the tag matches both version
   strings, builds `member-library-plugin.zip` (top-level dir
   `member-library-plugin/`, excluding `.git`, `.github`, `tests`, `tools`,
   `*.md`), and attaches it to the GitHub Release.

## Update channel — NOT yet live

The in-plugin update checker (`plugin-update-checker`, wired in
`tomschooloflife-plugin.php`) points at this repo, but the repo is **private**,
so the checker cannot fetch releases without authentication. Before automated
updates work, EITHER:

- flip the repo to **public** (tokenless, like the legacy TSOL plugin did), OR
- keep it private and configure a GitHub token via PUC's auth
  (`$updateChecker->setAuthentication('<token>')`) on each install.

This is consolidation-plan **decision #7** (deferred: "don't publish yet").
Until then, install/update the plugin manually from a release ZIP.

## Slug / entry-file note (cutover)

The entry file is still `tomschooloflife-plugin.php` and the WordPress plugin
slug is `tomschooloflife-plugin`. The canonical slug decision is
`member-library-plugin` (the release ZIP already uses it). Renaming the entry
file / activated slug on a live site is a **Phase 8 cutover** step
(deactivate old, activate new; `tsol_*` options/tables/cron survive because
they are keyed by name, not by plugin slug) — not done here to avoid changing
plugin identity on the live sites mid-consolidation.
