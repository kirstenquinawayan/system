START TRANSACTION;

UPDATE `vehicle_list`
SET `status` = 1, `delete_flag` = 0, `date_updated` = CURRENT_TIMESTAMP
WHERE `name` IN ('Sedan', 'AUV', 'MPV', 'Pick Up', 'Ordinary Van', 'Premium Van');

INSERT INTO `vehicle_list` (`name`, `status`, `delete_flag`)
SELECT vehicles.`name`, 1, 0
FROM (
    SELECT 'Sedan' AS `name`
    UNION ALL SELECT 'AUV'
    UNION ALL SELECT 'MPV'
    UNION ALL SELECT 'Pick Up'
    UNION ALL SELECT 'Ordinary Van'
    UNION ALL SELECT 'Premium Van'
) AS vehicles
WHERE NOT EXISTS (
    SELECT 1
    FROM `vehicle_list` AS existing
    WHERE existing.`name` = vehicles.`name`
);

-- Estimate each package as the existing 45-minute wash plus 60 minutes per treatment and wax option.
-- Options without asphalt omit that treatment's 60-minute block.
INSERT INTO `service_list` (`name`, `description`, `duration_minutes`, `status`, `delete_flag`)
SELECT packages.`name`, packages.`description`, packages.`duration_minutes`, 1, 0
FROM (
    SELECT 'Promo Package 01 - Machine Wax' AS `name`, 'Body wash and asphalt treatment. Machine wax option. Includes free glass cleaner and odor eliminator.' AS `description`, 165 AS `duration_minutes`
    UNION ALL SELECT 'Promo Package 01 - Hand Wax w/ Asphalt', 'Body wash and asphalt treatment. Hand wax with asphalt option. Includes free glass cleaner and odor eliminator.', 165
    UNION ALL SELECT 'Promo Package 02 - Machine Wax', 'Body wash, asphalt treatment, and armor. Machine wax option. Includes free glass cleaner and odor eliminator.', 225
    UNION ALL SELECT 'Promo Package 02 - Hand Wax w/ Asphalt', 'Body wash, asphalt treatment, and armor. Hand wax with asphalt option. Includes free glass cleaner and odor eliminator.', 225
    UNION ALL SELECT 'Promo Package 02 - Hand Wax w/o Asphalt', 'Body wash and armor. Hand wax without asphalt option. Includes free glass cleaner and odor eliminator.', 165
    UNION ALL SELECT 'Promo Package 03 - Machine Wax', 'Body wash, asphalt treatment, and acid rain treatment. Machine wax option. Includes free glass cleaner and odor eliminator.', 225
    UNION ALL SELECT 'Promo Package 03 - Hand Wax w/ Asphalt', 'Body wash, asphalt treatment, and acid rain treatment. Hand wax with asphalt option. Includes free glass cleaner and odor eliminator.', 225
    UNION ALL SELECT 'Promo Package 03 - Hand Wax w/o Asphalt', 'Body wash and acid rain treatment. Hand wax without asphalt option. Includes free glass cleaner and odor eliminator.', 165
    UNION ALL SELECT 'Promo Package 04 - Machine Wax', 'Body wash, asphalt treatment, acid rain treatment, and armor. Machine wax option. Includes free glass cleaner and odor eliminator.', 285
    UNION ALL SELECT 'Promo Package 04 - Hand Wax w/ Asphalt', 'Body wash, asphalt treatment, acid rain treatment, and armor. Hand wax with asphalt option. Includes free glass cleaner and odor eliminator.', 285
    UNION ALL SELECT 'Promo Package 04 - Hand Wax w/o Asphalt', 'Body wash, acid rain treatment, and armor. Hand wax without asphalt option. Includes free glass cleaner and odor eliminator.', 225
    UNION ALL SELECT 'Promo Package 05 - Machine Wax', 'Body wash, asphalt treatment, armor, and engine wash. Machine wax option. Includes free glass cleaner and odor eliminator.', 285
    UNION ALL SELECT 'Promo Package 05 - Hand Wax w/ Asphalt', 'Body wash, asphalt treatment, armor, and engine wash. Hand wax with asphalt option. Includes free glass cleaner and odor eliminator.', 285
    UNION ALL SELECT 'Promo Package 05 - Hand Wax w/o Asphalt', 'Body wash, armor, and engine wash. Hand wax without asphalt option. Includes free glass cleaner and odor eliminator.', 225
    UNION ALL SELECT 'Promo Package 06 - Machine Wax', 'Body wash, asphalt treatment, and B20. Machine wax option. Includes free glass cleaner and odor eliminator.', 225
    UNION ALL SELECT 'Promo Package 06 - Hand Wax w/ Asphalt', 'Body wash, asphalt treatment, and B20. Hand wax with asphalt option. Includes free glass cleaner and odor eliminator.', 225
    UNION ALL SELECT 'Promo Package 06 - Hand Wax w/o Asphalt', 'Body wash and B20. Hand wax without asphalt option. Includes free glass cleaner and odor eliminator.', 165
    UNION ALL SELECT 'Promo Package 07 - Machine Wax', 'Body wash, asphalt treatment, B20, and armor. Machine wax option. Includes free glass cleaner and odor eliminator.', 285
    UNION ALL SELECT 'Promo Package 07 - Hand Wax w/ Asphalt', 'Body wash, asphalt treatment, B20, and armor. Hand wax with asphalt option. Includes free glass cleaner and odor eliminator.', 285
    UNION ALL SELECT 'Promo Package 07 - Hand Wax w/o Asphalt', 'Body wash, B20, and armor. Hand wax without asphalt option. Includes free glass cleaner and odor eliminator.', 225
    UNION ALL SELECT 'Promo Package 08 - Machine Wax', 'Body wash, asphalt treatment, armor, acid rain treatment, and engine wash. Machine wax option. Includes free glass cleaner and odor eliminator.', 345
    UNION ALL SELECT 'Promo Package 08 - Hand Wax w/ Asphalt', 'Body wash, asphalt treatment, armor, acid rain treatment, and engine wash. Hand wax with asphalt option. Includes free glass cleaner and odor eliminator.', 345
    UNION ALL SELECT 'Promo Package 08 - Hand Wax w/o Asphalt', 'Body wash, armor, acid rain treatment, and engine wash. Hand wax without asphalt option. Includes free glass cleaner and odor eliminator.', 285
    UNION ALL SELECT 'Promo Package 09 - Machine Wax', 'Body wash, asphalt treatment, armor, acid rain treatment, and B20. Machine wax option. Includes free glass cleaner and odor eliminator.', 345
    UNION ALL SELECT 'Promo Package 09 - Hand Wax w/ Asphalt', 'Body wash, asphalt treatment, armor, acid rain treatment, and B20. Hand wax with asphalt option. Includes free glass cleaner and odor eliminator.', 345
    UNION ALL SELECT 'Promo Package 09 - Hand Wax w/o Asphalt', 'Body wash, armor, acid rain treatment, and B20. Hand wax without asphalt option. Includes free glass cleaner and odor eliminator.', 285
    UNION ALL SELECT 'Promo Package 10 - Machine Wax', 'Body wash, asphalt treatment, armor, engine wash, and B20. Machine wax option. Includes free glass cleaner and odor eliminator.', 345
    UNION ALL SELECT 'Promo Package 10 - Hand Wax w/ Asphalt', 'Body wash, asphalt treatment, armor, engine wash, and B20. Hand wax with asphalt option. Includes free glass cleaner and odor eliminator.', 345
    UNION ALL SELECT 'Promo Package 10 - Hand Wax w/o Asphalt', 'Body wash, armor, engine wash, and B20. Hand wax without asphalt option. Includes free glass cleaner and odor eliminator.', 285
    UNION ALL SELECT 'Promo Package 11 - Machine Wax', 'Body wash, asphalt treatment, armor, acid rain treatment, engine wash, and B20. Machine wax option. Includes free glass cleaner and odor eliminator.', 405
    UNION ALL SELECT 'Promo Package 11 - Hand Wax w/ Asphalt', 'Body wash, asphalt treatment, armor, acid rain treatment, engine wash, and B20. Hand wax with asphalt option. Includes free glass cleaner and odor eliminator.', 405
    UNION ALL SELECT 'Promo Package 11 - Hand Wax w/o Asphalt', 'Body wash, armor, acid rain treatment, engine wash, and B20. Hand wax without asphalt option. Includes free glass cleaner and odor eliminator.', 345
) AS packages
WHERE NOT EXISTS (
    SELECT 1
    FROM `service_list` AS existing
    WHERE existing.`name` = packages.`name` AND existing.`delete_flag` = 0
);

