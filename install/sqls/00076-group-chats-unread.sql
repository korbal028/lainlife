-- до какого сообщения беседы участник дочитал (для лички непрочитанность хранится в messages.unread)
ALTER TABLE `messenger_dialogs` ADD COLUMN IF NOT EXISTS `read_till` bigint(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `cleared_till`;

ALTER TABLE `messages` ADD INDEX IF NOT EXISTS `recipient` (`recipient_type`, `recipient_id`);

-- уже накопленная история бесед считается прочитанной
INSERT INTO `messenger_dialogs` (`owner_type`, `owner_id`, `peer_type`, `peer_id`, `read_till`)
SELECT 'openvk\\Web\\Models\\Entities\\User', p.`id`, 'openvk\\Web\\Models\\Entities\\GroupChat', c.`id`,
       COALESCE((SELECT MAX(m.`id`) FROM `messages` m WHERE m.`recipient_type` = 'openvk\\Web\\Models\\Entities\\GroupChat' AND m.`recipient_id` = c.`id`), 0)
FROM `group_chats` c
JOIN `profiles` p ON FIND_IN_SET(p.`id`, c.`participants`) > 0
ON DUPLICATE KEY UPDATE `read_till` = VALUES(`read_till`);
