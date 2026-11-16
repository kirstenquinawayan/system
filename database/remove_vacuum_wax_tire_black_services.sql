START TRANSACTION;

DELETE FROM `price_list`
WHERE `service_id` IN (
  SELECT `id` FROM `service_list`
  WHERE (`id` = 2 AND `name` = 'Tire Black')
     OR (`id` = 3 AND `name` = 'Vacuum')
     OR (`id` = 4 AND `name` = 'Wax')
);

UPDATE `service_list`
SET `status` = 0, `delete_flag` = 1
WHERE (`id` = 2 AND `name` = 'Tire Black')
   OR (`id` = 3 AND `name` = 'Vacuum')
   OR (`id` = 4 AND `name` = 'Wax');

COMMIT;
