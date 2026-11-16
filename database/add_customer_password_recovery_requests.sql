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
    FOREIGN KEY (`customer_id`) REFERENCES `customer_accounts` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
