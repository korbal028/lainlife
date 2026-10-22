-- код ссылки-приглашения в беседу: /im/join/<invite_hash>
ALTER TABLE `group_chats` ADD COLUMN IF NOT EXISTS `invite_hash` varchar(32) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL AFTER `pinned_message`;
ALTER TABLE `group_chats` ADD UNIQUE INDEX IF NOT EXISTS `invite_hash` (`invite_hash`);
