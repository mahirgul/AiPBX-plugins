# AiPBX Plugins

Official and Community Plugins catalog for [AiPBX](https://github.com/mahirgul/AiPBX) — the open-source, AI-powered enterprise IP PBX.

---

## 📦 Available Plugins

| Plugin | Slug | Category | Description | Author / Credits |
| :--- | :--- | :--- | :--- | :--- |
| **AI Assistant** | `ai-assistant` | `ai` | Natural language PBX dialplan assistant & query tool (`/assistant`). | [shido6](https://github.com/shido6) |
| **SIP Signaling Capture** | `signaling-capture` | `telemetry` | HEPv3 packet capture daemon and Homer / HEP telemetry integration. | [shido6](https://github.com/shido6) |

---

## 🛠️ Plugin Architecture

AiPBX plugins extend the core phone system without touching core Asterisk dialplans or core routing tables.

Each plugin is organized in a self-contained folder:

```
plugin-name/
├── plugin.json        # Metadata, permission scopes, hooks and settings schema
├── README.md          # Plugin installation and usage instructions
├── admin/             # Portal admin UI panels (optional)
├── events/            # Webhook & internal event handlers (call.started, call.ended, etc.)
└── sync/              # Dialplan / Asterisk config generation hooks
```

### `plugin.json` Specification

```json
{
    "slug": "sample-plugin",
    "name": "Sample Plugin",
    "version": "1.0.0",
    "author": "Developer Name",
    "category": "telemetry",
    "scopes": ["calls:read", "system:read"],
    "hooks": {
        "sync_domain": "general_dialplan",
        "events": ["call.started", "call.ended"],
        "admin_page": {
            "group": "integrations"
        }
    },
    "settings_schema": [...]
}
```

---

## 🚀 How to Install a Plugin in AiPBX

1. Copy the plugin folder into `/opt/aipbx/plugins/`:
   ```bash
   cp -r plugins/my-plugin /opt/aipbx/plugins/
   ```
2. Open the AiPBX Web Portal:
   - Navigate to **Integrations → Plugins**.
   - Locate the plugin and click **Enable**.
   - Configure any required settings in the plugin settings modal.

---

## 🤝 Contributing Plugins

We welcome community contributions! To submit a new plugin:

1. Fork this repository.
2. Create a new directory under `plugins/<your-plugin-slug>/`.
3. Ensure a valid `plugin.json` and `README.md` are included.
4. Open a Pull Request against `main`.

All plugin authors are recognized in [`CONTRIBUTORS.md`](CONTRIBUTORS.md).

---

## 📄 License

GPL-3.0 License. See [LICENSE](LICENSE) for details.
