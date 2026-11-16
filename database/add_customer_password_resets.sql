CREATE TABLE IF NOT EXISTS `customer_password_resets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned NOT NULL,
  `method` enum('email','sms') NOT NULL,
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
    FOREIGN KEY (`customer_id`) REFERENCES `customer_accounts` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
