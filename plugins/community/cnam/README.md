# Caller Name (CNAM) Plugin

Caller ID name resolution for inbound calls in AiPBX.

## Features
- **Multiple Providers:** BulkVS (Issue [#159](https://github.com/mahirgul/AiPBX/issues/159)), OpenCNAM, Custom HTTP API, or Local Phonebook only.
- **Cost Reduction (Caching):** Configurable local cache (default 30 days) to prevent paying twice for frequent callers.
- **Fail-Open Protection:** 2-second dialplan timeout; calls are never delayed or dropped if an external API is slow or unavailable.
- **Internal Directory Priority:** Checks extensions, central phonebook, and SMS contacts before performing paid lookups.
- **Admin Management Page:** Cache statistics, cache purging, and live interactive lookup test.

## Installation
Enable the Community Catalog in AiPBX under **Settings → Plugins**, then install **Caller Name (CNAM)**.

Configure your provider API key under **Settings → Plugins → Caller Name (CNAM)** and click **Apply Changes**.
