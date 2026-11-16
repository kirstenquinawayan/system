CREATE TABLE IF NOT EXISTS `customer_booking_dismissals` (
  `customer_id` int(10) unsigned NOT NULL,
  `booking_id` int(30) NOT NULL,
  `dismissed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`customer_id`, `booking_id`),
  KEY `idx_customer_booking_dismissals_booking` (`booking_id`),
  CONSTRAINT `customer_booking_dismissals_customer_fk`
    FOREIGN KEY (`customer_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_booking_dismissals_booking_fk`
    FOREIGN KEY (`booking_id`) REFERENCES `booking_list` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
