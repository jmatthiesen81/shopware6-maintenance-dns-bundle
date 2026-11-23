# Changelog

All notable changes to the Shopware 6 Maintenance DNS Bundle are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The major version follows the Shopware version: 7.x for Shopware 6.7 (branch `7.0`), 6.x for Shopware 6.6 (branch `6.0`).

## [Unreleased]

### Added

- This changelog, Dependabot updates for GitHub Actions and Composer, and a GitHub Actions workflow that runs the tests (including the MySQL tests) for PHP 8.2 to 8.5 on every push and pull request.

## [7.0.1] - 2026-11-23

### Added

- **Maintenance badge:** while a sales channel is in maintenance mode, Storefront pages show a small badge, so allowed clients see at a glance that the shop is not public. Configurable with `badge.enabled` (default `true`), `badge.text` and `badge.position`. Not shown on the maintenance page, XHR requests, redirects, the Store API or the Admin; uses the CSP nonce of the request; responses with the badge are sent `private, no-store`.
- **`command.keep_max_age_seconds`:** limits how long the sync keeps the managed IPs of a hostname that cannot be resolved, counted from their last successful lookup (default `0`, without limit). Prevents a dynamic IP that the provider has given to someone else from staying in the allowlist indefinitely.

### Fixed

- **Security (runtime mode):** a client allowed during maintenance of one sales channel could use the Store API of another sales channel in maintenance, by calling the Store API below the Storefront domain of the first one (e.g. `/de/store-api/...`) with the access key of the other one. Store API requests are now always checked against the sales channel of the `sw-access-key` header, like Shopware does.

## [7.0.0] - 2026-11-23

### Added

- **Command mode:** `maintenance:dns-whitelist:sync` resolves the configured DNS/DynDNS hostnames per sales channel and synchronizes their IPs into the maintenance allowlist. Only IPs the bundle added itself are removed again; manual entries are never touched. Dry run, per sales channel runs, a lock against parallel runs and exit codes for cron monitoring.
- **Runtime mode:** hooks into `MaintenanceModeRequestEvent` and allows clients whose IP matches a resolved hostname, without writing to the allowlist (Storefront, Store API and HTTP cache). Shared DNS cache, fail-closed by default.
- **Cleanup:** `maintenance:dns-whitelist:cleanup` removes the managed IPs and the bundle state before uninstalling.
- Minimal UDP resolver with a real timeout that distinguishes NXDOMAIN, SERVFAIL and timeouts. Only globally routable single IPs are accepted by default.
- Managed IPs stored in `system_config`, written in the same transaction as the allowlist.
- Support for `maintenanceIpAllowlist` (Shopware 6.7.13+) and `maintenanceIpWhitelist`.
- Licensed under the Mozilla Public License 2.0.

[Unreleased]: https://github.com/jmatthiesen81/shopware6-maintenance-dns-bundle/compare/7.0.1...7.0
[7.0.1]: https://github.com/jmatthiesen81/shopware6-maintenance-dns-bundle/compare/7.0.0...7.0.1
[7.0.0]: https://github.com/jmatthiesen81/shopware6-maintenance-dns-bundle/releases/tag/7.0.0
