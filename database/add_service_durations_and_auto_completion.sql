ALTER TABLE `service_list`
  ADD COLUMN `duration_minutes` smallint(5) unsigned NOT NULL DEFAULT 60;

ALTER TABLE `booking_services`
  ADD COLUMN `duration_minutes` smallint(5) unsigned NOT NULL DEFAULT 60;

ALTER TABLE `booking_list`
  ADD COLUMN `service_started_at` datetime DEFAULT NULL;
