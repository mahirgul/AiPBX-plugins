# Sample Plugin

A plugin that does not touch calls. It uses every hook a plugin can have, each in a few lines,
so you can see how they fit together. Copy the folder, change the slug, delete the hooks you do not
need and start from there.

The full reference is [docs/plugin-development.md](https://github.com/mahirgul/AiPBX/blob/main/docs/plugin-development.md)
in the main repository.

## Files

| File | What it is | When it runs |
| :--- | :--- | :--- |
| `plugin.json` | The manifest: slug, name, icon, hooks, settings. The only required file. | Read on every page load |
| `schema.sql` | Your own `plg_*` tables. | On enable (created), on uninstall (dropped) |
| `events/handler.php` | Receives the events listed in `hooks.events` (here `call.ended`) and stores a line per call. | A few seconds after the event, in `aipbx-webhook-worker` |
| `worker/tick.php` | Background job with its own clock (here: count once a minute). | Every ~2 s in `aipbx-webhook-worker` |
| `public/handler.php` | An HTTP endpoint at `/api/plugins/sample-plugin/<path>`. **No sign-in.** | On each request |
| `sync/Sync.php` | Writes `extensions_plugin_sample-plugin.conf` with a context nothing uses. | On **Apply** (general dialplan) and full sync |
| `admin/action.php` | Handles the page's own buttons (POST, with CSRF check). | Before the page is drawn |
| `admin/page.php` | The plugin's page under the settings form. | On `/plugins/sample-plugin` |

The settings form on the plugin page is built by the portal from `settings_schema`; the sample has
one field of each type (`text`, `number`, `boolean`, `select`, `textarea`, `password` with `secret`).
Every hook receives the settings as an array, secrets already decrypted.

## What it changes

Nothing outside itself: its own table `plg_sample_events` (at most 50 rows), one key in the
key-value store (`state/last_tick`) and the file `/etc/asterisk/pbx/extensions_plugin_sample-plugin.conf` with the
context `[sample-plugin-hello]`. No route points to that context, so it never runs; to hear it,
send a **Custom destination** to `sample-plugin-hello,s,1`.

## Try it

```bash
cd examples/sample-plugin && zip -r ../../sample-plugin.zip . && cd ../..
```

Uploading needs AiPBX **1.50.3** or newer: on older versions every `.zip` upload fails with
*"Failed to move extracted plugin into plugins directory"*.

1. Portal: **Integrations → Plugins → Upload Custom Plugin (`.zip`)**, choose `sample-plugin.zip`.
   The portal checks the archive (paths, `plugin.json`, `php -l` of every PHP file), installs it in
   `/var/lib/aipbx/plugins/sample-plugin/`, enables it and adds **Sample Plugin** to the sidebar
   under *Integrations* (`hooks.admin_page.group`).
2. Open the page: the worker counter goes up once a minute.
3. Make a call; after it ends it is listed on the page.
4. `curl -k https://<pbx>/api/plugins/sample-plugin/ping` returns the greeting from the settings.
5. Click **Apply** once; `asterisk -rx "dialplan show sample-plugin-hello"` shows the context.
6. **Disable** keeps the table and the settings and removes the `.conf` file (from AiPBX 1.50.3
   Asterisk drops the context at once, before only on the next Apply); **Uninstall** also drops
   the table, the settings and the key-value data.

## Rules worth knowing

- **Fault isolation:** an exception in a hook is caught and written to **Security → Audit Log**.
  Five in a row disable the plugin (*Safe Mode*).
- **Public endpoints are public.** Check the caller yourself (a token in a setting, a signature,
  or loopback only as `status` does here; not enough behind a reverse proxy on the same host).
- **Use the slug you are given** (`$slug`, `$plugin['slug']`), not a fixed string, so a copy
  renamed to another slug keeps working.
- **Escape what you print** in `admin/page.php` and check CSRF for your own forms, as the sample does.
- **Never write core tables** (`pbx_*`, `sys_*`) with SQL. Keep your data in your `plg_*` tables,
  your settings and the key-value store (`PluginManager::kvSet()` and friends).
- **Texts:** the portal does not translate plugins. Use `name_i18n`, `description_i18n`,
  `label_i18n` and `help_i18n` (language code → text) for other languages.
- **Do not copy plugins into `/opt/aipbx`**: that is the PBX's git checkout, and `aipbx-update`
  refuses to update a modified tree. Uploaded plugins live in `/var/lib/aipbx/plugins/` and survive
  updates.
