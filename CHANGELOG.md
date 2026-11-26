# Changelog

All notable changes to NebulaCMS are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.14.0] - 2026-11-27

### Added

- **Email settings** at **Settings → Email**: SMTP host, port, encryption, credentials,
  sender address and name, plus a test-email action. The credential blob is encrypted at
  rest and overrides `config/mail.php` at boot, so installations no longer need `.env`
  edits. A reset action returns to the environment configuration.
- **Page revisions and scheduling.** Pages now keep a revision history with one-click
  restore, can be scheduled, and disappear from the public site until their publish time.
  The existing `posts:publish-scheduled` command publishes both posts and pages.
- **Full-site backups** at **Settings → Backups**: application files, themes, plugins,
  uploads and a database dump in one archive, with download, delete and restore. A safety
  backup is created before every restore. Daily scheduled backups run at 02:00 when the
  scheduler is configured, keeping the newest `NEBULA_BACKUP_RETENTION` archives.
- **CSP enforcement toggle** at **Settings → Security**. The policy still ships in
  report-only mode; administrators can enforce it after checking the reports. Bundled
  inline scripts now carry the request nonce, jsDelivr is allowed for Bootstrap and Mermaid,
  and plugins can extend the policy with the `security.csp_directives` filter.

### Fixed

- Creating or editing a page failed at runtime because the form called the non-existent
  `pages.store` / `pages.update` route names. Both now use `admin.pages.*`.
- Restoring pages, publishing and unpublishing now keep `published_at` consistent.

## [1.13.2] - 2026-11-26

### Fixed

- Admin pages crashed with "usePage must be used within the Inertia component" because the
  flash toaster was mounted outside the Inertia tree. Flash messages now come from Inertia's
  `success` event and render through the global Toaster.

## [1.13.1] - 2026-11-26

### Added

- Global toast feedback: shared flash success/error messages now appear on every admin
  page instead of being invisible.
- Theme settings dialog on **Admin → Themes**, driven by `settings_schema.fields` in
  `theme.json`.
- **Post SEO fields:** meta title, meta description, canonical URL and robots, editable from
  the post form, rendered by the bundled theme (including Open Graph) and exposed through
  the public API under `seo`.
- **Audit coverage:** settings updates, user create/update/delete, role
  create/update/delete and media upload/delete are written to the activity log.

### Fixed

- Dotted settings keys (`colors.primary`, `layout.container_width`) are now persisted for
  plugins and themes; Laravel's `validated()` dropped flat dotted keys before.
- The bundled theme renders post featured images through `featured_image_url`, which
  previously resolved to null.
- README no longer claims TinyMCE support or links to documentation pages that do not exist.

## [1.13.0] - 2026-11-26

### Added

- **Security headers and report-only CSP** on every web response, with a per-request
  nonce and a throttled `/csp-report` endpoint (`SECURITY_*` env switches).
- **Server-side HTML sanitisation** for users without the `unfiltered_html` permission
  (Admin and Super Admin by default), covering posts, pages, autosave, and revision
  restores. `php artisan content:sanitize` audits existing content (`--apply` writes).
- **Public render safety net:** stored post/page HTML is sanitised when rendered, so legacy
  rows cannot execute scripts before the stored content is cleaned.
- **Settings → Security → Content HTML safety** lets admins scan up to 2,000 items, review
  samples, and sanitise them in one click; `content:sanitize` gained `--limit` and type
  filtering for operators.

### Changed

- Post-update tasks now run `optimize:clear` so route/event caches cannot survive an
  update, and release archives no longer ship CI-generated `bootstrap/cache/*.php`.

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
