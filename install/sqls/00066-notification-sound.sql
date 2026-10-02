-- Миграция: выбор звука уведомления в настройках "Внешний вид".
-- Хранит выбранный пресет (bell/skype/icq). Дефолт bell - исходный notify.mp3.
ALTER TABLE `profiles` ADD COLUMN IF NOT EXISTS `notification_sound` varchar(16) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'bell';
