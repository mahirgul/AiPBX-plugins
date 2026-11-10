# AI Natural Language PBX Assistant

Community plugin by **Gregory Merriweather (shido6)**. Requires AiPBX 1.72.12+ and PHP with curl and
mbstring. Install it from the community catalog or upload its ZIP under **Integrations → Plugins**.
Its console is `/plugins/ai-assistant`; the core `/assistant` dialplan editor is a separate feature.

## Preview and confirm

1. Enable the plugin and enter an instruction in Turkish or English.
2. Review the proposed HTTP method, `/api/v1` path and JSON body.
3. Click **Confirm & Execute** to send the stored draft to the PBX API.

Generating a plan does not execute it. POST actions require a valid CSRF token and an enabled plugin.
The console is an administrator page; drafts and history are shared among its administrators.
Drafts/history are stored in `sys_plugin_kv`, with the host's 500-entry collection cap. Drafts are
claimed by an atomic delete before execution, preventing concurrent confirmation of the same draft; no separate time-based draft expiry is implemented.

The optional OpenAI-compatible URL, API key and model generate plans through an external LLM.
Without a key or on provider failure, built-in rules support creating/listing extensions, listing
queues or active calls, applying pending configuration and reading health. Preview generated extension
credentials before confirming. The API enforces the plugin's declared scopes and normal validation. Queue operations declare their separate `queues:write` scope.

## API transport and safety

Execution uses HTTPS to the fixed local `127.0.0.1/api/v1/` endpoint, a plugin Bearer token, no proxy
and no redirects. Model output cannot select a different API host. The local request uses the same
certificate exception as the previous bundled implementation because the portal certificate is for
its public hostname, not the loopback address. External LLM requests retain normal TLS verification.
The internal token is read explicitly with `PluginManager::getSetting`, never from the public settings
array. If it is absent, re-enable the plugin to recreate it.

The LLM can suggest state-changing API operations; review every plan before confirming. The execution
response and recent action metadata appear in the console. Generated plans may contain credentials,
so treat the preview, stored drafts and history as administrative data. The plugin does not configure
core cloud privacy controls or apply an additional redaction filter to prompts.

## Moving from the bundled copy

The next AiPBX core upgrade migrates installed bundled copies to `/var/lib/aipbx/plugins/ai-assistant`
using an offline package. The slug, enabled state, encrypted settings, API token, drafts and history
are preserved. Legacy token scopes gain queue access at the same read/write level as their existing PBX access; token values and expiry are unchanged. Fresh installations do not install it automatically. Later plugin updates come from
the catalog. Disabling preserves data and revokes the worker API token; uninstall removes its settings
and KV collections through the host plugin lifecycle.

## Tests

Run `php plugins/community/ai-assistant/tests/console.php` from this repository. It exercises preview
and confirmation, CSRF, disabled state, consumed drafts, API destination validation and failures with
a controlled KV store and HTTP transport. No database, core checkout or paid API is needed. CI uses
PHP 8.5. Core tests cover package installation and migration; live provider behavior is not asserted.
