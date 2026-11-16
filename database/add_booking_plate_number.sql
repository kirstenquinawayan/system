ALTER TABLE `booking_list`
  ADD COLUMN `plate_number` varchar(20) NOT NULL DEFAULT '' AFTER `contact`;
