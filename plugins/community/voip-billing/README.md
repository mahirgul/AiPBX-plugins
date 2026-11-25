# VoIP Billing

Multi-account VoIP call billing and draft invoice creation for AiPBX (currently supporting **ClientFence** / FOSSBilling). Needs **AiPBX 1.71.0** or newer (`CdrCallAnalyzer`, `SecretBox`, `PluginManager`).

## Features

- **Multi-account billing providers**: configure one or more ClientFence HTTPS endpoints with encrypted API keys (`SecretBox`), active/disabled toggles, and connection testing.
- **Customer & extension mappings**: map numeric PBX extensions (e.g. `3001,3002`) to external ClientFence client IDs with optional per-customer rate and tax overrides.
- **Longest-prefix rate lookup**: normalizes `+` and leading `00` prefixes to match the longest configured prefix rate (`plg_billing_rates`), falling back to customer rate override and global `default_rate_per_minute`.
- **Multi-leg CDR accuracy**: inspects all legs per `linkedid` using `CdrCallAnalyzer` so only answered **outbound** calls from active mapped customer extensions are billed; inbound, internal, and transit calls are excluded.
- **Billing increments**: supports `60/60` (per minute, rounded up) and `1/1` (exact seconds).
- **Draft tracking**: commits a unique reference and reserves the calls before sending the draft. A confirmed rejection releases the calls. If the response is lost, the calls stay reserved. Find the reference above the line items in ClientFence and enter the invoice ID under **Recent Draft Invoices → Match invoice**.

## ClientFence Setup

1. In ClientFence, create an Admin API Key with access to Clients and Invoices and the **`client.view`**, **`invoice.view`** and **`invoice.manage_invoices`** permissions.
2. In AiPBX, open **Integrations → VoIP Billing**, adjust global defaults (`default_currency`, `default_rate_per_minute`, `billing_increment_seconds`, `default_taxed`), and add a **ClientFence** provider:
   - **HTTPS Base URL**: public `https://...` URL of your ClientFence instance (no embedded credentials, query strings, or private/loopback addresses).
   - **API User**: defaults to `admin` when left blank.
   - **API Key**: encrypted at rest using AiPBX `SecretBox`.
3. Click **Test** to verify `POST /api/admin/invoice/get_list`.
4. Add **Customer & Extension Mappings** (matching numeric extensions to ClientFence `client_id`) and optional **Destination Prefix Rates**.
5. Under **Usage Preview & Draft Invoices**, choose a provider and date range, preview unbilled outbound calls, and click **Create Draft Invoices** (calls `POST /api/admin/invoice/prepare` with `"approve": false`).

AiPBX checks the ClientFence customer's configured currency against the tariff currency before submitting each draft. The preview subtotal uses the same rounded lines sent to ClientFence. ClientFence applies its tax settings to the draft.

## Verification

Syntax, SSRF/DNS pinning, mocked ClientFence API behavior, CDR multi-leg analysis, prefix matching, and `schema.sql` validation:

```sh
find plugins/community/voip-billing -name '*.php' -print0 | xargs -0 -n1 php -l
php plugins/community/voip-billing/tests/billing.php
```

> Note: `tests/billing.php` uses mocked HTTP responses (`BillingProvider::$http`) and an isolated DNS subprocess check; it does not send requests to a live ClientFence server or require a live MariaDB instance.
