# Troubleshooting

## Connection test fails

Check the BladeCP server record:

1. Confirm that **Secure** is enabled.
2. Confirm that **Hostname** is `api.bladecp.com` for production or
   `api.bladecp.cloud` for sandbox.
3. Confirm that the **Access Hash** contains the API key with no extra spaces
   or line breaks.
4. Confirm that the key belongs to the same environment as the hostname.
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

Verify that WHMCS's normal daily cron is running successfully. The module
uses that scheduler for usage updates; it does not provide a separate cron
command.

## Vibe product cannot be provisioned

This is expected. Vibe is not provisioned through this module. Remove the
`bladecp` module assignment from that product and use the appropriate Vibe
service workflow.

## Get help

When contacting support, include the WHMCS version, PHP version, module
version, the affected service ID and the relevant error message. Never send
the API key or webhook signing secret.
