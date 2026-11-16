ALTER TABLE `booking_list`
  ADD CONSTRAINT `chk_booking_plate_number_length`
  CHECK (`plate_number` = '' OR CHAR_LENGTH(`plate_number`) BETWEEN 1 AND 8);
