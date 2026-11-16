ALTER TABLE `booking_online_payments`
  ADD COLUMN `payment_provider` varchar(20) NOT NULL DEFAULT 'demo_online' AFTER `payment_type`;
