ALTER TABLE `profiles` ADD COLUMN IF NOT EXISTS `notification_sound` varchar(16) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'bell';
