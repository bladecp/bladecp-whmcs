# Compatibility

## Requirements and supported baselines

| Component | Requirement or supported baseline |
| --- | --- |
| WHMCS | 8.x and 9.x. |
| PHP | PHP 8.1 or later. |
| Database | Any database engine and version supported by the installed WHMCS release. The module adds no database-specific requirement. |
| PHP extensions | cURL, JSON and OpenSSL |
| Administration theme | A theme that preserves standard WHMCS hooks and markup. |
| Client themes | Themes that preserve standard WHMCS hooks and markup. |
| Browser | Current evergreen browsers supported by WHMCS. The module has no browser-specific dependency. |
| Languages | Client-facing module and addon administration: English, Portuguese (Brazil) and Spanish. |
| Network | Outbound HTTPS access to the selected BladeCP API host |
| Webhooks | Public inbound HTTPS access when webhook status updates are enabled |
| Scheduler | A working WHMCS daily cron for usage and billing automation |

The module is loaded directly by WHMCS. It does not require Composer, a build
step or a separate PHP package manager installation.

## Validation snapshot

The supported range stays WHMCS 8.x and 9.x with PHP 8.1 or later. The last
complete validation matrix was run for `0.9.0-rc.4`, with WHMCS 8.13.3 and
9.0.6, PHP 8.2.31, MariaDB 12.3.2, the Blend administration theme and the
Twenty-One, Six and Nexus client themes. These are tested environments, not
additional minimum requirements. Release candidate `0.9.0-rc.8` runs in
production WHMCS installations of partners. Validate your installation in
staging before using it in production.

The module provisions Stack Container, Edge Cloud/VPS and Dedicated products.
Eligible Vibe plans use the module's dedicated Vibe account handlers. They do
not use infrastructure locations, components or WHMCS usage synchronization.

Configure `api.bladecp.com` as the hostname in the WHMCS server record.
