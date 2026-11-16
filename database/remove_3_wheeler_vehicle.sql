START TRANSACTION;

DELETE FROM `price_list`
WHERE `vehicle_id` IN (
  SELECT `id` FROM `vehicle_list`
  WHERE `id` = 3 AND `name` = '3 wheeler'
);

UPDATE `vehicle_list`
SET `status` = 0, `delete_flag` = 1
WHERE `id` = 3 AND `name` = '3 wheeler';

COMMIT;
