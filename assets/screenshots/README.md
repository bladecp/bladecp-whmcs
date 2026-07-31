# Public screenshot contract

Only reviewed captures from the packaged module belong in this directory. The
public documentation has three complete, independently coherent screenshot
sets: English (`en`), Portuguese for Brazil (`pt-BR`) and Spanish (`es`).

Each language directory contains exactly these seven images:

- `overview.jpg`
- `configuration.jpg`
- `plans.jpg`
- `pricing.jpg`
- `marketplace.jpg`
- `operations-diagnostics.jpg`
- `client-service.jpg`

The WHMCS interface and the BladeCP addon must both be in the language of the
corresponding README. Do not mix an English addon with Portuguese or Spanish
WHMCS chrome, and do not mix currencies, labels or navigation from another
locale in the same capture.

Capture only the packaged public module installed in a controlled demonstration
or disposable WHMCS environment. All public docs and images use
`api.bladecp.com` only. Do not
show an alternate API host, development environment, real customer details,
email addresses, people, company names, service IDs, IP addresses, API keys,
webhook secrets, ticket identifiers, financial records or unreleased details.

Use neutral demonstration data consistently. The `client-service.jpg` capture
must show the contracted-service addon panel with the service domain set to
`example.com`, never a subdomain or a real organization domain. Do not show a
maintainer name, a third-party brand, the unpublished API host, or a
non-production environment label in any capture.

Before release, review every image at original resolution. This visual privacy
and language-coherence review is a mandatory manual gate. Automated checks can
validate filenames and JPEG structure, but they cannot reliably validate the
visible text, translated WHMCS chrome, or privacy of a screenshot.

Capture the real localized interface and real neutral demonstration fixtures.
Do not edit text inside the bitmap, replace labels in the DOM for the capture,
or reuse one language image as another language. The client-service fixture
must exist as a separate demonstration service so its `example.com` domain is
consistent in both WHMCS and the BladeCP service response.
