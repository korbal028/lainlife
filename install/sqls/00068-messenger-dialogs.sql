CREATE TABLE IF NOT EXISTS `messenger_dialogs` (
  `owner_type` varchar(64) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `owner_id` bigint(20) UNSIGNED NOT NULL,
  `peer_type` varchar(64) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `peer_id` bigint(20) UNSIGNED NOT NULL,
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `archived` tinyint(1) NOT NULL DEFAULT 0,
  `muted` tinyint(1) NOT NULL DEFAULT 0,
  `cleared_till` bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'сообщения с id <= этого скрыты у владельца',
  PRIMARY KEY (`owner_type`, `owner_id`, `peer_type`, `peer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
