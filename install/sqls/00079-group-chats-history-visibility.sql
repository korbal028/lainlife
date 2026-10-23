-- видят ли новые участники беседы сообщения, отправленные до их вступления
ALTER TABLE `group_chats` ADD COLUMN IF NOT EXISTS `history_visible` tinyint(1) NOT NULL DEFAULT 1 AFTER `invite_hash`;
