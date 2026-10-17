# Troubleshooting

## Connection test fails

Check the BladeCP server record:

1. Confirm that **Secure** is enabled.
2. Confirm that **Hostname** is `api.bladecp.com`.
3. Confirm that the **Access Hash** contains the API key with no extra spaces
   or line breaks.
4. Confirm that the key is active for the configured production account.
5. Confirm that the WHMCS host can make outbound HTTPS requests and that PHP
   cURL is enabled.

Do not place the API key in module custom fields. It belongs only in the
server record's Access Hash field.

## A service was not provisioned

Review the WHMCS module log for the relevant service action. Then confirm that
the product uses the `bladecp` module, has the correct server assigned and
has a valid Product Type and BladeCP Plan. Correct the configuration and
retry the WHMCS action only after confirming the prior request's result in
BladeCP, to avoid duplicate operational work.

For Dedicated services, follow the manual lifecycle process. Hardware
allocation and release are not automated by the module.

## Webhook status updates do not arrive

Confirm that the registered URL is public HTTPS, has a valid certificate and
is reachable without WHMCS administrator authentication. Check that the
endpoint has not been changed by a reverse proxy, firewall or URL rewrite.
Re-register the webhook after changing the site URL or moving WHMCS.

Treat the signing secret as a credential. Generate and register a new secret
if it may have been exposed.

## Usage is not updated

Verify that the standard WHMCS cron is running successfully. Usage updates
come from its daily task, which calls the module for each server; the module
cron does not run usage updates.

## Module cron stopped

Open the Diagnostics tab of the addon. It shows the last run of each task, the
last error and the command to schedule. Confirm that the crontab line runs as
the user that runs the WHMCS PHP and that its path points to this WHMCS. Run
the command by hand to see its report: it prints one JSON line.

## Vibe product cannot be provisioned

Confirm that the plan was imported from the current partner catalog with Vibe
channel availability enabled, that the product type is `vibe`, and that the
server API key has the required Vibe scopes. The module does not send provider
AI credentials, so configure those through the BladeCP partner workflow.

## Federated sign-in cannot continue

Check the selected server, public installation registration, key revision and
remote capability status. Do not paste the private signing key into support
messages or another application. Start a new attempt from the customer's own
WHMCS session if a callback expired or was already consumed.

Never repair an identity collision by matching email addresses or copying an
account identifier from another service. Complete the requested ownership
verification instead. A disabled installation, revoked session or suspended
service must be corrected by its authorized administrator, not bypassed by
falling back to an older sign-in flow.

## Get help

When contacting support, include the WHMCS version, PHP version, module
version, the affected service ID and the relevant error message. Never send
the API key or webhook signing secret.

## Vibe offers waiting for synchronization

Open **Plans** in the BladeCP addon and review each Vibe plan's readiness and
synchronization details. These are separate requirements:

1. The selected WHMCS server must use a partner key issued for the API host
   configured in that server record. A test WHMCS installation keeps its own
   server record, key and catalog, so configure and synchronize it separately.
2. The technical plan needs an active Vibe reseller enrollment and an allowed
   AI mode. Partner AI requires a supported, validated credential for that
   server. Unpublished and administratively disabled plans remain blocked.
3. The active commercial profile must use the WHMCS Addon channel. Its allowed
   hosts, portal, support and handoff URLs should point to this installation.
   Editing or submitting a profile for review does not prove that it is active.
4. Import the available plan into this WHMCS, configure its price and make the
   product visible when ready for sale. Select **Sync Vibe offers**. The addon
   also synchronizes after imports and price changes and retries in the daily
   synchronization of the module cron.

Changing the handoff URL does not import products or refresh prices. A stale
price requires a new accepted catalog snapshot. Synchronization acceptance
alone does not mean that every offer is published; technical readiness and the
active commercial profile are still required. Disabled offers require an
explicit administrative reactivation.

If the diagnostic reports different local and remote product IDs, ask the
platform administrator to review the mapping. Do not copy IDs from another
WHMCS installation. The latest attempt is shown for the selected server;
connection failures and mapping conflicts are reported separately. Older API
versions may not provide detailed diagnostics.
