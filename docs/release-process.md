# Public release process

This page explains how maintainers prepare and publish BladeCP for WHMCS.
The process is intentionally conservative: preparing an artifact does not
publish it.

## The three steps

```sh
./scripts/public-release.sh prepare 0.9.0-rc.10
./scripts/public-release.sh stage-github 0.9.0-rc.10 --public-base <clean-public-main-checkout>
./scripts/public-release.sh publish 0.9.0-rc.10 --confirm-publication v0.9.0-rc.10
```

`prepare` runs the release gates and creates the reviewed artifact directory.
`stage-github` creates a local Git repository with an independent public
history and the matching version tag. Neither command contacts GitHub or
publishes files.

`publish` is intentionally separate. It requires the exact version confirmation and an additional maintainer-controlled safety lock. It validates the staged commit, tag, checksum and approved GitHub destination before it can perform an external write. It never force-pushes.

Publish `v0.9.0-rc.10` as a pre-release based on the existing public `main`
history. Do not describe it as a stable version until the planned partner
validation period is complete.

## What is released

Every public release includes:

- an installable ZIP containing only `modules/servers/bladecp/` and
  `modules/addons/bladecp/`;
- `SHA256SUMS` so users can verify the downloaded ZIP;
- release notes for that version;
- a curated public source tree;
- public documentation, templates and tested automation.

The released ZIP is validated from the artifact itself in disposable WHMCS
installations. For `v0.9.0-rc.10`, validate it with WHMCS 8.13.3 and WHMCS
9.0.6. Source-tree tests are useful, but they do not replace this
artifact-only validation.

When a release changes client-facing UI, refresh all three localized README
sets: English, Portuguese (Brazil) and Spanish. Each set must include overview,
configuration, plans, pricing, marketplace, operations and diagnostics, and
the client-service view. Capture the full WHMCS UI and the BladeCP addon in the
same language. Public documentation and captures must use `api.bladecp.com`
only, neutral data such as `example.com`, and no real people, organizations,
domains, customer records, credentials or operational identifiers.
The client-service capture must come from a dedicated neutral demonstration
service whose domain is `example.com` in both WHMCS and BladeCP. Do not alter a
bitmap or replace rendered DOM text to simulate localization or sanitized data.

## Safety checks

The preparation process checks version consistency, syntax, automated tests,
integration coverage, public-tree structure, links, secret patterns and
unintended internal references. It also verifies that the package is
deterministic and produces an audit record. Review the installable ZIP,
checksum, release notes, documentation, screenshots and public source tree
before authorizing publication.

Automated checks cannot judge every security or editorial concern. The first public release and material changes receive an independent human review of code, documentation, screenshots, manifest and release assets. Screenshot review at original resolution is mandatory. It verifies the complete language coherence of WHMCS and addon chrome, approved API host, neutral data and absence of personal or third-party information. Public images must be re-encoded without EXIF, XMP, IPTC, comments or application-specific metadata. OCR is advisory only and is not an automated release gate.

## History policy

The first public release starts a clean, curated public history. Later
releases add ordinary reviewed commits and version tags to that history. The
private development source remains authoritative: private history is never
mirrored or copied into the public repository, and public history is never
rewritten after the first release.

Public contributions are reviewed in GitHub, then incorporated into the
private development source before returning in a later curated public export.
This keeps the public repository reviewable without creating a second source
of truth.

For the contribution path between the public repository and the primary development source, see [repository governance](repository-governance.md).
