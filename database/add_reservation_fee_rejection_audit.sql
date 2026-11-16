ALTER TABLE `booking_list`
  ADD COLUMN `reservation_fee_rejected_at` datetime DEFAULT NULL AFTER `reservation_fee_verified_by`,
  ADD COLUMN `reservation_fee_rejected_by` int(30) DEFAULT NULL AFTER `reservation_fee_rejected_at`;
