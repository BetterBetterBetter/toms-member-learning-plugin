# Legacy Normalization Inventory (Historical)

This directory now retains only the read-only source specification and manifest
builder used by the clone-only TSOL Library importer.

The earlier pilot/full writers created drafts inside MemberPress Courses. They
were fully rolled back and their PHP classes, command registration, structural
contracts, and browser cutover controls were removed. There is no
`wp tsol library-normalization` command.

Current reusable files:

- `class-library-normalization-spec.php`: locked schema constants and source
  fingerprint.
- `class-library-normalization-manifest.php`: read-only classification, media,
  resource, curriculum, collection, and access mapping discovery.

The manifest expects 149 distinct published sources and 150 mappings, with
source fingerprint
`eac2344e9d2cafa392de22bafdb33cf89b0fcbe4a8d820bf9dd2e5a22d0eaab2`.

All writes belong to `../library-catalogue-import`, which creates only private
TSOL-owned drafts and never changes MemberPress Courses or legacy Pages.
