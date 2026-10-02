-- Миграция: колонка forwarded_from в личных сообщениях.
-- Используется и для пересылки, и для ответов (reply_to из формы пишется сюда же:
-- см. MessengerPresenter::setForwarded_from и VKAPI\Handlers\Messages). Колонку
-- добавляли на проде вручную через ALTER без миграции - на свежих установках её
-- не было, из-за чего Message::isForwarded() падал с "undeclared column".
-- Тип совпадает с group_messages.forwarded_from. IF NOT EXISTS - чтобы миграция
-- была идемпотентной и безопасно отрабатывала на проде, где колонка уже есть.
ALTER TABLE `messages` ADD COLUMN IF NOT EXISTS `forwarded_from` bigint(20) DEFAULT NULL AFTER `edited`;
