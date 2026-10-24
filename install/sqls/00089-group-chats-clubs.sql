ALTER TABLE `group_chats` ADD COLUMN IF NOT EXISTS `club_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `creator_id`;
ALTER TABLE `group_chats` ADD INDEX IF NOT EXISTS `club_id` (`club_id`);
