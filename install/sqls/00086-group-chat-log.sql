CREATE TABLE IF NOT EXISTS `group_chat_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat` bigint(20) UNSIGNED NOT NULL,
  `actor` bigint(20) UNSIGNED NOT NULL,
  `target` bigint(20) UNSIGNED DEFAULT NULL,
  `type` varchar(16) CHARACTER SET ascii NOT NULL,
  `data` text COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `created` bigint(20) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `chat` (`chat`, `id`),
  KEY `created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
