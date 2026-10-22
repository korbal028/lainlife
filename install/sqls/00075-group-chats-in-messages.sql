-- сообщения бесед живут в messages: recipient_type = GroupChat, recipient_id = id беседы
ALTER TABLE `group_chats` ADD COLUMN IF NOT EXISTS `pinned_message` bigint(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `admins`;

ALTER TABLE `messages` ADD COLUMN IF NOT EXISTS `group_message_id` bigint(20) DEFAULT NULL;

INSERT INTO `messages` (`sender_type`, `sender_id`, `recipient_type`, `recipient_id`, `content`, `created`, `edited`, `ad`, `deleted`, `unread`, `group_message_id`)
SELECT `sender_type`, `sender_id`, 'openvk\\Web\\Models\\Entities\\GroupChat', `chat_id`, `content`, `created`, `edited`, `ad`, `deleted`, 0, `id`
FROM `group_messages`
ORDER BY `id`;

UPDATE `attachments` a
JOIN `messages` m ON m.`group_message_id` = a.`target_id`
SET a.`target_type` = 'openvk\\Web\\Models\\Entities\\Message', a.`target_id` = m.`id`
WHERE a.`target_type` = 'openvk\\Web\\Models\\Entities\\GroupMessage';

-- forwarded_from в group_messages хранил ответ
UPDATE `messages` m
JOIN `group_messages` gm ON gm.`id` = m.`group_message_id`
JOIN `messages` r ON r.`group_message_id` = gm.`forwarded_from`
SET m.`reply_to` = r.`id`;

ALTER TABLE `messages` DROP COLUMN `group_message_id`;
