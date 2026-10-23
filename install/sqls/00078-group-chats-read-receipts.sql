-- последнее сообщение беседы на момент вступления: более ранние участник не читал, хотя read_till их покрывает
ALTER TABLE `messenger_dialogs` ADD COLUMN IF NOT EXISTS `joined_till` bigint(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `read_till`;

ALTER TABLE `messenger_dialogs` ADD INDEX IF NOT EXISTS `peer` (`peer_type`, `peer_id`);
