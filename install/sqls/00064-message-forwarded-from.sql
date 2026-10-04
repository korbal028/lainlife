ALTER TABLE `messages` ADD COLUMN IF NOT EXISTS `forwarded_from` bigint(20) DEFAULT NULL AFTER `edited`;
