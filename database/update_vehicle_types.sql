START TRANSACTION;

UPDATE `vehicle_list`
SET `status` = 1, `delete_flag` = 0, `date_updated` = CURRENT_TIMESTAMP
WHERE `name` IN ('Sedan', 'AUV', 'MPV', 'Pick Up', 'Ordinary Van', 'Premium Van');

INSERT INTO `vehicle_list` (`name`, `status`, `delete_flag`)
SELECT added.`name`, 1, 0
FROM (
    SELECT 'Sedan' AS `name`
    UNION ALL SELECT 'AUV'
    UNION ALL SELECT 'MPV'
    UNION ALL SELECT 'Pick Up'
    UNION ALL SELECT 'Ordinary Van'
    UNION ALL SELECT 'Premium Van'
) AS added
WHERE NOT EXISTS (
    SELECT 1 FROM `vehicle_list` AS existing WHERE existing.`name` = added.`name`
);

UPDATE `vehicle_list`
SET `name` = 'Sedan'
WHERE `name` = 'sedan' AND `status` = 1 AND `delete_flag` = 0;

UPDATE `vehicle_list`
SET `status` = 0, `delete_flag` = 1, `date_updated` = CURRENT_TIMESTAMP
WHERE `name` IN ('2 wheeler', '4 Wheeler', '6 wheeler', '10 wheeler');

DELETE price
FROM `price_list` AS price
INNER JOIN `vehicle_list` AS vehicle ON vehicle.`id` = price.`vehicle_id`
WHERE vehicle.`name` IN ('Sedan', 'AUV', 'MPV', 'Pick Up', 'Ordinary Van', 'Premium Van')
  AND vehicle.`id` <> COALESCE((
      SELECT source.`id`
      FROM `vehicle_list` AS source
      WHERE source.`name` IN ('4 Wheeler', 'Sedan')
      ORDER BY CASE source.`name` WHEN '4 Wheeler' THEN 0 ELSE 1 END, source.`id`
      LIMIT 1
  ), 0);

INSERT INTO `price_list` (`service_id`, `vehicle_id`, `price`)
SELECT sedan_price.`service_id`, vehicle.`id`, sedan_price.`price`
FROM `price_list` AS sedan_price
INNER JOIN (
    SELECT source.`id`
    FROM `vehicle_list` AS source
    WHERE source.`name` IN ('4 Wheeler', 'Sedan')
    ORDER BY CASE source.`name` WHEN '4 Wheeler' THEN 0 ELSE 1 END, source.`id`
    LIMIT 1
) AS source ON source.`id` = sedan_price.`vehicle_id`
INNER JOIN `vehicle_list` AS vehicle
    ON vehicle.`name` IN ('Sedan', 'AUV', 'MPV', 'Pick Up', 'Ordinary Van', 'Premium Van')
WHERE vehicle.`status` = 1 AND vehicle.`delete_flag` = 0;

COMMIT;
