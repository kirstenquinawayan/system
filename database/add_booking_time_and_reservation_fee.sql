ALTER TABLE `booking_list`
  MODIFY COLUMN `schedule` datetime NOT NULL,
  ADD COLUMN `reservation_fee` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`;
