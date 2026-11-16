START TRANSACTION;

UPDATE `service_list`
SET `name` = 'Car Wash', `description` = 'Car wash service.'
WHERE `name` = 'Wash' AND `delete_flag` = 0;

UPDATE `service_list`
SET `name` = CASE LOWER(TRIM(`name`))
    WHEN 'under wash' THEN 'Underwash'
    WHEN 'detailing' THEN 'Car Detailing'
    WHEN 'ceramic' THEN 'Ceramic Coating'
    WHEN 'painting' THEN 'Car Painting'
    ELSE `name`
END
WHERE LOWER(TRIM(`name`)) IN ('under wash', 'detailing', 'ceramic', 'painting')
  AND `delete_flag` = 0;

INSERT INTO `service_list` (`name`, `description`, `status`, `delete_flag`)
SELECT added.`name`, added.`description`, 1, 0
FROM (
    SELECT 'Car Painting' AS `name`, 'Vehicle painting service.' AS `description`
    UNION ALL SELECT 'Ceramic Coating', 'Ceramic coating service.'
    UNION ALL SELECT 'Undercoat', 'Vehicle undercoat service.'
    UNION ALL SELECT 'Car Detailing', 'Vehicle detailing service.'
    UNION ALL SELECT 'Engine Wash', 'Engine wash service.'
    UNION ALL SELECT 'Underwash', 'Vehicle under wash service.'
) AS added
WHERE NOT EXISTS (
    SELECT 1
    FROM `service_list` AS existing
    WHERE existing.`name` = added.`name` AND existing.`delete_flag` = 0
);

DELETE FROM `price_list`
WHERE `service_id` IN (
    SELECT `id`
    FROM `service_list`
    WHERE `name` IN ('Car Wash', 'Car Painting', 'Ceramic Coating', 'Undercoat', 'Car Detailing', 'Engine Wash', 'Underwash')
      AND `delete_flag` = 0
);

INSERT INTO `price_list` (`service_id`, `vehicle_id`, `price`)
SELECT service.`id`, vehicle.`id`, prices.`price`
FROM (
    SELECT 'Car Wash' AS `service_name`, '2 wheeler' AS `vehicle_name`, 130.00 AS `price`
    UNION ALL SELECT 'Car Wash', '4 Wheeler', 190.00
    UNION ALL SELECT 'Car Wash', '6 wheeler', 240.00
    UNION ALL SELECT 'Car Wash', '10 wheeler', 300.00
    UNION ALL SELECT 'Car Painting', '2 wheeler', 2500.00
    UNION ALL SELECT 'Car Painting', '4 Wheeler', 3200.00
    UNION ALL SELECT 'Car Painting', '6 wheeler', 5000.00
    UNION ALL SELECT 'Car Painting', '10 wheeler', 8000.00
    UNION ALL SELECT 'Ceramic Coating', '2 wheeler', 6000.00
    UNION ALL SELECT 'Ceramic Coating', '4 Wheeler', 10000.00
    UNION ALL SELECT 'Ceramic Coating', '6 wheeler', 12000.00
    UNION ALL SELECT 'Ceramic Coating', '10 wheeler', 15000.00
    UNION ALL SELECT 'Undercoat', '2 wheeler', 3500.00
    UNION ALL SELECT 'Undercoat', '4 Wheeler', 5000.00
    UNION ALL SELECT 'Undercoat', '6 wheeler', 6000.00
    UNION ALL SELECT 'Undercoat', '10 wheeler', 8000.00
    UNION ALL SELECT 'Car Detailing', '2 wheeler', 3000.00
    UNION ALL SELECT 'Car Detailing', '4 Wheeler', 5000.00
    UNION ALL SELECT 'Car Detailing', '6 wheeler', 6000.00
    UNION ALL SELECT 'Car Detailing', '10 wheeler', 8000.00
    UNION ALL SELECT 'Engine Wash', '2 wheeler', 350.00
    UNION ALL SELECT 'Engine Wash', '4 Wheeler', 550.00
    UNION ALL SELECT 'Engine Wash', '6 wheeler', 650.00
    UNION ALL SELECT 'Engine Wash', '10 wheeler', 950.00
    UNION ALL SELECT 'Underwash', '2 wheeler', 400.00
    UNION ALL SELECT 'Underwash', '4 Wheeler', 600.00
    UNION ALL SELECT 'Underwash', '6 wheeler', 700.00
    UNION ALL SELECT 'Underwash', '10 wheeler', 1000.00
) AS prices
INNER JOIN `service_list` AS service
    ON service.`name` = prices.`service_name` AND service.`delete_flag` = 0
INNER JOIN `vehicle_list` AS vehicle
    ON vehicle.`name` = prices.`vehicle_name` AND vehicle.`delete_flag` = 0 AND vehicle.`status` = 1;

COMMIT;
