-- SMS/MMS plugin. Created when the plugin is enabled, kept when it is disabled, dropped when it is uninstalled.

-- A number (E.164, e.g. +15551234567) and the provider it is with.
CREATE TABLE IF NOT EXISTS plg_sms_numbers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    number VARCHAR(20) NOT NULL,
    provider VARCHAR(20) NOT NULL,
    label VARCHAR(60) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uniq_number (number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who reads and writes the messages of a number.
CREATE TABLE IF NOT EXISTS plg_sms_access (
    number_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (number_id, user_id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One message; a conversation is one number with one peer.
CREATE TABLE IF NOT EXISTS plg_sms_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    number_id INT UNSIGNED NOT NULL,
    peer VARCHAR(32) NOT NULL,
    direction ENUM('in', 'out') NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT '',
    error VARCHAR(255) NOT NULL DEFAULT '',
    provider_id VARCHAR(100) NOT NULL DEFAULT '',
    user_id INT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_thread (number_id, peer, id),
    KEY idx_provider_id (provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MMS pictures and files, kept here: providers' own links need their sign-in or expire.
-- The token makes the public link a provider fetches for an outgoing MMS.
CREATE TABLE IF NOT EXISTS plg_sms_media (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id INT UNSIGNED NOT NULL,
    content_type VARCHAR(100) NOT NULL,
    name VARCHAR(120) NOT NULL DEFAULT '',
    data MEDIUMBLOB NOT NULL,
    token CHAR(32) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Names of peers, shared by the users of a number.
CREATE TABLE IF NOT EXISTS plg_sms_contacts (
    number_id INT UNSIGNED NOT NULL,
    phone VARCHAR(32) NOT NULL,
    name VARCHAR(80) NOT NULL,
    PRIMARY KEY (number_id, phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
