-- Auto Dialer Plugin Database Schema (plg_* tables only)

CREATE TABLE IF NOT EXISTS `plg_autodial_campaigns` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'paused',
    `caller_id` VARCHAR(64) NOT NULL DEFAULT '',
    `max_concurrent` INT NOT NULL DEFAULT 2,
    `queue_free_agent_pacing` TINYINT(1) NOT NULL DEFAULT 1,
    `max_retries` INT NOT NULL DEFAULT 2,
    `retry_interval_sec` INT NOT NULL DEFAULT 300,
    `amd_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `amd_machine_action` VARCHAR(20) NOT NULL DEFAULT 'hangup',
    `amd_machine_sound` VARCHAR(160) NOT NULL DEFAULT '',
    `dest_type` VARCHAR(32) NOT NULL DEFAULT 'queue',
    `dest_id` VARCHAR(64) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `plg_autodial_numbers` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `campaign_id` INT NOT NULL,
    `phone_number` VARCHAR(40) NOT NULL,
    `contact_name` VARCHAR(120) NOT NULL DEFAULT '',
    `notes` VARCHAR(255) NOT NULL DEFAULT '',
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `attempts` INT NOT NULL DEFAULT 0,
    `call_result` VARCHAR(32) NOT NULL DEFAULT '',
    `amd_status` VARCHAR(24) NOT NULL DEFAULT '',
    `amd_cause` VARCHAR(64) NOT NULL DEFAULT '',
    `duration` INT NOT NULL DEFAULT 0,
    `uniqueid` VARCHAR(64) NOT NULL DEFAULT '',
    `last_attempt_at` DATETIME DEFAULT NULL,
    `next_attempt_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_camp_status` (`campaign_id`, `status`, `next_attempt_at`),
    INDEX `idx_phone` (`phone_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.3.0: number source, managers and result delivery of a campaign (a table of its own:
-- plugin schemas may only CREATE, so existing installs get the new fields this way).
CREATE TABLE IF NOT EXISTS `plg_autodial_campaign_ext` (
    `campaign_id` INT PRIMARY KEY,
    `owner_user_id` INT DEFAULT NULL,
    `manager_users` VARCHAR(500) NOT NULL DEFAULT '',
    `manager_roles` VARCHAR(500) NOT NULL DEFAULT '',
    `source_type` VARCHAR(10) NOT NULL DEFAULT 'text',
    `db_id` INT DEFAULT NULL,
    `query_sql` TEXT NULL,
    `refresh_min` INT NOT NULL DEFAULT 0,
    `last_refresh_at` DATETIME DEFAULT NULL,
    `last_error` VARCHAR(255) NOT NULL DEFAULT '',
    `webhook` TINYINT(1) NOT NULL DEFAULT 1,
    `writeback_db_id` INT DEFAULT NULL,
    `writeback_sql` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.3.0: per number: its key for duplicates (external_id, else the number), the agent
-- who took the call, and whether its result was delivered (webhook, write-back).
CREATE TABLE IF NOT EXISTS `plg_autodial_number_ext` (
    `number_id` BIGINT PRIMARY KEY,
    `campaign_id` INT NOT NULL,
    `ext_key` VARCHAR(120) NOT NULL,
    `external_id` VARCHAR(120) NOT NULL DEFAULT '',
    `agent` VARCHAR(40) NOT NULL DEFAULT '',
    `reported` TINYINT(1) NOT NULL DEFAULT 0,
    `report_error` VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY `uniq_campaign_key` (`campaign_id`, `ext_key`),
    INDEX `idx_reported` (`reported`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