INSERT INTO `service_list` (`name`, `description`, `duration_minutes`, `status`, `delete_flag`)
SELECT aliases.`name`, aliases.`description`, source.`duration_minutes`, 1, 0
FROM (
    SELECT 'Car Repaint' AS `name`, 'Car repaint service.' AS `description`, 'Car Painting' AS `legacy_name`
    UNION ALL SELECT 'Interior/Exterior Detailing', 'Interior and exterior vehicle detailing service.', 'Car Detailing'
) AS aliases
INNER JOIN `service_list` AS source
    ON source.`name` = aliases.`legacy_name` AND source.`delete_flag` = 0
WHERE NOT EXISTS (
    SELECT 1
    FROM `service_list` AS existing
    WHERE existing.`name` = aliases.`name` AND existing.`delete_flag` = 0
);

INSERT INTO `price_list` (`service_id`, `vehicle_id`, `price`)
SELECT service.`id`, vehicle.`id`,
       CASE vehicle.`name`
           WHEN 'Sedan' THEN package_prices.`small`
           WHEN 'AUV' THEN package_prices.`medium`
           WHEN 'MPV' THEN package_prices.`large`
           WHEN 'Pick Up' THEN package_prices.`x_large`
           WHEN 'Ordinary Van' THEN package_prices.`c1_x_large`
           WHEN 'Premium Van' THEN package_prices.`c2_x_large`
       END
