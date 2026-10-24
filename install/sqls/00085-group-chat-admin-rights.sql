CREATE TABLE IF NOT EXISTS `group_chat_admin_rights` (
  `chat` bigint(20) UNSIGNED NOT NULL,
  `user` bigint(20) UNSIGNED NOT NULL,
  `promoted_by` bigint(20) UNSIGNED NOT NULL,
  `rights` text CHARACTER SET ascii NOT NULL,
  PRIMARY KEY (`chat`, `user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
