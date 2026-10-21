-- реакции на сообщения: одна реакция от пользователя на сообщение
CREATE TABLE IF NOT EXISTS `message_reactions` (
  `message` bigint(20) UNSIGNED NOT NULL,
  `user` bigint(20) UNSIGNED NOT NULL,
  `reaction` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `created` bigint(20) UNSIGNED NOT NULL,
  PRIMARY KEY (`message`, `user`),
  KEY `user` (`user`, `reaction`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- метка для поллинга (apiSync), `edited` не трогаем, чтобы сообщение не считалось изменённым
ALTER TABLE `messages` ADD COLUMN IF NOT EXISTS `reactions_changed` bigint(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `reply_to`;
