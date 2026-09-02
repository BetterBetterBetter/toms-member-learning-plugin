# Release Process

Canonical repo: `BetterBetterBetter/toms-member-learning-plugin` (private).

One plugin core serves both brands (Tom's School of Life, Liberty Classroom);
brand differences are per-install config (`MemberLibrary_Brand`), never separate
builds. See `docs/plans/plugin-consolidation-plan.md` in the workspace.

## Cut a release

1. Bump the version in **two** places to the same value:
   - the `Version:` header in `member-library-plugin.php`
   - `define('MEMBER_LIBRARY_PLUGIN_VERSION', ...)`
2. Add a `CHANGELOG.md` entry.
3. Run `tools/build-release.sh`. It verifies that both version declarations
   match and builds `build/member-library-plugin.zip` locally with the required
   top-level `member-library-plugin/` directory.
4. Inspect the ZIP, commit, tag `vX.Y.Z`, and push the commit and tag.
5. Create the GitHub Release with `gh release create` and attach the locally
   built ZIP. GitHub Actions is not part of the build or release path.

## Update channel — NOT yet live

The in-plugin update checker (`plugin-update-checker`, wired in
`member-library-plugin.php`) points at this repo, but the repo is **private**,
so the checker cannot fetch releases without authentication. Before automated
updates work, EITHER:

- flip the repo to **public** (tokenless, like the legacy TSOL plugin did), OR
- keep it private and configure a GitHub token via PUC's auth
  (`$updateChecker->setAuthentication('<token>')`) on each install.

This is consolidation-plan **decision #7** (deferred: "don't publish yet").
Until then, install/update the plugin manually from a release ZIP.

## Slug / entry-file note (cutover)

The entry file is now `member-library-plugin.php` and the release ZIP's
top-level directory is `member-library-plugin/`. Installed *folder* names still
vary per site and that is fine — WordPress updates a plugin in place, keyed by
the PUC slug (`member-library`), not by the ZIP's directory name. Locally TSOL
has the canonical plugin under the legacy folder `tomschooloflife-plugin` and
Liberty under `libertyclassroom-library`; both resolve to this one codebase.

Renaming the activated slug on a live site is a **Phase 8 cutover** step
(deactivate old, activate new; `tsol_*` options/tables/cron survive because
they are keyed by name, not by plugin slug) — deliberately not done here, to
avoid changing plugin identity on the live sites mid-consolidation.
