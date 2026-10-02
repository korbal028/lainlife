-- Миграция: колонка admins в групповых чатах (CSV список ID админов чата).
-- На проде её добавляли вручную после создания таблицы, в 00062 её не было -
-- на уже мигрированных БД (где group_chats создана без admins) её надо дошить.
-- Для свежих установок admins уже входит в CREATE TABLE в 00062; здесь
-- IF NOT EXISTS делает ALTER идемпотентным и безопасным на проде, где колонка есть.
ALTER TABLE `group_chats` ADD COLUMN IF NOT EXISTS `admins` text COLLATE utf8mb4_unicode_520_ci DEFAULT NULL COMMENT 'CSV список ID админов чата' AFTER `deleted`;
