# SIP Provider Templates

Create a SIP trunk from a template instead of finding the settings yourself. The templates are small files written by
the community, so the list grows with what people actually use. Needs AiPBX 1.60.6 or newer.

## Using it

1. Download this folder as a `.zip`, with `plugin.json` at its root, and upload it under **Integrations → Plugins**.
2. Open **Integrations → SIP Provider Templates** and choose your provider.
3. Read the settings, enter your server, login and country, and create the trunk.
4. Check it under **Trunks**, add an outbound route, and press **Apply**.

The result is an ordinary trunk: you can change it, and it keeps working if you remove the plugin.

A template marked **Not tested with real calls** was written from the provider's documentation. Make a test call before
you rely on it.

## Adding a provider

Copy [`templates/_example.json`](templates/_example.json) to `templates/<provider>.json` and open a pull request with
that one file. You do not need to write any code.

| Key | Meaning |
| --- | --- |
| `schema` | Always `1` |
| `name` | Provider name |
| `country` | Two-letter code, or `global` |
| `docs_url` | The provider's public setup page, `https` |
| `source` | `provider documentation`, `pbx vendor guide` or `working installation` |
| `host` | The SIP server if it is the same for everyone, otherwise empty |
| `host_hint` | Where to find the address if it is different for each customer |
| `needs_login` | `true` if a user name and password are required |
| `send_e164` | `true` if the provider wants `+<country code>...` |
| `notes` | What the user has to do at the provider first |
| `tested` | `null`, or who tested it with real calls: `by` (GitHub name), `date` (`YYYY-MM`), `aipbx` (version) |
| `trunk` | The trunk's settings, as text |

Allowed in `trunk`: `transport`, `port`, `codecs`, `dtmf_mode`, `outbound_proxy`, `registration_enabled`, `registration_expiration`,
`qualify_frequency`, `direct_media`, `rtp_symmetric`, `rewrite_contact`, `force_rport`, `timers`, `prack_100rel`,
`send_pai`, `send_rpid`, `t38_support`, `carrier_strip_prefix`, `carrier_add_prefix`.

Check your file with `php tests/validate.php`. The same check runs on every pull request.

### Rules

- **Only public information or your own working setup.** Do not copy from a vendor's paid or password-protected material.
- **No passwords, account names or customer-specific addresses.**
- **Set `tested` only if you made real calls** with that template, in and out.
- **A `host` is reviewed closely:** a wrong one would send someone's calls to another server. Link the page that states it.
- **Tested a template that says it is not tested?** A pull request that fills in `tested` is very welcome.
- **Free-form PJSIP options are not allowed.** If a provider needs one, say so in `notes`: the user can add it on **Advanced Configuration**.

## Removing it

Disable or delete the plugin under **Integrations → Plugins**. Trunks you created stay as they are.
