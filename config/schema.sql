-- Asset Manager Database Schema
-- PHP 8.3 + MySQL 8

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `departments` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(100) NOT NULL,
  `location`   VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(100) NOT NULL,
  `email`         VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('super_admin','admin','it') NOT NULL DEFAULT 'it',
  `department_id` INT UNSIGNED DEFAULT NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `last_login`    DATETIME DEFAULT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `type`        ENUM('IT','Furniture','Office Equipment','Vehicle','Networking','Software','Other') NOT NULL DEFAULT 'Other',
  `parent_id`   INT UNSIGNED DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  FOREIGN KEY (`parent_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `locations` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `building`      VARCHAR(100) DEFAULT NULL,
  `floor`         VARCHAR(50)  DEFAULT NULL,
  `room`          VARCHAR(50)  DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `assets` (
  `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `asset_tag`            VARCHAR(50) NOT NULL UNIQUE,
  `name`                 VARCHAR(150) NOT NULL,
  `category_id`          INT UNSIGNED DEFAULT NULL,
  `serial_number`        VARCHAR(100) DEFAULT NULL,
  `model`                VARCHAR(100) DEFAULT NULL,
  `brand`                VARCHAR(100) DEFAULT NULL,
  `purchase_date`        DATE DEFAULT NULL,
  `purchase_cost`        DECIMAL(12,2) DEFAULT 0.00,
  `vendor`               VARCHAR(150) DEFAULT NULL,
  `warranty_expiry`      DATE DEFAULT NULL,
  `useful_life_years`    TINYINT UNSIGNED DEFAULT 5,
  `salvage_value`        DECIMAL(12,2) DEFAULT 0.00,
  `depreciation_method`  ENUM('straight_line','declining_balance') DEFAULT 'straight_line',
  `status`               ENUM('active','under_maintenance','disposed','lost') DEFAULT 'active',
  `location_id`          INT UNSIGNED DEFAULT NULL,
  `department_id`        INT UNSIGNED DEFAULT NULL,
  `assigned_to`          INT UNSIGNED DEFAULT NULL,
  `qr_code_path`         VARCHAR(255) DEFAULT NULL,
  `image_path`           VARCHAR(255) DEFAULT NULL,
  `notes`                TEXT DEFAULT NULL,
  `created_by`           INT UNSIGNED DEFAULT NULL,
  `created_at`           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`)   REFERENCES `categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`location_id`)   REFERENCES `locations`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`assigned_to`)   REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`)    REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `depreciation_log` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `asset_id`         INT UNSIGNED NOT NULL,
  `fiscal_year`      YEAR NOT NULL,
  `dep_amount`       DECIMAL(12,2) NOT NULL,
  `book_value_start` DECIMAL(12,2) NOT NULL,
  `book_value_end`   DECIMAL(12,2) NOT NULL,
  `calculated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`asset_id`) REFERENCES `assets`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_asset_year` (`asset_id`, `fiscal_year`)
) ENGINE=InnoDB;

-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `maintenance` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `asset_id`   INT UNSIGNED NOT NULL,
  `type`       ENUM('repair','service','inspection','upgrade') DEFAULT 'repair',
  `date`       DATE NOT NULL,
  `cost`       DECIMAL(10,2) DEFAULT 0.00,
  `vendor`     VARCHAR(150) DEFAULT NULL,
  `notes`      TEXT DEFAULT NULL,
  `logged_by`  INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`asset_id`)  REFERENCES `assets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`logged_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `asset_history` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `asset_id`     INT UNSIGNED NOT NULL,
  `performed_by` INT UNSIGNED DEFAULT NULL,
  `action`       VARCHAR(100) NOT NULL,
  `from_value`   TEXT DEFAULT NULL,
  `to_value`     TEXT DEFAULT NULL,
  `notes`        TEXT DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`asset_id`)     REFERENCES `assets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(100) NOT NULL,
  `table_name` VARCHAR(100) DEFAULT NULL,
  `record_id`  INT UNSIGNED DEFAULT NULL,
  `old_values` JSON DEFAULT NULL,
  `new_values` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_name`        VARCHAR(150) DEFAULT 'My Company',
  `company_logo`        VARCHAR(255) DEFAULT NULL,
  `currency`            VARCHAR(10)  DEFAULT 'USD',
  `fiscal_year_start`   TINYINT UNSIGNED DEFAULT 1,
  `default_dep_method`  ENUM('straight_line','declining_balance') DEFAULT 'straight_line'
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------
-- Default data
INSERT INTO `departments` (`name`, `location`) VALUES
  ('IT Department', 'Building A'),
  ('Finance', 'Building B'),
  ('HR', 'Building B'),
  ('Management', 'Building A');

INSERT INTO `categories` (`name`, `type`) VALUES
  ('Computers & Laptops', 'IT'),
  ('Servers', 'IT'),
  ('Networking Equipment', 'Networking'),
  ('Printers & Scanners', 'Office Equipment'),
  ('Monitors', 'IT'),
  ('Phones & Tablets', 'IT'),
  ('Furniture', 'Furniture'),
  ('Chairs', 'Furniture'),
  ('Vehicles', 'Vehicle'),
  ('Software Licenses', 'Software'),
  ('Office Equipment', 'Office Equipment'),
  ('Other', 'Other');

INSERT INTO `locations` (`building`, `floor`, `room`) VALUES
  ('Main Building', '1', 'Server Room'),
  ('Main Building', '1', 'Reception'),
  ('Main Building', '2', 'IT Office'),
  ('Main Building', '2', 'Finance Office'),
  ('Main Building', '3', 'Management');

INSERT INTO `settings` (`company_name`, `currency`, `fiscal_year_start`, `default_dep_method`)
  VALUES ('My Company', 'USD', 1, 'straight_line');

-- Default Super Admin: admin@company.com / Admin@1234
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `is_active`)
  VALUES ('Super Admin', 'admin@company.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 1);
