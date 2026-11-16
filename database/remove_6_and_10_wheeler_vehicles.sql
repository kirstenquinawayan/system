START TRANSACTION;

DELETE FROM `price_list`
WHERE `vehicle_id` IN (
  SELECT `id` FROM `vehicle_list`
  WHERE (`id` = 4 AND `name` = '6 wheeler')
     OR (`id` = 5 AND `name` = '10 wheeler')
);

UPDATE `vehicle_list`
SET `status` = 0, `delete_flag` = 1
WHERE (`id` = 4 AND `name` = '6 wheeler')
   OR (`id` = 5 AND `name` = '10 wheeler');

COMMIT;
