# AiPBX Plugins Catalog

Catalog of plugins for [AiPBX](https://github.com/mahirgul/AiPBX) — the open-source, AI-powered enterprise IP PBX —
and the home of **community plugins**.

- **Bundled plugins ship with AiPBX itself** (the [`plugins/`](https://github.com/mahirgul/AiPBX/tree/main/plugins)
  folder of the main repository): the official plugins and two community plugins by
  [@shido6](https://github.com/shido6) (`ai-assistant`, `signaling-capture`). They are released and tested together
  with the PBX, so they are not copied here. Report their bugs and send their fixes to the main repository.
- **Community plugins live here**, under [`plugins/community/<slug>/`](plugins/community/).

---

## 🏛️ Bundled plugins (in every AiPBX installation)

Enable them on **Integrations → Plugins** in the portal.

| Plugin | Slug | Category | Type | Description |
| :--- | :--- | :--- | :--- | :--- |
| **Cloud AI & Speech** | [`ai-cloud`](https://github.com/mahirgul/AiPBX/tree/main/plugins/ai-cloud) | `ai` | Official | Cloud LLM/STT/TTS connectors (OpenAI, Gemini, Groq, NVIDIA NIM, Qwen). |
| **Local AI Models** | [`ai-models`](https://github.com/mahirgul/AiPBX/tree/main/plugins/ai-models) | `ai` | Official | Offline on-device voice models (Vosk STT, Piper TTS, Kokoro, Whisper). |
| **Local AI TTS** | [`ai-tts`](https://github.com/mahirgul/AiPBX/tree/main/plugins/ai-tts) | `ai` | Official | Offline neural text-to-speech. |
| **Voicemail AI Transcribe** | [`ai-voicemail`](https://github.com/mahirgul/AiPBX/tree/main/plugins/ai-voicemail) | `ai` | Official | Speech-to-text voicemail transcription & summarization. |
| **Interactive AI Apps** | [`ai-apps`](https://github.com/mahirgul/AiPBX/tree/main/plugins/ai-apps) | `ai` | Official | Voice requests, speech switchboards and interactive IVR bots. |
| **Predictive Auto-Dialer** | [`auto-dialer`](https://github.com/mahirgul/AiPBX/tree/main/plugins/auto-dialer) | `callcenter` | Official | Outbound campaigns with answering machine detection (AMD). |
| **Website Voice Widgets** | [`web-widgets`](https://github.com/mahirgul/AiPBX/tree/main/plugins/web-widgets) | `integration` | Official | Click-to-call & WebRTC web widgets with daily rate limiting. |
| **Google SSO Sign-In** | [`google-auth`](https://github.com/mahirgul/AiPBX/tree/main/plugins/google-auth) | `integration` | Official | Google OAuth2 and One-Tap sign-in for the portal and mobile apps. |
| **Microsoft Teams Direct Routing** | [`ms-teams`](https://github.com/mahirgul/AiPBX/tree/main/plugins/ms-teams) | `integration` | Official | Direct Routing SIP trunking between Asterisk and Microsoft Teams. |
| **AI Assistant** | [`ai-assistant`](https://github.com/mahirgul/AiPBX/tree/main/plugins/ai-assistant) | `ai` | Community — [@shido6](https://github.com/shido6) | Plain-language PBX dialplan assistant (`/assistant`). |
| **SIP Signaling Capture** | [`signaling-capture`](https://github.com/mahirgul/AiPBX/tree/main/plugins/signaling-capture) | `telemetry` | Community — [@shido6](https://github.com/shido6) | HEPv3 capture daemon and Homer/HEP integration. |

## 🌐 Community plugins (this repository)

| Plugin | Slug | Category | Author | Description |
| :--- | :--- | :--- | :--- | :--- |
| *Yours could be the first one — see [Contributing](#-contributing-a-community-plugin).* | | | | |

---

## 🚀 Installing a community plugin

1. Download the plugin folder as a `.zip` (with `plugin.json` at the root of the archive).
2. In the AiPBX portal open **Integrations → Plugins → Upload Custom Plugin (`.zip`)**, upload it and click **Enable**.

Uploaded plugins are stored in `/var/lib/aipbx/plugins/` and survive updates.
**Do not copy plugins into `/opt/aipbx`:** that folder is the PBX's own git checkout — any change there makes
`aipbx-update` refuse to update ("Code was modified by hand").

AiPBX shows a plugin as **Official** only when it ships with AiPBX; every uploaded plugin is shown as
**Community**, whatever its `plugin.json` says.

---

## 🛠️ Writing a plugin

The plugin format (`plugin.json`, hooks, `plg_*` database tables, settings, public endpoints, permissions) is
documented in the main repository: **[docs/plugin-development.md](https://github.com/mahirgul/AiPBX/blob/main/docs/plugin-development.md)**.

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
