ALTER TABLE `booking_list`
  ADD COLUMN `receipt_pdf_token` varchar(64) DEFAULT NULL AFTER `payment_paid_at`,
  ADD UNIQUE KEY `uq_booking_receipt_pdf_token` (`receipt_pdf_token`);
