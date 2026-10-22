-- Created when the plugin is enabled, kept when it is disabled, dropped when it
-- is uninstalled. Every table must start with plg_ and be listed under "tables"
-- in plugin.json; DROP, ALTER, TRUNCATE and core tables are refused.
CREATE TABLE IF NOT EXISTS plg_sample_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_name VARCHAR(64) NOT NULL,
    summary VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
