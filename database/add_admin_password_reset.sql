ALTER TABLE `users`
  ADD COLUMN `email` varchar(254) DEFAULT NULL AFTER `username`,
  ADD UNIQUE KEY `uq_users_email` (`email`);

CREATE TABLE `admin_password_resets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(50) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_password_resets_token_hash` (`token_hash`),
  KEY `idx_admin_password_resets_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_admin_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
