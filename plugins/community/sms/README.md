# SMS / MMS

Text messages on your numbers, sent and received through **Twilio, Vonage, Plivo, Telnyx, Bandwidth,
Sinch or VoIP.ms**. The users a number is given to read and answer its messages under **Messages**;
administrators see every number. Needs AiPBX 1.71 or newer (the `user_page` hook). Requested in
[AiPBX #152](https://github.com/mahirgul/AiPBX/issues/152).

## Setting up

1. On **Integrations → Plugins** tick **Show community plugins from the catalog** (AiPBX 1.72+) and
   press **Install & Enable** next to SMS / MMS; on 1.71, upload the folder as a `.zip`. Then open **SMS / MMS**.
2. Fill in the account of your provider at the top. Secrets are stored encrypted.
3. Under **Numbers** add the number in international form (+15551234567), its provider and its users.
4. Copy the provider's **webhook address** from the plugin page into the provider's portal, where it
   asks for the address of incoming messages (the page names the place). The last part of the address
   is a secret; **Make a new secret key** replaces it.

## Using it

- **Messages** lists the conversations of the user's numbers; new messages appear by themselves, and a
  reply being written stays.
- **Names:** "Save name" on a conversation names that number for everyone on our number; the list, the
  page and the mails show it.
- **E-mail:** when a message arrives, the number's users get a mail with the text and a link to the
  conversation, at most one per conversation in 10 minutes (setting at the top; the users need an
  e-mail address and the portal's mail settings must work).

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
Users on #152 confirmed sending and receiving with **Twilio** and **VoIP.ms** (1.0.0). Bandwidth's
send fields could not be read from its documentation and follow what is publicly known; the other
providers are untried with live accounts. Reports are welcome on #152.

## Adding a provider

One file in `lib/providers/` that extends `SmsProvider` (`lib/Provider.php`): `send()`, `inbound()`,
`verify()`, and its settings in `plugin.json`. Add its cases to `tests/providers.php`.
