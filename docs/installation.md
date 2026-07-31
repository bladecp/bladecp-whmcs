# Installation

## Before you begin

Have a BladeCP partner API key and administrative access to the WHMCS
installation. Download the release ZIP and its published `SHA256SUMS` file.

Verify the downloaded ZIP before extracting it:

```sh
shasum -a 256 -c SHA256SUMS
```

The command must report `OK` for the release ZIP. On Linux, use
`sha256sum -c SHA256SUMS` instead.

## Install the module

1. Back up the WHMCS installation according to your normal operating
   procedure.
2. Upload the release ZIP to the root directory of the WHMCS installation.
3. Extract the ZIP there. Do not extract it into an additional subdirectory.
4. Confirm that these directories exist:

   ```text
   modules/servers/bladecp/
   modules/addons/bladecp/
   ```

The installable ZIP contains only `modules/`. It does not overwrite a WHMCS
root README, license, documentation file, or installer.

If your hosting control panel cannot extract ZIP files, extract it locally
and upload the resulting `modules/` directory contents to the matching
`modules/` directory in WHMCS. Preserve the directory structure and replace
only files from the release.

The server module provides provisioning. The addon is optional and provides
the setup, catalog and webhook administration screens.

## Update an existing installation

Verify the new ZIP checksum, then repeat the extraction over the existing
WHMCS root. Existing server records, products and module settings are kept.
Do not delete the BladeCP module directories before updating.

## Next step

Continue with [configuration](configuration.md) to create the BladeCP server
record and connect products to plans.
