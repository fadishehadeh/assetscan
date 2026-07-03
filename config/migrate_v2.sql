-- Asset Manager V2 Migration
-- Run once: mysql -u root asset_manager < migrate_v2.sql

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Check-in / Check-out log
CREATE TABLE IF NOT EXISTS `asset_checkouts` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `asset_id`        INT UNSIGNED NOT NULL,
  `user_id`         INT UNSIGNED NOT NULL COMMENT 'Who holds the asset',
  `checked_out_by`  INT UNSIGNED NOT NULL COMMENT 'Who performed the action',
  `checked_in_by`   INT UNSIGNED DEFAULT NULL,
  `checkout_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expected_return` DATE DEFAULT NULL,
  `actual_return`   DATETIME DEFAULT NULL,
  `condition_out`   ENUM('good','fair','poor') DEFAULT 'good',
  `condition_in`    ENUM('good','fair','poor') DEFAULT NULL,
  `notes_out`       TEXT DEFAULT NULL,
  `notes_in`        TEXT DEFAULT NULL,
  FOREIGN KEY (`asset_id`) REFERENCES `assets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`checked_out_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 2. Transfer Requests
CREATE TABLE IF NOT EXISTS `asset_transfers` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `asset_id`         INT UNSIGNED NOT NULL,
  `from_dept_id`     INT UNSIGNED DEFAULT NULL,
  `to_dept_id`       INT UNSIGNED DEFAULT NULL,
  `from_location_id` INT UNSIGNED DEFAULT NULL,
  `to_location_id`   INT UNSIGNED DEFAULT NULL,
  `requested_by`     INT UNSIGNED NOT NULL,
  `approved_by`      INT UNSIGNED DEFAULT NULL,
  `status`           ENUM('pending','approved','rejected') DEFAULT 'pending',
  `reason`           TEXT NOT NULL,
  `rejection_note`   TEXT DEFAULT NULL,
  `requested_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at`      DATETIME DEFAULT NULL,
  FOREIGN KEY (`asset_id`)     REFERENCES `assets`(`id`)      ON DELETE CASCADE,
  FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`)       ON DELETE RESTRICT,
  FOREIGN KEY (`from_dept_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`to_dept_id`)   REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 3. Asset Documents / Attachments
CREATE TABLE IF NOT EXISTS `asset_documents` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `asset_id`    INT UNSIGNED NOT NULL,
  `name`        VARCHAR(200) NOT NULL,
  `file_path`   VARCHAR(500) NOT NULL,
  `file_size`   INT UNSIGNED DEFAULT 0,
  `mime_type`   VARCHAR(100) DEFAULT NULL,
  `doc_type`    ENUM('invoice','warranty','manual','purchase_order','insurance','other') DEFAULT 'other',
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes`       TEXT DEFAULT NULL,
  FOREIGN KEY (`asset_id`) REFERENCES `assets`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Custom Field Definitions
CREATE TABLE IF NOT EXISTS `custom_fields` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `category_id`    INT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to all categories',
  `field_name`     VARCHAR(100) NOT NULL COMMENT 'snake_case internal key',
  `field_label`    VARCHAR(150) NOT NULL,
  `field_type`     ENUM('text','number','date','select','textarea','url','email') DEFAULT 'text',
  `field_options`  JSON DEFAULT NULL COMMENT 'For select: ["Option A","Option B"]',
  `is_required`    TINYINT(1) DEFAULT 0,
  `sort_order`     TINYINT UNSIGNED DEFAULT 0,
  `is_active`      TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

-- 5. Custom Field Values per Asset
CREATE TABLE IF NOT EXISTS `custom_field_values` (
  `id`       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `asset_id` INT UNSIGNED NOT NULL,
  `field_id` INT UNSIGNED NOT NULL,
  `value`    TEXT DEFAULT NULL,
  UNIQUE KEY `asset_field` (`asset_id`,`field_id`),
  FOREIGN KEY (`asset_id`) REFERENCES `assets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`field_id`) REFERENCES `custom_fields`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. Disposal Requests
CREATE TABLE IF NOT EXISTS `disposal_requests` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `asset_id`         INT UNSIGNED NOT NULL,
  `requested_by`     INT UNSIGNED NOT NULL,
  `approved_by`      INT UNSIGNED DEFAULT NULL,
  `status`           ENUM('pending','approved','rejected') DEFAULT 'pending',
  `reason`           TEXT NOT NULL,
  `disposal_method`  ENUM('donate','scrap','sell','recycle','other') DEFAULT 'scrap',
  `rejection_note`   TEXT DEFAULT NULL,
  `certificate_no`   VARCHAR(50) DEFAULT NULL,
  `requested_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at`      DATETIME DEFAULT NULL,
  FOREIGN KEY (`asset_id`)     REFERENCES `assets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 7. Email Notification Log
CREATE TABLE IF NOT EXISTS `notification_log` (
  `id`       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `type`     VARCHAR(60) NOT NULL COMMENT 'warranty_alert|eol_alert|maintenance_due',
  `asset_id` INT UNSIGNED DEFAULT NULL,
  `sent_to`  VARCHAR(200) DEFAULT NULL,
  `sent_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `details`  JSON DEFAULT NULL
) ENGINE=InnoDB;

-- 8. User Dashboard Widget Preferences
CREATE TABLE IF NOT EXISTS `user_dashboard_prefs` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `widgets`    JSON NOT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `user_id` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Alter maintenance: add scheduling columns
ALTER TABLE `maintenance`
  ADD COLUMN IF NOT EXISTS `is_recurring`     TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `interval_months`  TINYINT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `next_due_date`    DATE DEFAULT NULL;

-- Alter settings: add alert + language columns
ALTER TABLE `settings`
  ADD COLUMN IF NOT EXISTS `alert_email`         VARCHAR(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `notify_warranty`      TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `notify_eol`           TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `notify_maintenance`   TINYINT(1) DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `warranty_alert_days`  TINYINT UNSIGNED DEFAULT 30,
  ADD COLUMN IF NOT EXISTS `app_language`         VARCHAR(10) DEFAULT 'en';

SET FOREIGN_KEY_CHECKS = 1;
