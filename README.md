# Shopware 6 Maintenance DNS Bundle

Symfony bundle (not a Shopware plugin) that resolves DNS hostnames, typically DynDNS names, and allows their IP
addresses during the maintenance mode of a sales channel.

Two modes can be enabled independently:

| Mode      | What it does                                                                                                                         | Persistent                       |
|-----------|--------------------------------------------------------------------------------------------------------------------------------------|----------------------------------|
| `command` | `bin/console maintenance:dns-whitelist:sync` (cron) resolves the hostnames and writes their IPs into the maintenance allowlist of the sales channel | yes, visible in the Administration |
| `runtime` | During the maintenance check of a request, the IPs of the hostnames are allowed in addition to the allowlist                          | no, the sales channel is not changed |

## Requirements

- PHP 8.2+
- Shopware 6.7 (`shopware/core ~6.7.0`), tested with 6.7.4.0 and 6.7.14.1
- A name server reachable via UDP port 53 (by default the ones from `/etc/resolv.conf`)

## Installation

```bash
composer require devable/shopware6-maintenance-dns-bundle
```

There is no Flex recipe. Register the bundle in `config/bundles.php`:

```php
Devable\MaintenanceDnsBundle\MaintenanceDnsBundle::class => ['all' => true],
```

The bundle needs no database migration.

## Configuration

```yaml
# config/packages/devable_maintenance_dns_whitelist.yaml
devable_maintenance_dns_whitelist:
  enabled: true

  dns:
    record_types: ['A']            # 'A' and/or 'AAAA'
    timeout_seconds: 2             # total budget per hostname (all record types and name servers)
    max_records: 10                # addresses used per hostname
    cache_ttl_seconds: 300         # runtime: cache lifetime of a successful lookup
    negative_cache_ttl_seconds: 30 # runtime: cache lifetime of a failed lookup
    allow_non_public_ips: false    # accept private, loopback, link-local, multicast, … addresses
    nameservers: []                # e.g. ['9.9.9.9']; empty = /etc/resolv.conf

  command:
    enabled: true
    dry_run: false                     # always run as dry run
    remove_on_negative_answer: false   # remove managed IPs on NXDOMAIN / no address
    lock_ttl_seconds: 300

  runtime:
    enabled: false
    fail_closed: true                  # on DNS failure allow nothing
    stale_max_age_seconds: 3600        # only if fail_closed is false: max. age of the last good answer
    cache_pool: 'cache.object'
    extend_cache_allowlist_header: true

  logging:
    enabled: true
    channel: 'maintenance_dns'         # Monolog channel

  sales_channels:
    '018f0000000000000000000000000001':
      domains:
        - 'entwickler.example.net'
        - 'agentur.example.org'
    '018f0000000000000000000000000002':
      domains:
        - 'qa.example.net'
```

- `enabled` is `false` by default. While it is disabled, only the cleanup command is registered.
- With `enabled: true`, at least one sales channel and at least one mode (`command` or `runtime`) are required.
- Sales channel IDs are validated (32 hexadecimal characters, case-insensitive).
- Hostnames are lowercased and must be valid hostnames with at least one dot. IP addresses, CIDR ranges, URLs and
  wildcards are rejected. Write IDNs in punycode (`xn--…`). Static IPs belong in the allowlist of the sales channel.
- `runtime.enabled` is off by default. Enable it only after testing it with your Shopware version and your setup
  (HTTP cache, reverse proxy).

Clear the cache after changes:

```bash
bin/console cache:clear
```

## Command mode

```bash
bin/console maintenance:dns-whitelist:sync [--sales-channel=<id>]... [--dry-run] [-v] [-n]
```

| Option                 | Meaning                                                                       |
|------------------------|-------------------------------------------------------------------------------|
| `--sales-channel`, `-s` | Only this sales channel (repeatable). Without it, all configured sales channels are synchronized |
| `--dry-run`            | Show the changes without writing them                                         |
| `-v`                   | Also show the DNS result of every hostname and the resulting allowlist        |
| `-n`                   | No interaction (cron)                                                         |

| Exit code | Meaning                                                    |
|-----------|------------------------------------------------------------|
| `0`       | Success                                                    |
| `1`       | Error (database, configured sales channel does not exist, …) |
| `2`       | Invalid input (invalid or unconfigured sales channel ID)   |
| `3`       | Finished, but at least one hostname could not be resolved  |
| `4`       | Another sync is running (lock held), nothing was done      |

Rules:

- IPs that the hostnames resolve to are added. IPs that the bundle added earlier and that no hostname resolves to any
  more are removed.
- **Manually entered entries are never removed**, not even when a hostname resolved to the same IP. If an IP is
  already in the allowlist when the bundle wants to add it, the bundle records it as manual.
- Other entries (CIDR ranges, other IPs) and their order stay unchanged.
- A temporary DNS error (timeout, SERVFAIL, truncated answer, …) keeps the previously managed IPs of the hostname.
  NXDOMAIN and answers without (acceptable) addresses keep them as well, unless `remove_on_negative_answer` is
  enabled.
