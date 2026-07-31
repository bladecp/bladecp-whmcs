# Contributing to BladeCP for WHMCS

Thanks for taking the time to contribute. This repository contains the
official BladeCP module for WHMCS.

## How public contributions are handled

The private Bitbucket repository is the primary development source. GitHub
issues and pull requests are reviewed publicly, but an approved public change
is first imported into the private source and passes its normal validation
there. A later curated export returns the approved change to this repository.

This means a merged pull request may not appear in a public release
immediately. It also means public history stays independent from private
development history. After the first public release, public commits are added
normally and are never rewritten or force-pushed.

Read [repository governance](docs/repository-governance.md) for the complete
policy.

## Scope

Useful contributions include bug fixes, reliability improvements, accessibility
and translation fixes, documentation improvements, and focused enhancements to
the supported WHMCS integration.

Before starting a large feature, open an issue to describe the problem and the
proposed outcome. This helps us confirm scope and avoid duplicate work.

Do not include customer data, API keys, passwords, webhook secrets, or other
sensitive information in issues, pull requests, commits, screenshots, or test
fixtures.

## Development environment

- PHP 8.1 or later, with PHP 8.2 recommended.
- A local WHMCS installation for integration testing when a change touches
  WHMCS runtime behavior.
- No Composer installation or build step is required by the module.

Keep changes focused and preserve compatibility with supported PHP versions.
Follow the existing PHP style in the files you modify, including strict types,
direct-access guards, and defensive error handling suitable for a WHMCS module.

## Validation

Run a PHP syntax check before opening a pull request:

```sh
find modules -name '*.php' -print0 | xargs -0 -n1 php -l
```

Changes that affect WHMCS behavior should also be exercised in a non-production
WHMCS installation. Describe the version, theme and scenarios you tested in the
pull request. Maintainers run the complete automated, integration, security and
release gates before accepting or publishing a release. Public continuous
integration also checks the curated repository tree. A green public check does
not replace the private artifact-only validation performed before a release.

## Pull requests

1. Start from the current default branch and create a focused branch.
2. Describe the user-facing problem, the chosen solution, and the validation
   you performed.
3. Add or update tests when behavior changes.
4. Keep unrelated formatting or refactoring out of the pull request.
5. Be ready to address review feedback before merge.

Please write commit messages and pull-request descriptions clearly enough that
reviewers can understand the intended behavior without access to private
systems.

## Security reports

Do not use GitHub issues or pull requests to report a vulnerability. Follow
the private reporting process in [SECURITY.md](SECURITY.md).

## License

By contributing, you agree that your contributions are licensed under the
project's [MIT License](LICENSE).
