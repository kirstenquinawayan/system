CREATE TABLE `booking_payment_otps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int(30) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'sending',
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_booking_payment_otp_booking_created` (`booking_id`, `date_created`),
  CONSTRAINT `booking_payment_otp_booking_fk`
    FOREIGN KEY (`booking_id`) REFERENCES `booking_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
