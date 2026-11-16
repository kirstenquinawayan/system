-- Preflight before applying: all checks below should return zero.
SELECT COUNT(*) - COUNT(DISTINCT code) AS duplicate_booking_codes
FROM booking_list;

SELECT
  (SELECT COUNT(*) FROM booking_services bs
   LEFT JOIN booking_list b ON b.id = bs.booking_id WHERE b.id IS NULL) AS orphan_booking_services,
  (SELECT COUNT(*) FROM booking_services bs
   LEFT JOIN service_list s ON s.id = bs.service_id WHERE s.id IS NULL) AS orphan_service_links;

-- Currency columns are converted from FLOAT to exact two-decimal storage.
-- Existing values were checked to have at most two decimal places.
ALTER TABLE `booking_list`
  MODIFY COLUMN `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  ADD UNIQUE KEY `uq_booking_code` (`code`);

ALTER TABLE `booking_services`
  MODIFY COLUMN `price` DECIMAL(15,2) NOT NULL;

ALTER TABLE `price_list`
  MODIFY COLUMN `price` DECIMAL(15,2) NOT NULL DEFAULT 0.00;
