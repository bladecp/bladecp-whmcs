# Compatibility

## Requirements and supported baselines

| Component | Requirement or supported baseline |
| --- | --- |
| WHMCS | 8.x and 9.x. Validated with 8.13.3 and 9.0.6. |
| PHP | PHP 8.1 or later. |
| Database | Any database engine and version supported by the installed WHMCS release. The module adds no database-specific requirement. |
| PHP extensions | cURL, JSON and OpenSSL |
| Administration theme | A theme that preserves standard WHMCS hooks and markup. Validated with Blend. |
| Client themes | Themes that preserve standard WHMCS hooks and markup. Validated with Twenty-One, Six and Nexus. |
| Browser | Current evergreen browsers supported by WHMCS. The module has no browser-specific dependency. |
| Languages | Client-facing module and addon administration: English, Portuguese (Brazil) and Spanish. |
| Network | Outbound HTTPS access to the selected BladeCP API host |
| Webhooks | Public inbound HTTPS access when webhook status updates are enabled |
| Scheduler | A working WHMCS daily cron for usage and billing automation |

The module is loaded directly by WHMCS. It does not require Composer, a build
step or a separate PHP package manager installation.

## Validation snapshot

The validation snapshot for `0.9.0-rc.4` includes WHMCS 8.13.3 and 9.0.6, PHP
8.2.31 and MariaDB 12.3.2. These are tested environments, not additional
minimum requirements. Validate your installation in staging before using it in
production.

The module provisions Stack Container, Edge Cloud/VPS and Dedicated products.
Vibe is outside the scope of this module.

Configure `api.bladecp.com` as the hostname in the WHMCS server record.
