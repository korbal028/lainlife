ALTER TABLE `group_chat_admin_rights` ADD COLUMN IF NOT EXISTS `position` int(10) UNSIGNED NOT NULL DEFAULT 0;
