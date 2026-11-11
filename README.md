# AiPBX Plugins Catalog

Catalog of plugins for [AiPBX](https://github.com/mahirgul/AiPBX) — the open-source, AI-powered enterprise IP PBX —
and the home of **community plugins**.

- **Bundled plugins ship with AiPBX itself** (the [`plugins/`](https://github.com/mahirgul/AiPBX/tree/main/plugins)
  folder of the main repository). They are released and tested together
  with the PBX, so they are not copied here. Report their bugs and send their fixes to the main repository.
- **Community plugins live here**, under [`plugins/community/<slug>/`](plugins/community/).

---

## 🏛️ Bundled plugins (in every AiPBX installation)

Enable them on **Integrations → Plugins** in the portal.

| Plugin | Slug | Category | Type | Description |
| :--- | :--- | :--- | :--- | :--- |
| **Cloud AI services** | [`ai-cloud`](https://github.com/mahirgul/AiPBX/tree/main/plugins/ai-cloud) | `ai` | Official | Cloud AI providers in one place: Gemini/Gemma, OpenAI, Azure, Deepgram, ElevenLabs, Amazon Polly, Groq, NVIDIA NIM, Qwen or any OpenAI-compatible service (speech, speech to text, language models). |
| **Local AI models** | [`ai-models`](https://github.com/mahirgul/AiPBX/tree/main/plugins/ai-models) | `ai` | Official | AI on the PBX itself, offline: Turkish EMA Lightning and Piper voices, Kokoro English voices, Vosk and Whisper speech recognition. |
| **Cloud TTS (announcement studio)** | [`ai-tts`](https://github.com/mahirgul/AiPBX/tree/main/plugins/ai-tts) | `ai` | Official | Text to speech for IVR and queue announcements with cloud or local engines; saves the result as an announcement. |
| **AI applications** | [`ai-apps`](https://github.com/mahirgul/AiPBX/tree/main/plugins/ai-apps) | `ai` | Official | AI applications with their own numbers that answer calls, do their job and pass the call on (usable as IVR / route destinations). |
| **Website call widgets** | [`web-widgets`](https://github.com/mahirgul/AiPBX/tree/main/plugins/web-widgets) | `integration` | Official | "Call us" buttons for websites over WebRTC, callback form, WordPress plugin. |
| **Sign in with Google** | [`google-auth`](https://github.com/mahirgul/AiPBX/tree/main/plugins/google-auth) | `integration` | Official | Google Workspace / Gmail sign-in (OAuth 2.0) for the portal and the mobile apps. |
| **OpenID Connect SSO** | [`oidc-auth`](https://github.com/mahirgul/AiPBX/tree/main/plugins/oidc-auth) | `integration` | Official | Corporate sign-in through an OpenID Connect identity provider. |
| **SAML SSO** | [`saml-auth`](https://github.com/mahirgul/AiPBX/tree/main/plugins/saml-auth) | `integration` | Official | Corporate SAML 2.0 sign-in. |
| **Microsoft Teams** | [`ms-teams`](https://github.com/mahirgul/AiPBX/tree/main/plugins/ms-teams) | `integration` | Official | Direct Routing: AiPBX as the SBC for Microsoft Teams, plus call webhooks. |

## 🌐 Community plugins (this repository)

| Plugin | Slug | Category | Author | Description |
| :--- | :--- | :--- | :--- | :--- |
| **Auto Dialer & AMD Campaigns** | [`auto-dialer`](plugins/community/auto-dialer/) | `integration` | AiPBX | Outbound campaigns, AMD and queue pacing. AiPBX 1.72.12+. |
| **AI PBX Assistant** | [`ai-assistant`](plugins/community/ai-assistant/) | `ai` | [@shido6](https://github.com/shido6) | Preview and confirm REST API requests in Turkish or English. AiPBX 1.72.12+. |
| **AI Voicemail** | [`ai-voicemail`](plugins/community/ai-voicemail/) | `ai` | AiPBX | Summaries and transcripts of completed voicemail transcriptions. AiPBX 1.72.12+. |
| **Caller Name (CNAM)** | [`cnam`](plugins/community/cnam/) | `integration` | Community | Inbound caller-name lookup with local contacts, provider lookup and caching. AiPBX 1.72.12+. |
| **SIP Provider Templates** | [`provider-templates`](plugins/community/provider-templates/) | `integration` | Community | Create a SIP trunk from a provider template. Adding a provider takes one small file, no code. |
| **SMS / MMS** | [`sms`](plugins/community/sms/) | `integration` | Community | Text messages on your numbers through Twilio, Vonage, Plivo, Telnyx, Bandwidth, Sinch, VoIP.ms or BulkVS; the users a number is given to answer them. AiPBX 1.71+. |

**Installing:** on AiPBX 1.72+, tick **Show community plugins from the catalog** on **Integrations → Plugins**
and install or update them there; the CI of this repository publishes them on the release
[`catalog`](https://github.com/mahirgul/aipbx-plugins/releases/tag/catalog). Older versions: upload the
plugin's folder as a `.zip`.

**Signed catalog:** CI signs `catalog.json` with an Ed25519 key that is in no repository (the
Actions secret `CATALOG_SIGNING_KEY`) and publishes the signature as `catalog.json.sig`. AiPBX 1.74+
uses no catalog without a valid signature, and none older than the one it already has. The public key
is `zHUKaO/y9dxwJhPqfYOXA+S0Apw4Fu4oUPN5Jpyd6Bc=`. To check by hand:

```bash
php -r '$d = "https://github.com/mahirgul/aipbx-plugins/releases/download/catalog/";
var_dump(sodium_crypto_sign_verify_detached(base64_decode(trim(file_get_contents($d . "catalog.json.sig"))),
    file_get_contents($d . "catalog.json"), base64_decode("zHUKaO/y9dxwJhPqfYOXA+S0Apw4Fu4oUPN5Jpyd6Bc=")));'
```

The signature says the list came from this repository's maintainer. It is not a review of what a
plugin does.

**Starting a plugin?** [`examples/sample-plugin`](examples/sample-plugin/) uses every hook in a few lines and
does not touch calls: copy it, change the slug and delete what you do not need.

---

## 🚀 Installing a community plugin

1. Download the plugin folder as a `.zip` (with `plugin.json` at the root of the archive).
2. In the AiPBX portal open **Integrations → Plugins → Upload Custom Plugin (`.zip`)**, upload it and click **Enable**.
   Uploading needs AiPBX **1.50.3** or newer; older versions fail with *"Failed to move extracted plugin"*.

Uploaded plugins are stored in `/var/lib/aipbx/plugins/` and survive core updates. Core 1.73+ moves
previously bundled Auto Dialer, AI Assistant and AI Voicemail copies there using offline transition
packages, preserving their state and settings. Fresh PBX installs obtain these plugins from this
catalog. Respect the `requires_aipbx` in each plugin manifest; the upload feature's minimum version
is not the minimum supported core version of every plugin.
**Keep plugin installations out of `/opt/aipbx`:** it is the managed PBX checkout. Use the catalog or
ZIP installer so plugin code stays in `/var/lib/aipbx/plugins` and core updates can manage their own files.

AiPBX shows a plugin as **Official** only when it ships with AiPBX; every uploaded plugin is shown as
**Community**, whatever its `plugin.json` says.

---

## 🛠️ Writing a plugin

The plugin format (`plugin.json`, hooks, `plg_*` database tables, settings, public endpoints, permissions) is
documented in the main repository: **[docs/plugin-development.md](https://github.com/mahirgul/AiPBX/blob/main/docs/plugin-development.md)**.

Before submitting a new plugin or update, follow the **[development requirements and release checklist](https://github.com/mahirgul/AiPBX/blob/main/docs/plugin-development.md#6-development-requirements-and-release-checklist)**.
Include the applicable test evidence in the plugin README or pull request, and distinguish mocked tests
from provider sandbox/live tests.

Start from **[examples/sample-plugin](examples/sample-plugin/)**: a plugin that uses every hook in a few lines
and does not touch calls. Copy it, change the slug and delete what you do not need.

---

## 🤝 Contributing a community plugin

- Add it as `plugins/community/<slug>/` (the folder name is the slug: lowercase letters, digits and `-`).
- **One plugin per pull request**, and one topic per pull request: a new plugin, or one fix/feature of an
  existing one. Unrelated changes in the same PR will be asked to be split.
- Include a `README.md` in the plugin folder: what it does, its settings, and how you tested it on AiPBX.
- No secrets, API keys or personal data in the code. Do not commit build output (`__pycache__/`, `*.pyc`,
  `node_modules/`, `vendor/`).
- Changes to the bundled plugins above go to the [main repository](https://github.com/mahirgul/AiPBX).

Contributors are listed in [CONTRIBUTORS.md](CONTRIBUTORS.md).

---

## 📄 License

MIT License. See [LICENSE](LICENSE) for details.

---

## ☁️ Cloud Infrastructure Partner

[![UpCloud](https://img.shields.io/badge/UpCloud-$250_Free_Credit-7b1fa2?style=flat&logo=cloud&logoColor=white)](https://signup.upcloud.com/?promo=affiliate-aipbx250&amp;utm_source=aipbx&amp;utm_medium=affiliate&amp;utm_campaign=affiliate-aipbx250)

Deploy and test your AiPBX plugins on high-performance [UpCloud](https://signup.upcloud.com/?promo=affiliate-aipbx250&amp;utm_source=aipbx&amp;utm_medium=affiliate&amp;utm_campaign=affiliate-aipbx250) cloud instances with MaxIOPS storage. Get **$250 in free cloud credits** with promo code `affiliate-aipbx250`.