FROM (
    SELECT 'Promo Package 01 - Machine Wax' AS `service_name`, 600 AS `small`, 700 AS `medium`, 850 AS `large`, 950 AS `x_large`, 1100 AS `c1_x_large`, 1200 AS `c2_x_large`
    UNION ALL SELECT 'Promo Package 01 - Hand Wax w/ Asphalt', 450, 500, 600, 700, 800, 900
    UNION ALL SELECT 'Promo Package 02 - Machine Wax', 750, 900, 1100, 1250, 1450, 1600
    UNION ALL SELECT 'Promo Package 02 - Hand Wax w/ Asphalt', 600, 700, 850, 950, 1100, 1200
    UNION ALL SELECT 'Promo Package 02 - Hand Wax w/o Asphalt', 500, 600, 700, 800, 900, 1000
    UNION ALL SELECT 'Promo Package 03 - Machine Wax', 1200, 1350, 1550, 1700, 1900, 2050
    UNION ALL SELECT 'Promo Package 03 - Hand Wax w/ Asphalt', 1050, 1150, 1300, 1400, 1550, 1650
    UNION ALL SELECT 'Promo Package 03 - Hand Wax w/o Asphalt', 950, 1050, 1150, 1250, 1350, 1450
    UNION ALL SELECT 'Promo Package 04 - Machine Wax', 1350, 1550, 1800, 2000, 2250, 2400
    UNION ALL SELECT 'Promo Package 04 - Hand Wax w/ Asphalt', 1200, 1350, 1550, 1700, 1900, 2000
    UNION ALL SELECT 'Promo Package 04 - Hand Wax w/o Asphalt', 1100, 1250, 1400, 1550, 1700, 1800
    UNION ALL SELECT 'Promo Package 05 - Machine Wax', 1100, 1300, 1500, 1750, 2000, 2150
    UNION ALL SELECT 'Promo Package 05 - Hand Wax w/ Asphalt', 950, 1100, 1300, 1450, 1650, 1750
    UNION ALL SELECT 'Promo Package 05 - Hand Wax w/o Asphalt', 850, 1000, 1150, 1300, 1450, 1550
    UNION ALL SELECT 'Promo Package 06 - Machine Wax', 900, 1100, 1300, 1550, 1800, 2000
    UNION ALL SELECT 'Promo Package 06 - Hand Wax w/ Asphalt', 750, 900, 1100, 1250, 1450, 1600
    UNION ALL SELECT 'Promo Package 06 - Hand Wax w/o Asphalt', 650, 800, 950, 1100, 1250, 1400
    UNION ALL SELECT 'Promo Package 07 - Machine Wax', 1050, 1300, 1600, 1850, 2150, 2350
    UNION ALL SELECT 'Promo Package 07 - Hand Wax w/ Asphalt', 900, 1100, 1350, 1550, 1800, 1950
    UNION ALL SELECT 'Promo Package 07 - Hand Wax w/o Asphalt', 800, 1000, 1200, 1400, 1600, 1750
    UNION ALL SELECT 'Promo Package 08 - Machine Wax', 1700, 1950, 2250, 2500, 2800, 3000
    UNION ALL SELECT 'Promo Package 08 - Hand Wax w/ Asphalt', 1550, 1750, 2000, 2200, 2450, 2600
    UNION ALL SELECT 'Promo Package 08 - Hand Wax w/o Asphalt', 1450, 1650, 1850, 2050, 2250, 2400
    UNION ALL SELECT 'Promo Package 09 - Machine Wax', 1650, 1950, 2300, 2600, 2950, 3200
    UNION ALL SELECT 'Promo Package 09 - Hand Wax w/ Asphalt', 1500, 1750, 2050, 2300, 2600, 2800
    UNION ALL SELECT 'Promo Package 09 - Hand Wax w/o Asphalt', 1400, 1650, 1900, 2150, 2400, 2600
    UNION ALL SELECT 'Promo Package 10 - Machine Wax', 1400, 1700, 2050, 2350, 2700, 2950
    UNION ALL SELECT 'Promo Package 10 - Hand Wax w/ Asphalt', 1250, 1500, 1800, 2050, 2350, 2550
    UNION ALL SELECT 'Promo Package 10 - Hand Wax w/o Asphalt', 1150, 1400, 1650, 1900, 2150, 2350
    UNION ALL SELECT 'Promo Package 11 - Machine Wax', 2000, 2350, 2750, 3100, 3500, 3800
    UNION ALL SELECT 'Promo Package 11 - Hand Wax w/ Asphalt', 1850, 2150, 2500, 2800, 3150, 3400
    UNION ALL SELECT 'Promo Package 11 - Hand Wax w/o Asphalt', 1750, 2050, 2350, 2650, 2950, 3200
) AS package_prices
INNER JOIN `service_list` AS service
    ON service.`name` = package_prices.`service_name` AND service.`status` = 1 AND service.`delete_flag` = 0
