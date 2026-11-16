ALTER TABLE `booking_online_payments`
  ADD COLUMN `transaction_ref` varchar(100) DEFAULT NULL AFTER `status`,
  ADD COLUMN `verified_by` int(30) DEFAULT NULL AFTER `transaction_ref`,
  ADD COLUMN `verified_at` datetime DEFAULT NULL AFTER `verified_by`,
  ADD COLUMN `rejected_by` int(30) DEFAULT NULL AFTER `verified_at`,
  ADD COLUMN `rejected_at` datetime DEFAULT NULL AFTER `rejected_by`,
  ADD UNIQUE KEY `uq_booking_online_payment_provider_ref` (`payment_provider`, `transaction_ref`);
