# Changelog

All notable changes to BladeCP for WHMCS are documented in this file.

The project follows [Semantic Versioning](https://semver.org/). Versions marked
as release candidates are pre-release versions and may change before a stable
release.

## 0.9.0-rc.8

This release candidate gives the addon a cron of its own, bills migrations
made with the cPanel Migrator in WHMCS and adds the optional Fast Deploy
client flow.

### Added

- A module cron, `modules/addons/bladecp/cron.php`, run every minute from the
  server crontab. Federated logout, the Vibe welcome, cPanel Migrator billing
  and the migration commercial bridge run every minute, and the daily
  synchronization once a day. Each task has its own lock, time budget and
  error record, so one failing task does not stop the others.
- Module cron status in the **Diagnostics** tab: last run, pending work, the
  exact command with a copy button, an optional email alert when the cron
  stops, and an action that adds or removes only the module's own crontab
  line.
- cPanel Migrator billing. Migrations made by the cPanel Migrator with the
  key of a server become native WHMCS services of the right client, linked
  to the migration, so they are billed in WHMCS. The setting is per server,
  on by default, invisible to clients and independent of Fast Deploy.
- Account confirmation in the client area: when a migrated account is not
  yet known to WHMCS and its holder started the migration, the customer
  confirms the account after signing in.
- **Migrations to review** in the addon, where an administrator chooses or
  creates the client of a migration that could not be billed automatically.
  The same screen lists linked migrations with their phase, commercial state
  and last decision.
- Fast Deploy, an optional client area flow to migrate a site from a full
  cPanel backup to a plan and location of the partner catalog, with plan
  details, commercial badges, migration history and safe recovery.
- One Fast Deploy commercial policy per server, for all of its plans: pay
  before migrating, migrate and pay to activate, or migrate and try, each
  with a configurable number of days.
- An optional Vibe prompt card on the client homepage, enabled per server and
  off by default.
- An optional WordPress Toolkit page in the client area for eligible
  services, enabled per server and off by default.
- An **Open control panel** action on the client area page of an active
  Stack Container service, through the native single sign-on action.

### Changed

- Services for linked migrations, created by the importer, the administrator
  review or the customer confirmation, no longer go through a WHMCS order, so
  store checkout hooks and fraud checks are not triggered. Fast Deploy
  purchases made by the customer keep the regular order.
- A migration counts as complete once the destination serves the site.
  Pointing the domain is optional, and activation, the trial and the first
  invoice start at that point.
- Each commercial decision of the bridge, such as a trial started, a service
  activated, an invoice issued, a suspension or a skipped step with its
  reason, is recorded in the WHMCS activity log without personal data.
- The addon **Client area** tab groups the Vibe prompt card, Fast Deploy,
  WordPress Toolkit and cPanel Migrator billing settings per server.
- Vibe plan diagnostics explain what blocks a plan, the free plan state and
  the result of each catalog synchronization, including import counts.
- The scoped Vibe support entry checks native WHMCS administrator
  permissions, also starts when an administrator is logged in as the client,
  and confirms before switching to another customer.
- Vibe sign-in transitions show continuous progress and follow the contrast
  of the client area theme.

### Fixed

- Checkout and product configuration stop a cart whose component combination
  or choice has left the BladeCP catalog, before any charge.
- Fast Deploy due dates, including the first invoice and the end of a trial,
  use the time zone of the WHMCS installation instead of UTC.
- Marketplace substrate invoices show the correct due day.
- BladeCP product configuration styles no longer affect other products.
- A linked migration always uses its own service, even when an older closed
  reservation exists for the same request.
- Vibe activation recovers from temporary identity exchange failures and
  keeps its correlation when an API response is lost.
- Vibe client access and the support entry accept WHMCS releases whose
  native CSRF check returns no value.
- Client area messages of the Vibe activation and commercial flows, Fast
  Deploy, WordPress Toolkit, cart checks and single sign-on, and several addon
  screens, now follow the customer or installation language instead of always
  showing Portuguese.

### Security

- Ownership of a migrated account is proven only by an Active or Suspended
  cPanel service already held in WHMCS for the same server and account.
  cPanel can reuse usernames, so terminated, cancelled or never provisioned
  services are not accepted, and an unproven owner never receives a service.
- Clients created in **Migrations to review** require the full WHMCS
  registration and receive no welcome email.

## 0.9.0-rc.7

### Added

- Added a scoped administrative support entry for eligible Vibe services.
  Authorized operators authenticate with their own BladeCP account before
  access is granted.

### Security

- The support entry verifies the selected service and uses short-lived,
  single-use navigation data. Existing customer sign-in flows remain unchanged.

## 0.9.0-rc.6

This release candidate adds an opt-in federated sign-in flow for Vibe.
Existing installations retain their current behavior until explicitly enabled.

### Added

- Server-specific signing keys, encrypted through native WHMCS facilities,
  with public registration, key rotation and capability checks.
- Automatic continuation from an authenticated account owner's client area,
  with browser-bound, short-lived and single-use authorization.
- Durable logout delivery with retry after temporary network failures.

### Security

- Administrative impersonation cannot authorize a federated customer session.
- Existing identities require ownership verification before linking.
- Credentials, authorization codes and private signing keys are redacted from
  module diagnostics.

## 0.9.0-rc.5

This release candidate adds the authorized Vibe reseller lifecycle and makes
the saved API connection state explicit throughout the administration addon.

### Added

- Dedicated Vibe account creation, plan changes, suspension, reactivation,
  termination and single sign-on through the Customer API.
- Vibe catalog readiness, service correlation fields and lifecycle webhook
  handling.
- Automatic validation of the saved Server credential when an administrator
  opens the connection or overview screen.

### Changed

- Connection summaries now distinguish a validated credential, a rejected or
  revoked credential, temporary API unavailability and missing configuration.
- Administration banners and onboarding readiness use API health instead of
  treating the existence of a Server record as a working connection.

### Fixed

- A revoked or replaced API key no longer leaves the connection card with a
  misleading healthy appearance.

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
