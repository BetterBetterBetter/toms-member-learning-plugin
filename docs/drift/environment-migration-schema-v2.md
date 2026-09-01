# Drift: environment-migration package schema v1 → v2 (one-way)

**Recorded:** 2026-09-01 (consolidation Phase 2/9).

## Divergence

`TSOL_Library_Environment_Migration::SCHEMA_VERSION` is now `2` (was `1`). The
importer accepts BOTH versions:

```php
in_array((int) ($package['manifest']['schema_version'] ?? 0), array(1, self::SCHEMA_VERSION), true)
```

So a v2 build can import a v1 package (forward compatible). But a **v1 build
cannot import a v2 package** — older plugin code checks `=== 1` and rejects it.

## The hold

- v2 adds collection `appearance` (light/dark background + accent colors) to
  exported collection records; v1 has no field for it.
- Exports from canonical (v2) that carry appearance data are **not
  importable** by any install still running pre-consolidation code.

## What must never be invented to paper over it

- Do not silently downgrade a v2 export to v1 by dropping `appearance` — that
  loses brand data without signal. If a v1 target ever needs a package,
  produce it deliberately and record the loss.
- Do not bump SCHEMA_VERSION again without extending the accepted-versions
  `in_array(...)` set and this doc.

## Practical rule

Migrate the exporting and importing installs to canonical (v2-capable)
together. During any rolling window, export from the OLDER of the two.
