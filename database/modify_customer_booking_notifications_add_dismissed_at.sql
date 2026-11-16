ALTER TABLE `customer_booking_notifications`
  ADD COLUMN `dismissed_at` datetime DEFAULT NULL AFTER `read_at`,
  DROP INDEX `idx_customer_booking_notifications_unread`,
  ADD KEY `idx_customer_booking_notifications_unread`
    (`customer_id`, `dismissed_at`, `read_at`, `date_created`);
