-- =============================================
-- HCLOU SERVER - DATABASE SCHEMA
-- Import vào database hcloucom_panel hoặc database bạn cấu hình trong config.php
-- =============================================

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT UNIQUE NOT NULL,
    telegram_username VARCHAR(100),
    full_name VARCHAR(200),
    avatar_url VARCHAR(500),
    balance DECIMAL(12,0) DEFAULT 0,
    credit DECIMAL(12,0) DEFAULT 0,
    plan_id INT DEFAULT NULL,
    plan_expires_at TIMESTAMP NULL,
    keys_used INT DEFAULT 0,
    packages_used INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS games (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    package_name VARCHAR(200) NOT NULL,
    icon_url VARCHAR(500),
    type ENUM('VIP','NORMAL') DEFAULT 'NORMAL',
    root_type VARCHAR(50) DEFAULT 'Only Root',
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    days INT NOT NULL,
    duration_hours INT DEFAULT NULL,
    price DECIMAL(12,0) NOT NULL,
    price_per_device DECIMAL(12,0) DEFAULT NULL,
    key_type ENUM('Normal','VIP') DEFAULT 'Normal',
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_packages_game_active (game_id, is_active),
    CONSTRAINT fk_packages_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(50) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    game_id INT NOT NULL,
    package_id INT NOT NULL,
    amount DECIMAL(12,0) NOT NULL,
    status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
    payment_proof TEXT,
    admin_note TEXT,
    approved_at TIMESTAMP NULL,
    approved_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_orders_user_status (user_id, status),
    INDEX idx_orders_status_created (status, created_at),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_orders_game FOREIGN KEY (game_id) REFERENCES games(id),
    CONSTRAINT fk_orders_package FOREIGN KEY (package_id) REFERENCES packages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `keys` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_code VARCHAR(100) UNIQUE NOT NULL,
    user_id INT,
    game_id INT NOT NULL,
    package_id INT NOT NULL,
    order_id INT,
    status ENUM('pending','active','expired','locked') DEFAULT 'pending',
    days INT NOT NULL,
    duration_hours INT DEFAULT NULL,
    reset_count INT DEFAULT 0,
    max_reset INT DEFAULT 3,
    max_devices INT DEFAULT 1,
    devices TEXT DEFAULT NULL,
    start_at TIMESTAMP NULL,
    expire_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_keys_user_status (user_id, status),
    INDEX idx_keys_order_status (order_id, status),
    CONSTRAINT fk_keys_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_keys_game FOREIGN KEY (game_id) REFERENCES games(id),
    CONSTRAINT fk_keys_package FOREIGN KEY (package_id) REFERENCES packages(id),
    CONSTRAINT fk_keys_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT UNIQUE NOT NULL,
    username VARCHAR(100),
    role ENUM('superadmin','admin') DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS free_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_code VARCHAR(100) NOT NULL,
    game_id INT NOT NULL,
    package_id INT NOT NULL,
    days INT NOT NULL,
    key_type ENUM('Normal','VIP') DEFAULT 'VIP',
    is_active TINYINT(1) DEFAULT 1,
    start_at DATETIME NOT NULL,
    expire_at DATETIME NOT NULL,
    claim_token VARCHAR(80) UNIQUE NOT NULL,
    short_url TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_free_keys_active_game (is_active, game_id, expire_at),
    CONSTRAINT fk_free_keys_game FOREIGN KEY (game_id) REFERENCES games(id),
    CONSTRAINT fk_free_keys_package FOREIGN KEY (package_id) REFERENCES packages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS free_key_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    free_key_id INT NOT NULL,
    user_id INT NOT NULL,
    key_id INT,
    claimed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_free_key_once (free_key_id),
    INDEX idx_free_claims_user (user_id),
    CONSTRAINT fk_free_claims_free_key FOREIGN KEY (free_key_id) REFERENCES free_keys(id) ON DELETE CASCADE,
    CONSTRAINT fk_free_claims_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_free_claims_key FOREIGN KEY (key_id) REFERENCES `keys`(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed tối thiểu theo hệ thống hiện tại
INSERT IGNORE INTO admins (telegram_id, username, role) VALUES
(1985248892, 'hcloucom', 'superadmin');

INSERT IGNORE INTO games (id, name, package_name, icon_url, type, root_type, is_active, sort_order) VALUES
(1, 'Liên Quân Mobile', 'com.garena.game.kgvn', NULL, 'VIP', 'Only Root', 0, 1),
(3, 'Free Fire', 'com.dts.freefireth', NULL, 'NORMAL', 'Only Root', 0, 3),
(4, 'Free Fire Max', 'com.dts.freefiremax', 'https://play-lh.googleusercontent.com/EJ83sg58Oo2gAjMHFxFVLM6Z53kuH4_R0M7Yq7gts5fWSIlFchUlmskG1vJKMoncmfOxBXcgJyIaO-nak6sO-MM=s128', 'VIP', 'Root & NoRoot', 1, 4);

INSERT IGNORE INTO packages (id, game_id, name, days, duration_hours, price, price_per_device, key_type, is_active) VALUES
(8, 4, 'Gói 1 ngày', 1, 24, 25000, 25000, 'VIP', 1),
(9, 4, 'Gói 7 ngày', 7, 168, 75000, 75000, 'VIP', 1),
(10, 4, 'Gói 30 ngày', 30, 720, 120000, 120000, 'VIP', 1);


CREATE TABLE IF NOT EXISTS bank_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tx_hash CHAR(64) NOT NULL UNIQUE,
  tx_date VARCHAR(32) NOT NULL,
  amount DECIMAL(12,0) NOT NULL,
  description TEXT NOT NULL,
  order_code VARCHAR(50) DEFAULT NULL,
  status ENUM('seen','matched','approved','ignored','error') DEFAULT 'seen',
  note TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_order_code (order_code),
  INDEX idx_status (status),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS admin_config_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin VARCHAR(100) NOT NULL,
  config_key VARCHAR(100) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_config_logs_created (created_at),
  INDEX idx_config_logs_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cron ngoài hiện dùng cron-job.org gọi cron_run.php với CRON_RUN_TOKEN:
--   /cron_run.php?token=<CRON_RUN_TOKEN>&job=mbbank      -> mbbank_poll.php
--   /cron_run.php?token=<CRON_RUN_TOKEN>&job=maintenance -> maintenance.php
--   /cron_run.php?token=<CRON_RUN_TOKEN>&job=automation  -> automation_daily.php
--   /cron_run.php?token=<CRON_RUN_TOKEN>&job=backup      -> /www/backup/hclou_db/backup.sh

-- =============================================
-- PLANS SYSTEM (authtool.app style)
-- =============================================

CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    price DECIMAL(12,0) NOT NULL,
    duration_days INT DEFAULT 30,
    max_keys INT DEFAULT 100,
    max_packages INT DEFAULT 10,
    max_devices_per_key INT DEFAULT 3,
    features JSON,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed plans
INSERT INTO plans (name, price, duration_days, max_keys, max_packages, max_devices_per_key, sort_order) VALUES
('Free', 0, 30, 10, 2, 1, 1),
('Basic', 100000, 30, 100, 10, 3, 2),
('Pro', 500000, 30, 1000, 50, 10, 3),
('Enterprise', 2000000, 30, 99999, 999, 99, 4);

-- Credit transactions
CREATE TABLE IF NOT EXISTS credit_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(12,0) NOT NULL,
    type ENUM('deposit','purchase','refund','bonus') NOT NULL,
    description TEXT,
    reference VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_created (user_id, created_at),
    CONSTRAINT fk_credit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User plans history
CREATE TABLE IF NOT EXISTS user_plan_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    keys_used INT DEFAULT 0,
    packages_used INT DEFAULT 0,
    status ENUM('active','expired','cancelled') DEFAULT 'active',
    INDEX idx_user_status (user_id, status),
    CONSTRAINT fk_plan_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_history_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

