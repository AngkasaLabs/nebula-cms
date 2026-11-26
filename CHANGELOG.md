# Changelog

All notable changes to NebulaCMS are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.12.1] - 2026-11-26

### Added

- CI matrix across PHP 8.3 and 8.4 on SQLite, plus a MySQL 8.4 job so database-specific
  behaviour (full-text search, JSON, migrations) is tested.
- Larastan (level 5) with a baseline and a `composer analyse` script.
- Reusable `tests` workflow: release tags now run the full suite before building anything,
  and tags must match `config/nebula.php`.
- Release tags create a **draft** release. The archive is signed offline with
  `nebula-pack sign-file` before publishing.
- The update check reports the signature asset URL, and the admin updater and `cms:update`
  pass it through so verification happens before the backup/extract step.
- `CHANGELOG.md`.
- `NEBULA_CONTENT_SEARCH_FULLTEXT=false` forces the LIKE search fallback on hosts that
  cannot create full-text indexes.
- MySQL-only integration suite (`tests/Integration`) for full-text search.
- **Signed core releases:** `nebula-pack sign-file` signs a CI-built archive and
  `UpdateService` verifies it (Ed25519 + SHA-256 + size) before extracting anything.
  Releases from 1.13.0 onwards must be signed; older releases stay installable unsigned
  so pre-signing installs can update once.

### Changed

- Quality gates now fail instead of silently rewriting files: `pint --test`,
  `prettier --check`, and `eslint` without `--fix` run in CI.
- Repository reformatted with Pint and Prettier so the gates are enforceable.
- Updated `laravel/framework` to 12.74.0, `league/commonmark`, `league/flysystem`, and the
  Symfony components to clear every `composer audit` advisory (including a critical
  Laravel TokenGuard authentication bypass fixed in 12.68.0).

### Fixed

- Media breadcrumb for the open folder used a raw `#` href, which failed type checking.
- Search and scan-settings tests are deterministic on MySQL: the blog search test asserts
  the matched title, and settings comparisons ignore JSON key order.

## [1.12.0] - 2026-11-25

### Added

- Core-owned admin navigation sections (`main`, `content`, `commerce`, `tools`, `system`,
  `extensions`) with `order` and `permission` support for plugin items.
- 37 supported sidebar icons with a `Puzzle` fallback, plus warnings in Admin → Plugins
  when a manifest uses an unknown group or icon.
- `schedule.register` action and `sitemap.urls` filter for plugins.
- Child theme view fallback to the parent theme; `shop` slug reserved for Nebula Commerce.

### Changed

- Legacy `admin` navigation group maps to `extensions`; unknown groups no longer leak into
  the system area.
- Extensions section collapses into one entry when it holds more than six flat links.

### Fixed

- Admin plugin/theme uploads show verification errors and inline validation messages.
- Re-scanning plugins/themes no longer overwrites admin-managed settings.
- Slug mismatches report as verification failures instead of "Invalid ZIP".

## [1.11.1] - 2026-11-25

### Changed

- `NEBULA_PACKAGE_SIGNING_KEYS` adds keys on top of the built-in official key instead of
  replacing the whole map. `NEBULA_PACKAGE_SIGNING_KEYS_REPLACE=true` keeps the old
  replace-only behaviour.

### Fixed

- Invalid signing-key JSON falls back to the built-in keys instead of trusting nothing.

## [1.11.0] - 2026-11-25

### Security

- Admin plugin/theme uploads require a signed package (`.sig.json`) and verify Ed25519,
  SHA-256, and size before anything is written to disk.
- Signed slug and package type are bound to the installed archive.

### Added

- `schedule.register` action and `sitemap.urls` filter for plugins.
- Child theme view fallback and reserved `shop` slug.

### Fixed

- Re-scanning plugins/themes no longer resets their settings.
- Admin upload and scan actions now report success and failure.

## [1.10.1] - 2026-11-22

Previous release. See the [releases page](https://github.com/AngkasaLabs/nebula-cms/releases)
for earlier history.