INNER JOIN `vehicle_list` AS vehicle
    ON vehicle.`status` = 1 AND vehicle.`delete_flag` = 0
   AND vehicle.`name` IN ('Sedan', 'AUV', 'MPV', 'Pick Up', 'Ordinary Van', 'Premium Van')
WHERE package_prices.`service_name` IS NOT NULL
ON DUPLICATE KEY UPDATE `price` = VALUES(`price`);

UPDATE `service_list`
SET `description` = 'Car wash service.', `duration_minutes` = 45, `status` = 1, `delete_flag` = 0
WHERE `name` = 'Car Wash';

INSERT INTO `price_list` (`service_id`, `vehicle_id`, `price`)
SELECT service.`id`, vehicle.`id`,
       CASE vehicle.`name`
           WHEN 'Sedan' THEN 190.00
           WHEN 'AUV' THEN 210.00
           WHEN 'MPV' THEN 230.00
           WHEN 'Pick Up' THEN 250.00
           WHEN 'Ordinary Van' THEN 270.00
           WHEN 'Premium Van' THEN 290.00
       END
FROM `service_list` AS service
CROSS JOIN `vehicle_list` AS vehicle
WHERE service.`name` = 'Car Wash'
  AND service.`status` = 1
  AND service.`delete_flag` = 0
  AND vehicle.`name` IN ('Sedan', 'AUV', 'MPV', 'Pick Up', 'Ordinary Van', 'Premium Van')
  AND vehicle.`status` = 1
  AND vehicle.`delete_flag` = 0
ON DUPLICATE KEY UPDATE `price` = VALUES(`price`);

