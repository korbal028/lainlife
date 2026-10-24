CREATE TABLE IF NOT EXISTS `group_chat_exceptions` (
  `chat` bigint(20) UNSIGNED NOT NULL,
  `user` bigint(20) UNSIGNED NOT NULL,
  `permissions` text CHARACTER SET ascii NOT NULL,
  `slowmode` int(10) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`chat`, `user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
