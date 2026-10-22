# call-quality

Score calls from the RTCP reports Asterisk already sends, keep the evidence and
tell an administrator when a trunk is losing media. It **never** changes a
route, a dialplan or a live call.

RTCP Sender/Receiver Reports already arrive on every AiPBX with HEP capture on
(`res_hep_rtcp` → `aipbx-signaling`). Nobody reads them while a call is bad, and
"the carrier is terrible" is hard to argue with when the only evidence is a
user's memory. This plugin turns those reports into a per-call record:

- packet loss and jitter, exactly as Asterisk reported them;
- one evidence row per incident, with the first/last four report blocks kept;
- a plain sentence naming the trunk, the route and the change a person *could*
  make — and nothing applied on their behalf;
- one e-mail per problem per cooldown window, when an address is configured.

## Requirements

| | |
|---|---|
| AiPBX | **1.50.2 or newer** for the RTCP counters on `pbx_signaling_events`. On an older build the plugin installs, shows the capture state and says that this build does not store the counters yet — it never reports "all fine" from an empty measurement. |
| Capture | HEP capture on, pointed at this server's own daemon (`enabled = yes`, `capture_address = 127.0.0.1:9061`). The page says which of the two is missing. |
| Worker | `aipbx-webhook-worker.service` running (it is part of a normal install). The plugin declares `"hooks": {"worker": true}` and runs from `worker/tick.php`. |

No cron line, no extra package, no second collector: this is the same path
`auto-dialer` uses for its background work.

## What it does with the schedule

`worker/tick.php` is polled every two seconds by the worker service. The
five-minute clock lives in the plugin: a pass claims its turn, runs, and is
remembered in the plugin key-value store (`state/last_pass`), which is also what
the page shows under **Last pass**. A poll in between does nothing.

A pass:

1. prunes evidence older than `evidence_days`;
2. groups the last 10 minutes of RTCP reports per call and scores them
   (two reports at ≥ 5 % loss = poor; ≥ 1 % = fair; otherwise good);
3. records one evidence row per poor call;
4. groups poor calls per trunk and writes the suggestion
   (`move qc_lossy to the end of "qc-main"`), or says there is no route to
   reorder;
5. mails the configured address, at most once per trunk per
   `alert_cooldown_hours`.

If two trunks lose packets at the same time, no single carrier is blamed: the
pass records `multiple_trunks` and says to check the uplink and both carriers.
Loss against a peer that is not a configured trunk is recorded as `local_peer`
(the phone, its Wi-Fi, the switch) and is not mailed unless
`mail_local_peers` is on.

## Settings

Rendered by the core plugin page from `settings_schema`:

| Key | Default | Meaning |
|---|---|---|
| `evidence_days` | 180 | Retention of the evidence table, 7–3650 days. |
| `notify_email` | *(empty)* | One or more addresses (comma separated). Empty = nothing leaves the server. |
| `alert_cooldown_hours` | 6 | One alert per trunk (or per peer) per window, 1–168 h. The evidence is still recorded on every pass; only the mail and the repeat are held back. |
| `mail_local_peers` | off | Also mail phone/LAN problems. Off by default: they are local and can be many. |

## What it writes

- `plg_call_quality_evidence` — one row per incident, deduped by
  `hash(call_id . first_ts)`, with the RTCP samples that led to the decision and
  the finding in plain language.
- `sys_plugin_kv` — `state/last_pass` (the clock and what the last pass did) and
  `state/alert:<key>` (cooldown timestamps for the repeat window).
- `sys_plugin_settings` — the four settings above, through the core settings form.

It does **not** write `pbx_signaling_events` (that is the daemon's), `pbx_trunks`,
`pbx_outbound_routes`, `sys_pending_sync`, `sys_audit_log`, `pbx_notices`, any
dialplan file or any Asterisk configuration. There is no code path in the plugin
that mutates the PBX.

## Install

1. Zip this folder with `plugin.json` at the root of the archive (or copy it to
   `/var/lib/aipbx/plugins/call-quality/`).
2. **Integrations → Plugins → Upload Custom Plugin (`.zip`)**, then **Enable**.
   The loader creates `plg_call_quality_evidence` from `schema.sql`.
3. Open **Integrations → Plugins → Call Quality Evidence** and read the capture
   card. It must say *RTCP arrives here*; if it says something else, fix
   `hep.conf` first — nothing else will work.
4. Set `notify_email` if you want mail, and use **Send a test e-mail**.
5. **Run a pass now** should agree with the page a minute later; the worker
   takes over from there.

## Uninstall

Disabling stops the worker and the page. Uninstalling drops
`plg_call_quality_evidence` (the loader owns that) — export anything you need to
keep first. The settings and the small key-value state are removed with the
plugin.

## How this was tested on AiPBX

A staged AiPBX 1.50.1 checkout (`14ad927`), the plugin uploaded to a custom
catalog, the test database, and the core loader calling `worker/tick.php`. 67
checks, all passing, covering:

- capture state: local / remote collector / loopback on the wrong port /
  `enabled = no` / missing file / `#include` overriding the address;
- the arithmetic: fraction → percentage, "two reports is poor", a single 20 %
  report is only fair, IPv6 peer parsing;
- a real pass through `PluginManager::tickAllEnabledWorkers()`: one evidence row,
  action `suggested`, the finding naming `qc-main`, the route order **unchanged**,
  no audit row, no notice, no signaling row written;
- the mail: one message, portal `From:`, the trunk in the subject, the plugin link
  and "nothing was changed" in the body (captured from PHP's `sendmail_path`);
- the clock: a second poll two seconds later does nothing; a pass after five
  minutes runs but the incident dedupes and the cooldown holds the mail, and the
  page can say why;
- two trunks at once: `multiple_trunks`, a critical mail that blames neither, and
  routes untouched;
- a build without the 1.50.2 counters: `counters_missing`, no verdict, and the
  page says which release adds them;
- the page itself through `templates/views/plugins/detail.php` (capture card,
  suggestion card, evidence table, trail via `?evidence=N`), the run-now and
  test-mail actions, CSRF refusal, and retention pruning.

Not verified: a real Asterisk with real SIP calls (the RTCP rows are synthetic,
built from Asterisk's report shape), and a browser session clicking the page
(the panel was rendered through the portal templates with real data). Mail was
verified against a local `sendmail_path` capture script, not a live SMTP server.

## Files

```
plugin.json                     manifest: worker hook, tables, settings
schema.sql                      plg_call_quality_evidence
CaptureState.php                hep.conf -> "does RTCP land here?"
CallQualityService.php          scoring, evidence, suggestion, mail, the five-minute pass
worker/tick.php                 the background hook
admin/page.php                  capture state, suggestion card, evidence table, trail
admin/action.php                run a pass now / send a test e-mail
```
