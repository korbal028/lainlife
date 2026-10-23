CREATE TABLE IF NOT EXISTS `group_chat_tags` (
  `chat` bigint(20) UNSIGNED NOT NULL,
  `user` bigint(20) UNSIGNED NOT NULL,
  `tag` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `color` char(7) CHARACTER SET ascii NOT NULL,
  PRIMARY KEY (`chat`, `user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
