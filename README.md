# Liberty Classroom Library

WordPress editorial, access, and authentication bridge for the Liberty Classroom library application.

## Responsibilities

- Owns the Library catalogue and editorial metadata in WordPress.
- Publishes the signed catalogue contract consumed by the Library application.
- Bridges WordPress authentication and membership entitlements into Library sessions.
- Provides manageable Library access groups and membership assignments.
- Delivers announcement audiences, authentication revocations, and WebVTT transcripts.
- Supports WordPress-only migration packages for catalogue setup and recovery.

The plugin intentionally excludes Tom's School of Life accountability and cookie-consent features. Internal `TSOL_` class, option, hook, and REST names remain stable because they form the shared application contract and migration format.

## Local development

The repository is symlinked into the local WordPress installation at:

`libertyclassroom/wp-content/plugins/libertyclassroom-library`

Activate **Liberty Classroom Library** in WordPress, then use the **Liberty Library** admin menu to configure authentication, catalogue synchronization, content, and access groups.

## Production

Production credentials belong in the Liberty WordPress environment or protected plugin settings and in Coolify. Never commit secrets to this repository.

The GitHub updater is intentionally disabled until this plugin has its own repository and release channel. The `upstream` Git remote tracks the TSOL plugin only as a reference; it is not a deployment destination.

## Requirements

- WordPress 6.0 or newer
- PHP 8.0 or newer
- MemberPress for membership-backed access decisions
- Access Platform SSO is optional; the plugin reports when it is unavailable
