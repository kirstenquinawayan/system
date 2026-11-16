-- Customer portal prerequisites. Safe to re-run on MariaDB 10.11+.
-- Apply manually after backing up the database; this script does not copy
-- historical bookings into customer accounts.

CREATE TABLE IF NOT EXISTS `customer_accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(250) NOT NULL,
  `email` varchar(254) NOT NULL,
  `contact` varchar(32) NOT NULL DEFAULT '',
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_accounts_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `booking_list`
  ADD COLUMN IF NOT EXISTS `customer_id` int(10) unsigned DEFAULT NULL AFTER `email`;

CREATE INDEX IF NOT EXISTS `idx_booking_customer_schedule`
  ON `booking_list` (`customer_id`, `schedule`, `id`);

SET @customer_booking_fk_sql = IF(
  (SELECT COUNT(*) FROM information_schema.referential_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'booking_list'
     AND constraint_name = 'booking_customer_account_fk') = 0,
  'ALTER TABLE `booking_list` ADD CONSTRAINT `booking_customer_account_fk` FOREIGN KEY (`customer_id`) REFERENCES `customer_accounts` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE customer_booking_fk_stmt FROM @customer_booking_fk_sql;
EXECUTE customer_booking_fk_stmt;
DEALLOCATE PREPARE customer_booking_fk_stmt;

CREATE TABLE IF NOT EXISTS `customer_booking_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int(30) NOT NULL,
  `customer_id` int(10) unsigned NOT NULL,
  `status` tinyint(3) unsigned NOT NULL,
  `message` varchar(500) NOT NULL,
  `appointment_at` datetime NOT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  `dismissed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customer_booking_notifications_unread` (`customer_id`, `dismissed_at`, `read_at`, `date_created`),
  KEY `idx_customer_booking_notifications_booking` (`booking_id`),
  CONSTRAINT `customer_booking_notifications_booking_fk`
    FOREIGN KEY (`booking_id`) REFERENCES `booking_list` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_booking_notifications_customer_fk`
    FOREIGN KEY (`customer_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `customer_booking_notifications`
  ADD COLUMN IF NOT EXISTS `dismissed_at` datetime DEFAULT NULL AFTER `read_at`;

CREATE INDEX IF NOT EXISTS `idx_customer_booking_notifications_unread_v2`
  ON `customer_booking_notifications` (`customer_id`, `dismissed_at`, `read_at`, `date_created`);

CREATE TABLE IF NOT EXISTS `customer_password_recovery_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned NOT NULL,
  `status` enum('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_customer_password_recovery_status` (`status`,`requested_at`),
  KEY `idx_customer_password_recovery_customer` (`customer_id`,`status`,`requested_at`),
  CONSTRAINT `fk_customer_password_recovery_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `customer_password_resets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned NOT NULL,
  `method` enum('email','sms') NOT NULL DEFAULT 'email',
  `token_hash` char(64) DEFAULT NULL,
  `otp_hash` varchar(255) DEFAULT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_password_resets_token` (`token_hash`),
  KEY `idx_customer_password_resets_customer_created` (`customer_id`,`created_at`),
  KEY `idx_customer_password_resets_expiry` (`expires_at`),
  CONSTRAINT `fk_customer_password_resets_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
