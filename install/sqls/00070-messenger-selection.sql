ALTER TABLE `messenger_dialogs` ADD COLUMN IF NOT EXISTS `pinned_message` bigint(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `cleared_till`;

CREATE TABLE IF NOT EXISTS `messages_hidden` (
  `owner_id` bigint(20) UNSIGNED NOT NULL COMMENT 'пользователь, у которого сообщение скрыто',
  `message_id` bigint(20) UNSIGNED NOT NULL,
  PRIMARY KEY (`owner_id`, `message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
