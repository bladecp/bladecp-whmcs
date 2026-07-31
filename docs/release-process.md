# Public release process

This page explains how maintainers prepare and publish BladeCP for WHMCS.
The process is intentionally conservative: preparing an artifact does not
publish it.

## The three steps

```sh
./scripts/public-release.sh prepare 0.9.0-rc.3
./scripts/public-release.sh stage-github 0.9.0-rc.3
./scripts/public-release.sh publish 0.9.0-rc.3 --confirm-publication v0.9.0-rc.3
```

`prepare` runs the release gates and creates the reviewed artifact directory.
`stage-github` creates a local Git repository with an independent public
history and the matching version tag. Neither command contacts GitHub or
publishes files.

`publish` is intentionally separate. It requires the exact version confirmation and an additional maintainer-controlled safety lock. It validates the staged commit, tag, checksum and approved GitHub destination before it can perform an external write. It never force-pushes.

For the first public release, publish `v0.9.0-rc.3` as a pre-release. Do not
describe it as a stable version until it has completed the planned partner
validation period.

## What is released

Every public release includes:

- an installable ZIP containing only `modules/servers/bladecp/` and
  `modules/addons/bladecp/`;
- `SHA256SUMS` so users can verify the downloaded ZIP;
- release notes for that version;
- a curated public source tree;
- public documentation, templates and tested automation.

The released ZIP is validated from the artifact itself in non-production WHMCS
installations. For `v0.9.0-rc.3`, validate it with WHMCS 8.13.3 and WHMCS
9.0.6. Source-tree tests are useful, but they do not replace this
artifact-only validation. When a release changes client-facing UI, review the
English, Portuguese (Brazil), and Spanish views from the generated artifact.

## Safety checks

The preparation process checks version consistency, syntax, automated tests,
integration coverage, public-tree structure, links, secret patterns and
unintended internal references. It also verifies that the package is
deterministic and produces an audit record. Review the installable ZIP,
checksum, release notes, documentation, screenshots and public source tree
before authorizing publication.

Automated checks cannot judge every security or editorial concern. The first public release and material changes receive an independent human review of code, documentation, screenshots, manifest and release assets.

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