- If a hostname is removed from the configuration, its managed IPs are removed. If a sales channel is removed from
  the configuration, its managed IPs are removed on the next run without `--sales-channel`.
- Repeated runs without DNS changes write nothing.
- The allowlist is written through the DAL (`sales_channel.repository`), like a change in the Administration. Write
  events and cache invalidation (e.g. of the Storefront domain cache) work as usual. From 6.7.13 on, the bundle
  writes the field `maintenanceIpAllowlist`, before that `maintenanceIpWhitelist`.
- The command reads the allowlist and the bundle state with the sales channel row locked (`SELECT … FOR UPDATE`) and
  writes both in one transaction. Parallel changes in the Administration therefore wait and are not overwritten.
  If someone removes a managed IP in the Administration while its hostname still resolves to it, the next run adds it
  again and logs a warning.
- A lock (Symfony Lock, `lock.factory`) prevents parallel runs. With several servers, configure a shared lock store,
  e.g. `framework.lock: '%env(LOCK_DSN)%'` with Redis.
- The command never switches the maintenance mode on or off. It synchronizes whether the maintenance mode is
  active or not.
- When the runtime mode is enabled too, the command also stores its DNS results in the runtime cache
  (cache warming). Runtime results never change the persistent state.

### Cron job

Every five minutes (adjust path and PHP binary):

```cron
*/5 * * * * cd /var/www/shopware && /usr/bin/php bin/console maintenance:dns-whitelist:sync --no-interaction >> /var/log/shopware-maintenance-dns.log 2>&1
```

How fast a changed IP is allowed also depends on the update interval of the DynDNS provider, the DNS TTL and caches
of the name server. An immediate update after an IP change is not guaranteed.

### Where the bundle keeps its state

To tell its own entries apart from manual ones, the bundle stores the IPs it manages in the Shopware system
configuration (table `system_config`), one global entry per sales channel:

```
DevableMaintenanceDnsWhitelist.managedIps.<salesChannelId> = {"<ip>": {"hostnames": [...], "inAllowlist": true|false}}
```

The entry is deleted when a sales channel no longer has managed IPs. It is not exposed publicly. Sales channel custom
fields are not used for this, because the Store API delivers them (`/store-api/context`).

## Runtime mode

Shopware decides about maintenance access in `Shopware\Core\Framework\Routing\MaintenanceModeResolver::isClientAllowed()`
and dispatches `Shopware\Core\Framework\Routing\Event\MaintenanceModeRequestEvent` there, **before** it decides. The
bundle listens to this event (`MaintenanceRequestSubscriber`, priority 100) and calls `allowClient()` if the client
IP matches an IP of a hostname of the sales channel. No core code is decorated or patched.

The same decision point is used by:

- the Storefront (`MaintenanceModeResolver::shouldRedirect()` / `shouldRedirectToShop()`, i.e. the redirect to and
  from the maintenance page),
- the Store API (`SalesChannelAuthenticationListener`, HTTP 503 during maintenance),
- the HTTP cache (`CacheStore`, `MaintenanceModeResolver::shouldBeCached()`).

Behavior:

- It only takes effect if the sales channel of the request is in maintenance mode. For the Storefront this comes
  from the request attributes, for Store API routes always from the `sw-access-key` header (one database query per
  request during maintenance), also when the Store API is called below a Storefront domain (`/de/store-api/...`),
  whose attributes belong to the sales channel of that domain. Without maintenance, it does not resolve anything.
- It only allows, it never disallows. Already allowed clients (static allowlist) are left alone without a DNS lookup.
- Hostnames are only resolved while a non-allowed client hits a sales channel in maintenance, and resolving stops at
  the first match.
- Nothing is written to the sales channel.
- Exceptions are logged and allow nothing (fail closed).

### Cache

- Lookups are cached in the PSR-6 pool `runtime.cache_pool` (`cache.object` by default): successful answers for
  `cache_ttl_seconds`, failed ones for `negative_cache_ttl_seconds`. So a slow or broken name server costs at most
  `timeout_seconds` per hostname and cache period, not on every request.
- With several servers, use a shared pool (e.g. Redis). Otherwise every server resolves on its own, which is still
  correct, just slower.
- If the runtime mode is used together with the command, the cron job warms the cache.
- `bin/console cache:clear` empties the pool. The next request resolves again.

### DNS failures

- `fail_closed: true` (default): a failed lookup allows nothing.
- `fail_closed: false`: the last successful answer is used, but only while it is younger than
  `stale_max_age_seconds`.
- NXDOMAIN and answers without addresses never allow anything.

### HTTP cache

Shopware sends the static allowlist in the `sw-maintenance-allowlist` header (`sw-maintenance-whitelist` before
6.7.13) with the maintenance page. The HTTP cache uses it to bypass cached maintenance pages for allowed IPs. With
`extend_cache_allowlist_header: true`, the bundle adds the runtime IPs to this header. It only does so when the core
already sends the header, i.e. when the static allowlist is not empty. Cached copies keep the IPs from the time they
were cached.

