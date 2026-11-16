ALTER TABLE `booking_list`
  ADD COLUMN `payment_status` varchar(20) NOT NULL DEFAULT 'not_required' AFTER `status`,
  ADD COLUMN `payment_paid_at` datetime DEFAULT NULL AFTER `payment_status`;
