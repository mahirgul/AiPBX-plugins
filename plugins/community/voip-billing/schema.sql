-- VoIP Billing plugin tables. Created when enabled, kept when disabled, removed when uninstalled.

CREATE TABLE IF NOT EXISTS plg_billing_providers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    provider VARCHAR(40) NOT NULL DEFAULT 'clientfence',
    base_url VARCHAR(255) NOT NULL,
    api_user VARCHAR(120) NOT NULL DEFAULT 'admin',
    api_key_encrypted TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_test_at DATETIME NULL,
    last_test_status VARCHAR(20) NOT NULL DEFAULT '',
    last_test_message VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plg_billing_customers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id INT UNSIGNED NOT NULL,
    external_client_id VARCHAR(64) NOT NULL,
    customer_name VARCHAR(120) NOT NULL,
    extensions_csv VARCHAR(500) NOT NULL,
    rate_per_minute DECIMAL(10,4) NULL,
    taxed TINYINT(1) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_provider_active (provider_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plg_billing_rates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id INT UNSIGNED NOT NULL,
    prefix VARCHAR(32) NOT NULL,
    label VARCHAR(120) NOT NULL DEFAULT '',
    rate_per_minute DECIMAL(10,4) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_provider_prefix (provider_id, prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plg_billing_invoices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    call_count INT UNSIGNED NOT NULL DEFAULT 0,
    billed_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    external_invoice_id VARCHAR(64) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'created',
    error VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_provider_created (provider_id, id),
    KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plg_billing_calls (
    provider_id INT UNSIGNED NOT NULL,
    linkedid VARCHAR(150) NOT NULL,
    invoice_id INT UNSIGNED NOT NULL DEFAULT 0,
    customer_id INT UNSIGNED NOT NULL,
    extension VARCHAR(32) NOT NULL,
    destination VARCHAR(64) NOT NULL,
    calldate DATETIME NOT NULL,
    billsec INT UNSIGNED NOT NULL DEFAULT 0,
    billed_units INT UNSIGNED NOT NULL DEFAULT 0,
    rate_per_minute DECIMAL(10,4) NOT NULL,
    amount DECIMAL(12,4) NOT NULL,
    PRIMARY KEY (provider_id, linkedid),
    KEY idx_invoice (invoice_id),
    KEY idx_customer_calldate (customer_id, calldate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
