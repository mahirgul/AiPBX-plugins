# Call Quality (RTCP)

Measures the audio of every call leg from the RTCP reports Asterisk already sends, and tells the
administrator in the portal's alert bell when a trunk or a phone is losing audio. Read-only: it never
changes a route or the dialplan.

Idea and first version: Gregory Merriweather ([@shido6](https://github.com/shido6)), who also built the
signaling capture it reads. This version was rewritten on top of his work by the AiPBX maintainers.

## Requirements

| | |
|---|---|
| AiPBX | **1.50.8** or newer (signaling capture that works, the alert bell). Uploading a plugin needs 1.50.3+. |
| Capture | **PBX settings → Signaling → Capture: On** |

## What it shows

On **Integrations → Call Quality (RTCP)**:

- whether reports arrive at all, and when the last pass ran;
- per trunk and per extension (24 hours): calls, poor and fair calls, average and highest loss
  **in** and **out**, highest jitter;
- the poor and fair call legs, newest first.

**In** is what this PBX received (the reports Asterisk sends describe it), **out** what the other side
received (the reports it sends back). That tells where to look: a trunk losing audio *in* points at the
carrier's side, *out* at ours (uplink, firewall, bandwidth); an extension losing audio *out* is often a
phone on poor Wi-Fi.

A leg is **poor** when two of its reports in one direction reach the configured loss (default 5 %), and
**fair** from 1 %. Jitter stays in RTP timestamp units, as Asterisk reports it (8 units = 1 ms for G.711;
the sample rate is not in the report).

## Alerts

Every minute, a trunk or extension with at least *N* poor legs in the last 15 minutes (default 3, and at
least a fifth of its measured legs) gets one alert in the bell, e.g. *"Trunk VOIP is losing audio — 4 of
9 calls in 15 minutes had poor audio, up to 12 % packet loss, mostly on the way from the carrier."* It
closes by itself when the quality is back. The bell needs no e-mail; nothing leaves the server.

## How a leg is named

Each SIP Call-ID is one leg (a trunk call through AiPBX to a phone is two legs). The plugin finds the leg's
INVITE in the captured signaling: when the other side of the INVITE is a trunk's address (or matches its
*match* list, host names are resolved), the leg belongs to that trunk; otherwise the From/To user part
names the extension. Media may come from other addresses than the signaling, which is why the INVITE,
not the RTP address, decides.

## Settings

| Setting | Default | |
|---|---|---|
| Packet loss that makes a report bad | 5 % | 1–50 |
| Poor legs in 15 minutes for an alert | 3 | 1–50 |
| Keep measurements | 90 days | 7–3650 |

## Data

One table, `plg_call_quality_legs` (one row per leg). The plugin reads `pbx_signaling_events` from the
last row it saw (first run: the last hour), so each report is read once.
