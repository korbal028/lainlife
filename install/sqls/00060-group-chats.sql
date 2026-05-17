-- Миграция: Добавление таблиц для групповых чатов
-- Версия: 00051

-- Таблица групповых чатов
CREATE TABLE IF NOT EXISTS `group_chats` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `creator_id` bigint(20) UNSIGNED NOT NULL,
  `participants` text COLLATE utf8mb4_unicode_520_ci NOT NULL COMMENT 'CSV список ID участников',
  `participants_count` int(11) NOT NULL DEFAULT 1,
  `avatar` varchar(512) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `created` bigint(20) NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `creator_id` (`creator_id`),
  KEY `deleted` (`deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Таблица сообщений групповых чатов
CREATE TABLE IF NOT EXISTS `group_messages` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `chat_id` bigint(20) UNSIGNED NOT NULL,
  `sender_type` varchar(64) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `sender_id` bigint(20) UNSIGNED NOT NULL,
  `content` longtext COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `created` bigint(20) NOT NULL,
  `edited` bigint(20) DEFAULT NULL,
  `forwarded_from` bigint(20) DEFAULT NULL,
  `ad` tinyint(1) NOT NULL DEFAULT 0,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `unread` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `chat_id` (`chat_id`),
  KEY `sender_id` (`sender_id`),
  KEY `created` (`created`),
  KEY `deleted` (`deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Таблица вложений к сообщениям групповых чатов
CREATE TABLE IF NOT EXISTS `group_msg_attachments` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `message` bigint(20) UNSIGNED NOT NULL,
  `object_type` varchar(64) COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `object_id` bigint(20) UNSIGNED NOT NULL,
  `index` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `message` (`message`),
  KEY `object_type` (`object_type`, `object_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
