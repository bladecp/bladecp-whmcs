# Compatibility

BladeCP for WHMCS requires:

| Component | Requirement |
| --- | --- |
| WHMCS | 8.13.3 and 9.0.6, tested |
| PHP | PHP 8.1 or later; tested with PHP 8.2.31 |
| Database | MariaDB 12.3.2 |
| PHP extensions | cURL and OpenSSL |
| Administration theme | Blend |
| Client themes | Twenty-One and Six |
| Browser | Google Chrome 150.0.7871.187 |
| Languages | Client-facing module: English, Portuguese (Brazil) and Spanish. Addon administration: Portuguese (Brazil). |
| Network | Outbound HTTPS access to the selected BladeCP API host |
| Webhooks | Public inbound HTTPS access when webhook status updates are enabled |
| Scheduler | A working WHMCS daily cron for usage and billing automation |

The module is loaded directly by WHMCS. It does not require Composer, a build
step or a separate PHP package manager installation.

This table reports the WHMCS releases tested for `0.9.0-rc.3` and the local
baseline exercised for the remaining components. It is not a claim that other
WHMCS releases or themes are incompatible. Validate any additional environment
in staging before using it in production.

The module provisions Stack Container, Edge Cloud/VPS and Dedicated products.
Vibe is outside the scope of this module.

Only configure the production host (`api.bladecp.com`) or sandbox host
(`api.bladecp.cloud`) that corresponds to the API key in the server record.
