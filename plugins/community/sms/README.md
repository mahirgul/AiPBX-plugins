# SMS / MMS

Text messages on your numbers, sent and received through **Twilio, Vonage, Plivo, Telnyx, Bandwidth,
Sinch or VoIP.ms**. The users a number is given to read and answer its messages under **Messages**;
administrators see every number. Needs AiPBX 1.71 or newer (the `user_page` hook). Requested in
[AiPBX #152](https://github.com/mahirgul/AiPBX/issues/152).

## Setting up

1. Enable the plugin on **Integrations → Plugins**, then open **SMS / MMS**.
2. Fill in the account of your provider at the top. Secrets are stored encrypted.
3. Under **Numbers** add the number in international form (+15551234567), its provider and its users.
4. Copy the provider's **webhook address** from the plugin page into the provider's portal, where it
   asks for the address of incoming messages (the page names the place). The last part of the address
   is a secret; **Make a new secret key** replaces it.

## How each provider is checked

| Provider | Incoming messages | Proof checked | MMS |
| :--- | :--- | :--- | :--- |
| Twilio | form POST | `X-Twilio-Signature` (Auth Token) | yes |
| Vonage | JSON (Messages API) | signed webhook JWT, when the signature secret is set | US; one picture per message |
| Plivo | form POST | `X-Plivo-Signature-V2` (Auth Token) | yes |
| Telnyx | JSON, `message.received` | Ed25519 signature, when the public key is set | yes |
| Bandwidth | JSON list, `message-received` | secret key in the address | yes |
| Sinch | JSON, `mo_text` / `mo_media` | secret key in the address | US, CA, AU when Sinch turns it on; one picture per message |
| VoIP.ms | URL callback with placeholders | secret key in the address | yes, up to 3 |

Every request also needs the secret key in its address.

Pictures are kept in the database, as providers' own links need their sign-in or expire. An outgoing
picture is given to the provider as a link with a random token under `/api/plugins/sms/media/`, so the
portal must be reachable from the internet over HTTPS by its domain (PORTAL_DOMAIN).

## Tested

`php tests/providers.php` checks every provider's request, its webhook reading and its proof check
with the shapes from the providers' public documentation (Twilio's own example signature included).
No provider was tried with a live account yet: **Bandwidth's send fields and VoIP.ms's callback
placeholders could not be read from their documentation and follow what is publicly known.** Reports
from real accounts are welcome on #152.

## Adding a provider

One file in `lib/providers/` that extends `SmsProvider` (`lib/Provider.php`): `send()`, `inbound()`,
`verify()`, and its settings in `plugin.json`. Add its cases to `tests/providers.php`.
