# Changelog

All notable changes to BladeCP for WHMCS are documented in this file.

The project follows [Semantic Versioning](https://semver.org/). Versions marked
as release candidates are pre-release versions and may change before a stable
release.

## 0.9.0-rc.4

This release candidate expands the public administration experience and
strengthens the curated release workflow introduced in `0.9.0-rc.3`.

### Added

- Native administration-addon translations for English, Portuguese (Brazil),
  and Spanish.
- A Spanish README and complete localized screenshot sets for all three public
  languages.
- Localized public screenshots for pricing, marketplace, operations and
  diagnostics, and the client service view.

### Changed

- Public compatibility guidance now describes supported WHMCS and PHP release
  families without imposing the versions used by one validation environment.
- Public documentation and release rules now require coherent WHMCS and addon
  language, the production API hostname, and neutral demonstration data.
- Administrative views now use the selected WHMCS language consistently.

### Fixed

- Public-tree validation now checks the complete localized screenshot matrix
  portably across supported maintainer environments.
- Configured-server summaries no longer expose internal WHMCS record IDs.

## 0.9.0-rc.3

First public release candidate for BladeCP for WHMCS. This pre-release is
intended for partner validation before the first stable version.

### Added

- Public release documentation for installation, security reporting, and
  contribution guidance.
- A curated public package layout for GitHub distribution.
- Client-area language support for English, Portuguese (Brazil), and Spanish.

### Included

- Provisioning support for BladeCP Stack Container, Edge Cloud, and Dedicated
  products through WHMCS.
- An optional administrative addon for setup, catalog management, webhooks,
  configurable options, and operational views.
- Compatibility validation with WHMCS 8.13.3 and WHMCS 9.0.6.

### Improved

- More reliable synchronization between a selected component combination and
  the native WHMCS order summary.
- Clearer handling of simple and composable product choices when importing and
  configuring catalog products.

## Earlier versions

Earlier development history is intentionally not reproduced in this public
changelog. This release establishes the independent public version history.
