# Caller Name (CNAM) Plugin

Caller ID name resolution for inbound calls in AiPBX.

## Features
- **Multiple Providers:**
  - **BulkVS:** Ultra low-cost CNAM lookup for US/CA numbers (Issue [#159](https://github.com/mahirgul/AiPBX/issues/159)).
  - **Telnyx:** Number Lookup API v2 (`type=caller-name`).
  - **Twilio:** Modern Lookups v2 API (`Fields=caller_name`).
  - **Vonage:** Number Insight Advanced API (`cnam=true`).
  - **OpenCNAM:** Global and North American v3 REST API.
  - **SignalWire:** Relay REST Lookup API (`include=cnam`).
  - **NumVerify:** APILayer global number validation & carrier identification.
  - **AbstractAPI:** Global phone intelligence & carrier detection.
  - **Custom URL:** Integrate any proprietary CRM, database, or internal HTTPS microservice.
  - **Local Phonebook Only:** Look up caller names from internal extensions, PBX contacts, and SMS address books for free.
- **Cost Reduction (Caching):** Configurable local cache (default 30 days) to prevent paying twice for frequent callers.
- **Fail-Open Protection:** Synchronous lookup with a 1-second provider timeout and a 2-second local HTTP timeout. A slow lookup can delay call routing up to the local timeout; empty results leave the existing caller name unchanged.
- **Internal Directory Priority:** Checks active user extensions/external caller IDs and SMS contacts before performing paid lookups.
- **Admin Management Page:** Cache statistics, cache purging, and live interactive lookup test.

## Installation
Requires AiPBX 1.72.12 or newer, including the inbound CNAM hook. Apply generates a private lookup token; the HTTP endpoint requires that token and a loopback source address. Admin lookup tests run through the authenticated plugin page.

Enable the Community Catalog in AiPBX under **Settings → Plugins**, then install **Caller Name (CNAM)**.

Configure your provider API key under **Settings → Plugins → Caller Name (CNAM)** and click **Apply Changes**.
