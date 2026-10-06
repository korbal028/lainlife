ALTER TABLE `messenger_dialogs` MODIFY `pinned` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 — не закреплён, иначе позиция среди закреплённых';
