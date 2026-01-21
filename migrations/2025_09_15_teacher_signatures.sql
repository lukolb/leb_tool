CREATE TABLE IF NOT EXISTS `teacher_signatures` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `purpose` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enc_key` varbinary(255) NOT NULL,
  `enc_key_iv` varbinary(32) NOT NULL,
  `enc_key_tag` varbinary(32) NOT NULL,
  `iv` varbinary(32) NOT NULL,
  `tag` varbinary(32) NOT NULL,
  `ciphertext` longblob NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_teacher_signatures_user_purpose` (`user_id`,`purpose`),
  KEY `idx_teacher_signatures_active` (`user_id`,`purpose`,`is_active`),
  CONSTRAINT `fk_teacher_signatures_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
