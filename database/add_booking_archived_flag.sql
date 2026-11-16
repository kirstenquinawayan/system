ALTER TABLE `booking_list`
  ADD COLUMN `is_archived` tinyint(1) NOT NULL DEFAULT 0 AFTER `service_started_at`;
