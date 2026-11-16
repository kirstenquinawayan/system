START TRANSACTION;

UPDATE `service_list`
SET `duration_minutes` = CASE LOWER(TRIM(`name`))
  WHEN 'car wash' THEN 45
  WHEN 'engine wash' THEN 60
  WHEN 'under wash' THEN 45
  WHEN 'underwash' THEN 45
  WHEN 'undercoat' THEN 180
  WHEN 'detailing' THEN 300
  WHEN 'car detailing' THEN 300
  WHEN 'ceramic' THEN 480
  WHEN 'ceramic coating' THEN 480
  WHEN 'painting' THEN 4320
  WHEN 'car painting' THEN 4320
  ELSE `duration_minutes`
END
WHERE LOWER(TRIM(`name`)) IN (
  'car wash',
  'engine wash',
  'under wash',
  'underwash',
  'undercoat',
  'detailing',
  'car detailing',
  'ceramic',
  'ceramic coating',
  'painting',
  'car painting'
)
AND `delete_flag` = 0;

UPDATE `service_list`
SET `name` = CASE LOWER(TRIM(`name`))
  WHEN 'under wash' THEN 'Underwash'
  WHEN 'underwash' THEN 'Underwash'
  WHEN 'detailing' THEN 'Car Detailing'
  WHEN 'car detailing' THEN 'Car Detailing'
  WHEN 'ceramic' THEN 'Ceramic Coating'
  WHEN 'ceramic coating' THEN 'Ceramic Coating'
  WHEN 'painting' THEN 'Car Painting'
  WHEN 'car painting' THEN 'Car Painting'
  ELSE `name`
END
WHERE LOWER(TRIM(`name`)) IN (
  'under wash',
  'underwash',
  'detailing',
  'car detailing',
  'ceramic',
  'ceramic coating',
  'painting',
  'car painting'
)
AND `delete_flag` = 0;

COMMIT;
