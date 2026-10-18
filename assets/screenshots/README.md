# Public screenshot contract

Only reviewed captures of the BladeCP addon belong in this directory. The
public documentation has three complete, independently coherent screenshot
sets: English (`en`), Portuguese for Brazil (`pt-BR`) and Spanish (`es`).

Each language directory contains exactly these eight images:

- `overview.jpg`
- `configuration.jpg`
- `plans.jpg`
- `pricing.jpg`
- `components.jpg`
- `client-area.jpg`
- `migrations.jpg`
- `diagnostics.jpg`

Each image shows the addon area only, cut below the WHMCS header, in the
language of the corresponding README. Do not mix an English addon with
Portuguese or Spanish labels, and do not reuse one language image as another
language.

Captures may come from a demonstration WHMCS or from a production WHMCS that
runs the module, as long as nothing in the image identifies the installation.
All public docs and images use `api.bladecp.com` only. Do not show the WHMCS
header or brand of the installation, an alternate API host, a development
environment, real customer details, email addresses, people, company names,
domains or paths of the installation, IP addresses, API keys, webhook
secrets, endpoint identifiers, ticket identifiers, business volumes,
financial records or unreleased details.

Prefer screens that need no change. When a screen shows an address, a path or
an identifier of the installation, either leave that screen out or replace
the value with a documentation example (`example.com`, `/home/example`) in the
page before capturing. Never invent a state, a count or a result, and never
edit text inside the bitmap.

Before release, review every image at original resolution. This visual privacy
and language-coherence review is a mandatory manual gate. Automated checks can
validate filenames and JPEG structure, but they cannot reliably validate the
visible text or the privacy of a screenshot.