INSERT INTO `price_list` (`service_id`, `vehicle_id`, `price`)
SELECT target_service.`id`, target_vehicle.`id`, source_price.`price`
FROM (
    SELECT 'Ceramic Coating' AS `name`, 'Ceramic Coating' AS `legacy_name`, 'Sedan' AS `legacy_vehicle`, 'Sedan' AS `new_vehicle`
    UNION ALL SELECT 'Ceramic Coating', 'Ceramic Coating', 'AUV', 'AUV'
    UNION ALL SELECT 'Ceramic Coating', 'Ceramic Coating', 'MPV', 'MPV'
    UNION ALL SELECT 'Ceramic Coating', 'Ceramic Coating', 'Pick Up', 'Pick Up'
    UNION ALL SELECT 'Ceramic Coating', 'Ceramic Coating', 'Ordinary Van', 'Ordinary Van'
    UNION ALL SELECT 'Ceramic Coating', 'Ceramic Coating', 'Premium Van', 'Premium Van'
    UNION ALL SELECT 'Undercoat', 'Undercoat', 'Sedan', 'Sedan'
    UNION ALL SELECT 'Undercoat', 'Undercoat', 'AUV', 'AUV'
    UNION ALL SELECT 'Undercoat', 'Undercoat', 'MPV', 'MPV'
    UNION ALL SELECT 'Undercoat', 'Undercoat', 'Pick Up', 'Pick Up'
    UNION ALL SELECT 'Undercoat', 'Undercoat', 'Ordinary Van', 'Ordinary Van'
    UNION ALL SELECT 'Undercoat', 'Undercoat', 'Premium Van', 'Premium Van'
    UNION ALL SELECT 'Underwash', 'Underwash', 'Sedan', 'Sedan'
    UNION ALL SELECT 'Underwash', 'Underwash', 'AUV', 'AUV'
    UNION ALL SELECT 'Underwash', 'Underwash', 'MPV', 'MPV'
    UNION ALL SELECT 'Underwash', 'Underwash', 'Pick Up', 'Pick Up'
    UNION ALL SELECT 'Underwash', 'Underwash', 'Ordinary Van', 'Ordinary Van'
    UNION ALL SELECT 'Underwash', 'Underwash', 'Premium Van', 'Premium Van'
    UNION ALL SELECT 'Car Repaint', 'Car Painting', 'Sedan', 'Sedan'
    UNION ALL SELECT 'Car Repaint', 'Car Painting', 'AUV', 'AUV'
    UNION ALL SELECT 'Car Repaint', 'Car Painting', 'MPV', 'MPV'
    UNION ALL SELECT 'Car Repaint', 'Car Painting', 'Pick Up', 'Pick Up'
    UNION ALL SELECT 'Car Repaint', 'Car Painting', 'Ordinary Van', 'Ordinary Van'
    UNION ALL SELECT 'Car Repaint', 'Car Painting', 'Premium Van', 'Premium Van'
    UNION ALL SELECT 'Interior/Exterior Detailing', 'Car Detailing', 'Sedan', 'Sedan'
    UNION ALL SELECT 'Interior/Exterior Detailing', 'Car Detailing', 'AUV', 'AUV'
    UNION ALL SELECT 'Interior/Exterior Detailing', 'Car Detailing', 'MPV', 'MPV'
    UNION ALL SELECT 'Interior/Exterior Detailing', 'Car Detailing', 'Pick Up', 'Pick Up'
    UNION ALL SELECT 'Interior/Exterior Detailing', 'Car Detailing', 'Ordinary Van', 'Ordinary Van'
    UNION ALL SELECT 'Interior/Exterior Detailing', 'Car Detailing', 'Premium Van', 'Premium Van'
) AS vehicle_map
INNER JOIN `service_list` AS target_service
    ON target_service.`name` = vehicle_map.`name` AND target_service.`status` = 1 AND target_service.`delete_flag` = 0
INNER JOIN `service_list` AS legacy_service
    ON legacy_service.`name` = vehicle_map.`legacy_name` AND legacy_service.`delete_flag` = 0
INNER JOIN `vehicle_list` AS target_vehicle
    ON target_vehicle.`name` = vehicle_map.`new_vehicle` AND target_vehicle.`status` = 1 AND target_vehicle.`delete_flag` = 0
INNER JOIN `vehicle_list` AS legacy_vehicle
    ON legacy_vehicle.`name` = vehicle_map.`legacy_vehicle`
INNER JOIN `price_list` AS source_price
    ON source_price.`service_id` = legacy_service.`id` AND source_price.`vehicle_id` = legacy_vehicle.`id`
ON DUPLICATE KEY UPDATE `price` = VALUES(`price`);

UPDATE `service_list`
SET `status` = 0, `delete_flag` = 1
WHERE `name` IN ('Engine Wash', 'Car Painting', 'Car Detailing')
  AND `delete_flag` = 0;

INSERT IGNORE INTO `price_list` (`service_id`, `vehicle_id`, `price`)
SELECT source_price.`service_id`, target_vehicle.`id`, source_price.`price`
FROM (
    SELECT 'Small' AS `source_name`, 'Sedan' AS `target_name`
    UNION ALL SELECT 'Medium', 'AUV'
    UNION ALL SELECT 'Large', 'MPV'
    UNION ALL SELECT 'X-Large', 'Pick Up'
    UNION ALL SELECT 'C1 XX-Large', 'Ordinary Van'
    UNION ALL SELECT 'C2 XX-Large', 'Premium Van'
) AS vehicle_map
INNER JOIN `vehicle_list` AS source_vehicle
    ON source_vehicle.`name` = vehicle_map.`source_name`
INNER JOIN `vehicle_list` AS target_vehicle
    ON target_vehicle.`name` = vehicle_map.`target_name`
   AND target_vehicle.`status` = 1 AND target_vehicle.`delete_flag` = 0
INNER JOIN `price_list` AS source_price
    ON source_price.`vehicle_id` = source_vehicle.`id`;

UPDATE `vehicle_list`
SET `status` = 0, `delete_flag` = 1, `date_updated` = CURRENT_TIMESTAMP
WHERE `name` IN ('Small', 'Medium', 'Large', 'X-Large', 'C1 XX-Large', 'C2 XX-Large')
  AND `delete_flag` = 0;

COMMIT;
