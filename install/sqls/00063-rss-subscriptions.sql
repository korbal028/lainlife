-- Миграция: таблица RSS-подписок пользователей.
-- До этого таблица существовала только на проде, созданная вручную, без миграции -
-- на свежих установках её не было. Схема снята один-в-один с прода через
-- SHOW CREATE TABLE (отсюда utf8mb4_general_ci и unsigned-поля - это не опечатка,
-- а то, как таблица реально создана в проде; трогать коллацию не стали ради
-- точного соответствия, джойнов по текстовым колонкам у неё нет).
CREATE TABLE IF NOT EXISTS `rss_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `url` varchar(512) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `created` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
