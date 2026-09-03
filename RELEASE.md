# Release Process

Canonical repo: `BetterBetterBetter/toms-member-learning-plugin` (public).

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

## Update channel — LIVE (repository public since 2026-09-03)

The in-plugin update checker (`plugin-update-checker`, wired in
`member-library-plugin.php` with release assets enabled) reads the GitHub
Releases of this now-public repository. Each site sees a new version in
Plugins → Installed Plugins within WordPress's normal update-check window (up
to 12 hours), or immediately via the plugin row's "Check for updates" link.
The update installs the `member-library-plugin.zip` asset attached to the
release, so every release must carry that asset (steps 3–5 above).

Consolidation-plan decision #7 is therefore settled as "public repository";
no per-site token is needed.

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
