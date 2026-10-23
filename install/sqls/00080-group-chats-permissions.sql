ALTER TABLE `group_chats` ADD COLUMN IF NOT EXISTS `permissions` text COLLATE utf8mb4_unicode_520_ci DEFAULT NULL AFTER `history_visible`;
