ALTER TABLE `booking_list`
  ADD COLUMN `reservation_fee_payment_status` varchar(24) NOT NULL DEFAULT 'not_required' AFTER `reservation_fee`,
  ADD COLUMN `reservation_fee_payment_method` varchar(20) DEFAULT NULL AFTER `reservation_fee_payment_status`,
  ADD COLUMN `reservation_fee_transaction_ref` varchar(100) DEFAULT NULL AFTER `reservation_fee_payment_method`,
  ADD COLUMN `reservation_fee_submitted_at` datetime DEFAULT NULL AFTER `reservation_fee_transaction_ref`,
  ADD COLUMN `reservation_fee_verified_at` datetime DEFAULT NULL AFTER `reservation_fee_submitted_at`,
  ADD COLUMN `reservation_fee_verified_by` int(30) DEFAULT NULL AFTER `reservation_fee_verified_at`,
  ADD COLUMN `reservation_fee_refunded_at` datetime DEFAULT NULL AFTER `reservation_fee_verified_by`,
  ADD COLUMN `reservation_fee_refund_ref` varchar(100) DEFAULT NULL AFTER `reservation_fee_refunded_at`,
  ADD COLUMN `reservation_fee_refunded_by` int(30) DEFAULT NULL AFTER `reservation_fee_refund_ref`,
  ADD COLUMN `cash_balance_amount` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `reservation_fee_refunded_by`,
  ADD KEY `idx_booking_reservation_fee_status` (`reservation_fee_payment_status`),
  ADD UNIQUE KEY `uq_booking_reservation_fee_reference` (`reservation_fee_payment_method`,`reservation_fee_transaction_ref`);
