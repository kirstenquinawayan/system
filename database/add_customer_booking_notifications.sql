CREATE TABLE `customer_booking_notifications` (
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
