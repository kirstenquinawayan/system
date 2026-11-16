ALTER TABLE `booking_list`
  ADD COLUMN `payment_received_by` int(30) DEFAULT NULL AFTER `payment_paid_at`;
