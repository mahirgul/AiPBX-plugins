# AiPBX Plugins Catalog

Official and Community Plugins catalog for [AiPBX](https://github.com/mahirgul/AiPBX) — the open-source, AI-powered enterprise IP PBX.

---

## 🏛️ Official Core Plugins (`plugins/official/`)

Maintained directly by the **AiPBX Core Team**:

| Plugin | Slug | Category | Description |
| :--- | :--- | :--- | :--- |
| **Cloud AI & Speech** | `ai-cloud` | `ai` | Cloud LLM/STT/TTS connectors (OpenAI, Gemini, Groq, NVIDIA NIM, Qwen). |
| **Local AI Models** | `ai-models` | `ai` | Offline on-device voice models (Vosk STT, Piper TTS, Kokoro, Whisper). |
| **Local AI TTS** | `ai-tts` | `ai` | Offline neural Text-To-Speech engine with dynamic voice synthesis. |
| **Voicemail AI Transcribe** | `ai-voicemail` | `ai` | Automated speech-to-text voicemail transcription & summarization. |
| **Interactive AI Apps** | `ai-apps` | `ai` | Voice requests, speech switchboards, and interactive IVR bots. |
| **Predictive Auto-Dialer** | `auto-dialer` | `callcenter` | Outbound call center campaigns with AMD (Answering Machine Detection). |
| **Website Voice Widgets** | `web-widgets` | `integration` | Click-to-call & WebRTC web widgets with daily rate limiting. |
| **Google SSO Sign-In** | `google-auth` | `integration` | Google OAuth2 and One-Tap Single Sign-On for portal and mobile apps. |
| **Microsoft Teams Direct Routing**| `ms-teams` | `integration` | Direct Routing SIP trunking between Asterisk and Microsoft Teams. |

---

## 🌐 Community & External Plugins (`plugins/community/`)

Developed and contributed by third-party and community developers:

| Plugin | Slug | Category | Author | Description |
| :--- | :--- | :--- | :--- | :--- |
| **AI Assistant** | `ai-assistant` | `ai` | [@shido6](https://github.com/shido6) | Natural language PBX dialplan assistant & query tool (`/assistant`). |
| **SIP Signaling Capture** | `signaling-capture` | `telemetry` | [@shido6](https://github.com/shido6) | HEPv3 packet capture daemon and Homer / HEP telemetry integration. |

---

## 🛠️ Plugin Specification (`plugin.json`)

All plugins require a root `plugin.json` following this standard schema:

```json
{
    "slug": "sample-plugin",
    "name": "Sample Plugin",
    "version": "1.0.0",
    "type": "official",
    "author": "Developer or Team Name",
    "author_url": "https://github.com/username",
    "category": "integration",
    "scopes": ["calls:read", "system:read"],
    "hooks": {
        "admin_page": {
            "group": "integrations"
        }
    },
    "settings_schema": []
}
```

### Plugin Types:
- `"type": "official"`: Verified plugins maintained by the AiPBX core team.
- `"type": "community"`: Independent community/third-party plugins.

---

## 🚀 How to Install a Plugin in AiPBX

1. Copy the plugin directory into your AiPBX installation:
   ```bash
   # For official plugins
   cp -r plugins/official/web-widgets /opt/aipbx/plugins/
   
   # For community plugins
   cp -r plugins/community/ai-assistant /opt/aipbx/plugins/
   ```
2. Open the AiPBX Web Portal:
   - Navigate to **Integrations → Plugins**.
   - Locate the plugin and click **Enable**.
   - Configure credentials or options as needed.

---

## 🤝 Contributing New Plugins

Community plugins should be submitted under `plugins/community/<slug>/`. See [`CONTRIBUTORS.md`](CONTRIBUTORS.md) for recognized authors.

---

## 📄 License

GPL-3.0 License. See [LICENSE](LICENSE) for details.
