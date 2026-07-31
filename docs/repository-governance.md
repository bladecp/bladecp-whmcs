# Repository governance

This document describes the intended governance for the public `bladecp/bladecp-whmcs` repository. It is a recommendation for the repository owners to apply. It does not claim that any GitHub setting has already been enabled.

## Source of record and public collaboration

The private Bitbucket repository is the primary source of development. The public GitHub repository receives curated exports and keeps an independent, reviewable public history.

Public issues and pull requests are welcome. Maintainers review them in GitHub for transparency. When a public contribution is approved, its change is first imported into the private source repository, where it follows the normal validation process. A later curated export returns that approved change to the public repository.

This direction prevents the public repository from becoming a second, inconsistent source of truth. It also keeps sensitive development material out of public history. Do not expect a merged public pull request to appear in a release until it has completed this import and export cycle.

After the first public release, history remains normal and append-only. Do not rewrite public commits, mirror private history, or force-push public branches.

## Recommended GitHub settings

For the default branch, repository owners should consider enabling:

- Pull requests before merge.
- At least one approving review for changes made by contributors.
- Dismissal of stale approvals when new commits are pushed.
- Required successful checks from the public continuous-integration workflow.
- Conversation resolution before merge.
- Blocked force-pushes and branch deletion.

Restrict release creation and version tags to trusted maintainers. Keep the repository public only after the first release has passed its content, security and artifact review.

## Issues and pull requests

Use the issue templates for bugs and feature requests. A good report states the BladeCP for WHMCS version, relevant WHMCS and PHP versions, a concise reproduction path and the observed result.

Never include API keys, passwords, webhook signing secrets, customer records, private addresses, service identifiers, full logs or screenshots containing sensitive information. Use the private process in [SECURITY.md](../SECURITY.md) for vulnerabilities.

Pull requests should be focused, explain the user-facing outcome and include appropriate tests or manual validation notes. Maintainers may request that a public contribution be recreated from the private source before it can be exported.

## Releases

Releases are prepared locally from a closed public manifest. They include an installable ZIP, SHA-256 checksum, release notes and a reviewed public source tree. The three-step process is documented in [the release process](release-process.md).

Preparing a release or creating a local stage never publishes anything. A separate, explicitly confirmed command is required for an external release.
