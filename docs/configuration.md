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

Eligible Vibe plans can be imported and assigned to the `bladecp` server
module. The module stores the returned **BladeCP Vibe Account ID** on the
service, then uses it for lifecycle actions and single sign-on. Vibe plans do
not use locations, infrastructure components or provider AI credentials in
WHMCS.

### Optional Vibe prompt card

In **Addons > BladeCP > Client area**, enable **Show the Vibe prompt card**
for the server selected in Configuration. The option defaults to off and is
stored separately for each server. Disabling it removes the homepage card;
it does not disable existing Vibe services or sign-in routes.

The initial card supports a single visible, non-retired Vibe Free offer and
one usable server in that product's server group. The selected catalogue
context must match that server. It is shown to the account Owner when they
have no Vibe service or one matching Pending/Active Free service. Existing
Free history, ambiguous services and an Active/Pending paid Vibe service do
not receive this acquisition card. Administrator impersonation can only use
an existing, bound, eligible Free service through the normal support flow.

The customer can enter up to 5,000 characters, select Build or Plan, enable
Deep thinking and attach one JPEG, PNG, WebP, GIF or AVIF image up to 8 MiB.
Six suggestions provide starting text. Enter sends the idea; Shift+Enter
adds a line. Image requirements appear in a keyboard-accessible help tooltip.
The browser sends the content directly to Vibe. WHMCS receives only the
short-lived continuation token. Text and images are not stored in WHMCS.
The homepage uses local eligibility information; the existing activation
route still confirms live availability and identity before granting access.

Sending requires HTTPS, browser cookies and a WHMCS origin authorized by
Vibe. Enabling the option does not register that origin. An HTTP localhost
can display the card for layout review, but cannot send ideas. Errors keep
the entered content in the current page. Reloading or leaving the page
clears that content. The card does not offer a separate skip action.

Voice dictation starts only when the customer clicks the microphone. It
requires HTTPS, a compatible browser speech service and microphone permission.
Typing remains available when dictation is unsupported or permission is denied.
The browser speech service performs transcription; the module does not store
audio. The idea can be reviewed before sending.

Neutral card surfaces follow the template, with indigo accents for the composer.
A theme can set `--bcp-vibe-accent` on the card to match its brand. Its composer
controls and inline icons are supplied by the module. Six suggestion pills
share one row on desktop and wrap on smaller screens.
The prompt and suggestions have lightly tinted backgrounds. Hovering or focusing
the composer reveals a subtle animated border and shadow; reduced-motion
preferences keep the border static.

The card uses the native `ClientAreaHomepage` extension point. Themes must
render `addons_html` on the client homepage. It was validated on WHMCS 9.0.6
with Nexus and a child theme that retains this extension point, including a
mobile viewport. Themes that omit it will not show the card automatically.
The module does not edit theme files or add a second fallback page.

### Optional federated sign-in

Federated sign-in is disabled by default. Installing an update does not enable
it or replace an existing sign-in method. Enable it only after the connected
BladeCP service supports the protocol and the installation is registered.

In the addon's Configuration screen, select the correct server and generate
its signing key. WHMCS encrypts the private key locally. Register only the
displayed public registration with BladeCP, then confirm activation. The
module checks remote capabilities before enabling the flow. API credentials
and signing keys have separate purposes and must not be reused.

Customers start from their own authenticated WHMCS client area. Administrator
impersonation is not a customer identity proof. An existing, unlinked identity
may require one ownership verification before subsequent automatic access.

Disabling the local option stops new federated access. Revoking an installation
in BladeCP also invalidates its existing access when the authority is checked.
Key rotation disables local sign-in until the new public key is registered and
activation is confirmed again.

Keep the module cron running. Customer logout attempts revocation
immediately; unsuccessful deliveries are retained and retried by the module
cron every minute.
During a network outage, revocation is not instantaneous. Do not interpret a
successful browser handoff as confirmation that the customer reached Vibe.

## Webhooks

The addon can register a webhook endpoint so BladeCP can reflect service
status changes in WHMCS. The endpoint must be publicly reachable over HTTPS.
Use a valid TLS certificate and ensure that any firewall, reverse proxy or
web application firewall permits BladeCP webhook requests.

Keep the webhook signing secret private. If the endpoint is moved, update its
registration before disabling the old address.

## Usage cron

Usage updates and WHMCS billing automation come from the standard WHMCS cron,
which calls the module once per server for usage. Configure it according to
your WHMCS installation requirements. The module cron below runs the module
tasks and does not replace it: keep both.

## Module cron

The addon runs its light queues every minute and its daily synchronization
once a day from its own cron. Add this line to the crontab of the user that
runs the WHMCS PHP, with the PHP binary of your server and the path of your
installation:

```text
* * * * * php -q '/path/to/whmcs/modules/addons/bladecp/cron.php'
```

The Diagnostics tab of the addon shows the exact command for your server and a
button to copy it. When the server allows it, a button adds or removes only
this line in the crontab and keeps every other line. The script runs from the
command line only; a web request receives a 404.

Each task has its own lock, time budget and error record, so one failing task
does not stop the others. If the module cron stops, the WHMCS cron runs a
limited rescue: the queues after 15 minutes without a heartbeat and the daily
synchronization after 36 hours without a success. The addon can also email
administrators through a WHMCS system notification when the module cron
stops.
