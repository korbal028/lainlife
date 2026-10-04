ALTER TABLE `group_chats` ADD COLUMN IF NOT EXISTS `admins` text COLLATE utf8mb4_unicode_520_ci DEFAULT NULL COMMENT 'CSV список ID админов чата' AFTER `deleted`;
