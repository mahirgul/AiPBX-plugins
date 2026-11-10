CREATE TABLE IF NOT EXISTS `plg_cnam_cache` (
    `caller_number` VARCHAR(32) NOT NULL,
    `caller_name` VARCHAR(80) NOT NULL,
    `source` VARCHAR(32) NOT NULL DEFAULT 'bulkvs',
    `lookups_count` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`caller_number`),
    INDEX `idx_cnam_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
