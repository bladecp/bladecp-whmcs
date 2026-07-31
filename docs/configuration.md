# Configuration

## Create the server record

In WHMCS, open **Setup > Products/Services > Servers** and add a new server
using the `bladecp` module. Configure the production connection as follows:

| Field | Value |
| --- | --- |
| Hostname | `api.bladecp.com` |
| Secure | Enabled |
| Access Hash | BladeCP API key |

Enter the API key in **Access Hash**, not in a product field or addon setting.
Use `api.bladecp.com` as the public production host. An empty hostname also
defaults to production, but explicitly configuring the hostname is clearer and
recommended.

Save the server and use WHMCS's connection test before assigning it to
products.

## Create products

Create a WHMCS product, select `bladecp` as the module and assign the server
record. Set the required module options in this order:

1. **BladeCP Product Type**
2. **BladeCP Plan**
3. **Components**, when applicable

The optional addon can import available plans and create matching WHMCS
products. It can also manage component combinations and configurable options.

## Dedicated services

Dedicated services use a manual lifecycle. Create, suspension, changes and
termination must be coordinated with the BladeCP operations process. Do not
expect the module to automate physical server allocation or release.

## Vibe

Vibe is not provisioned by this WHMCS module. Do not assign Vibe products to
the `bladecp` server module.

## Webhooks

The addon can register a webhook endpoint so BladeCP can reflect service
status changes in WHMCS. The endpoint must be publicly reachable over HTTPS.
Use a valid TLS certificate and ensure that any firewall, reverse proxy or
web application firewall permits BladeCP webhook requests.

Keep the webhook signing secret private. If the endpoint is moved, update its
registration before disabling the old address.

## Usage cron

WHMCS must run its normal daily cron for usage updates and billing automation.
Configure the WHMCS cron according to your WHMCS installation requirements;
the module does not replace the WHMCS cron scheduler.