Pages that Shopware delivers from the HTTP cache are not checked again by the maintenance logic. This applies to
static allowlist entries the same way. Clear the HTTP cache after switching the maintenance mode on.

## Client IP and reverse proxies

The bundle uses `Request::getClientIp()`, the same IP Shopware uses. Behind a reverse proxy or load balancer,
configure `framework.trusted_proxies` (`TRUSTED_PROXIES`) correctly. `X-Forwarded-For` headers from clients that are
not trusted proxies are ignored. Spoofed entries left of the address the trusted proxy added do not help either.

An external reverse proxy cache (e.g. Varnish) that implements the maintenance mode itself only knows the allowlist of
the sales channel. Use the command mode there.

## Security and trust model

An allowlist entry grants access to a shop in maintenance. Whoever controls the DNS of a configured hostname (DNS
zone, DynDNS account, name server, network path to the name server) controls this access.

- Only configured hostnames are resolved, never names from requests.
- Only single, validated IP addresses are accepted. DNS answers cannot inject CIDR ranges or other entries. Only
  records of the queried name and its CNAME chain are used. The response ID and question are checked.
- By default only globally routable addresses are accepted (no private, loopback, link-local, multicast, CGNAT,
  documentation, IPv4-mapped, … addresses).
- DNS errors and timeouts never allow anything new, and they never remove anything in the command mode.
- The runtime cache lifetime limits how long an old answer stays valid (DNS rebinding). Keep it short.
- Logs contain hostnames and the added or removed IPs (needed to trace who was allowed), but no request data.

## Uninstalling

A Symfony bundle has no uninstall hook. When you remove it, the following stay behind:

- the IPs it added to the maintenance allowlists of the sales channels, and
- its state rows in the table `system_config` (keys `DevableMaintenanceDnsWhitelist.managedIps.<salesChannelId>`).

Therefore, before removing the bundle:

1. Disable the cron job.
2. Remove both, while the bundle is still installed (it works with `enabled: false` too):

   ```bash
   bin/console maintenance:dns-whitelist:cleanup --dry-run   # shows what would be removed
   bin/console maintenance:dns-whitelist:cleanup
   ```

   This removes only the IPs the bundle added itself. Manual entries stay.
3. Remove the bundle from `config/bundles.php` and the package configuration, run `composer remove
   devable/shopware6-maintenance-dns-bundle` and `bin/console cache:clear`.

If the bundle was already removed without the cleanup, delete the remaining rows manually:

```sql
DELETE FROM system_config WHERE configuration_key LIKE 'DevableMaintenanceDnsWhitelist.managedIps.%';
```

Afterwards, clear the cache (`bin/console cache:clear`). The IPs the bundle added then have to be removed from the
maintenance allowlists in the Administration by hand. Before deleting the rows, you can look up which IPs those are:
entries with `"inAllowlist": true` in `configuration_value`.

## Known limitations

- Only UDP to port 53, no TCP fallback. Truncated answers count as failures. This is irrelevant for the few A/AAAA
  records of a DynDNS name.
- No DNSSEC validation. If you need it, run a validating resolver on the same host (e.g. unbound or
  systemd-resolved with `DNSSEC=yes`) and configure it as the only name server (`nameservers: ['127.0.0.1']`). The
  bundle then trusts its answers. DNSSEC only helps if the zone of the hostname is signed, and it does not protect
  against a compromised DynDNS account.
- Link-local name servers with zone ID (`fe80::1%eth0`) from `/etc/resolv.conf` are ignored.
- When a cache entry expires, parallel requests may resolve at the same time (only during maintenance and only for
  non-allowed clients).
- The bundle cannot tell a manual entry apart from a managed IP that someone entered again by hand while it was
  managed. Such an entry is removed when its hostname no longer resolves to it.

## Maintenance

The runtime mode relies on Shopware internals. On Shopware updates, check:

- `MaintenanceModeResolver::isClientAllowed()` still dispatches `MaintenanceModeRequestEvent` before the decision,
  and Storefront, Store API and `CacheStore` still use it
- request attributes `sw-sales-channel-id`, `sw-maintenance` (`RequestTransformer`) and the Store API order in
  `SalesChannelAuthenticationListener` (maintenance check on `kernel.controller`, i.e. with `_routeScope` already set,
  and before setting the sales channel ID)
- allowlist field and header names (`maintenanceIpAllowlist` / `maintenanceIpWhitelist`, `sw-maintenance-allowlist` /
  `sw-maintenance-whitelist`, the deprecated ones are removed in 6.8)
- `SystemConfigService::set()` / `delete()`

## Tests

```bash
composer install
composer test
```

The database tests (`tests/Integration`) run against MySQL/MariaDB and are skipped without `DATABASE_URL`:

```bash
docker run -d --rm --name maintenance-dns-test-db -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=maintenance_dns_test -p 127.0.0.1:33067:3306 mysql:8.4
DATABASE_URL=mysql://root:root@127.0.0.1:33067/maintenance_dns_test composer test
docker stop maintenance-dns-test-db
```
