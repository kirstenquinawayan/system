ALTER TABLE `booking_list`
  ADD COLUMN `payment_method` varchar(20) DEFAULT NULL AFTER `payment_status`;

CREATE TABLE `booking_online_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int(30) NOT NULL,
  `payment_type` varchar(20) NOT NULL,
  `payment_provider` varchar(20) NOT NULL DEFAULT 'demo_online',
  `amount_cents` int unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'creating',
  `transaction_ref` varchar(100) DEFAULT NULL,
  `verified_by` int(30) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `rejected_by` int(30) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `paid_at` datetime DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_booking_online_payment_status` (`booking_id`, `status`),
  UNIQUE KEY `uq_booking_online_payment_provider_ref` (`payment_provider`, `transaction_ref`),
  CONSTRAINT `booking_online_payment_booking_fk`
    FOREIGN KEY (`booking_id`) REFERENCES `booking_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
