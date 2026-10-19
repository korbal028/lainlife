-- отложенные записи: posts.suggested = 3, created — время публикации
ALTER TABLE `posts` ADD INDEX IF NOT EXISTS `suggested_created` (`suggested`, `created`);
