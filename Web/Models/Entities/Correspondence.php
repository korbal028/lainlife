<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities;

use Chandler\Database\DatabaseConnection;
use Chandler\Signaling\SignalManager;
use Chandler\Security\Authenticator;
use openvk\Web\Events\{NewMessageEvent, TypingEvent};
use openvk\Web\Models\Entities\Message;
use openvk\Web\Models\Entities\User;
use openvk\Web\Models\RowModel;
use openvk\Web\Models\Repositories\{Users, Messages, MessengerDialogs};
use openvk\Web\Util\NotificationBroker;
use Nette\Database\Table\ActiveRow;

/**
 * A repository of messages sent between correspondents.
 *
 * A pseudo-repository that operates with messages
 * sent between users.
 */
class Correspondence
{
    /**
     * @var RowModel[] Array of correspondents (usually two)
     */
    private $correspondents;
    /**
     * @var \Nette\Database\Table\Selection Messages table
     */
    private $messages;

    public const CAP_BEHAVIOUR_END_MESSAGE_ID   = 1;
    public const CAP_BEHAVIOUR_START_MESSAGE_ID = 2;

    /**
     * Correspondence constructor.
     *
     * Requires two users/clubs to construct.
     *
     * @param $correspondent - first correspondent
     * @param $anotherCorrespondents - another correspondent
     */
    public function __construct(RowModel $correspondent, RowModel $anotherCorrespondent)
    {
        $this->correspondents = [$correspondent, $anotherCorrespondent];
        $this->messages       = DatabaseConnection::i()->getContext()->table("messages");
    }

    private ?int $clearedTill = null;

    private function getClearedTill(): int
    {
        return $this->clearedTill ??= (int) (new MessengerDialogs())->get($this->correspondents[0], $this->correspondents[1])["cleared_till"];
    }

    private function getHiddenCondition(): string
    {
        if (!($this->correspondents[0] instanceof User)) {
            return "";
        }

        return "\n  AND (`id` NOT IN (SELECT `message_id` FROM `messages_hidden` WHERE `owner_id` = " . (int) $this->correspondents[0]->getId() . "))";
    }

    /**
     * Принадлежит ли сообщение этой переписке.
     */
    public function hasMessage(Message $message): bool
    {
        $sender    = $message->getSender();
        $recipient = $message->getRecipient();
        if (!$sender || !$recipient) {
            return false;
        }

        $pair  = [get_class($sender) . $sender->getId(), get_class($recipient) . $recipient->getId()];
        $own   = [get_class($this->correspondents[0]) . $this->correspondents[0]->getId(), get_class($this->correspondents[1]) . $this->correspondents[1]->getId()];
        sort($pair);
        sort($own);

        return $pair === $own;
    }

    /**
     * Скрыть сообщения только у первого корреспондента.
     */
    public function hideMessagesForOwner(array $messageIds): void
    {
        $connection = DatabaseConnection::i()->getConnection();
        foreach ($messageIds as $id) {
            $connection->query("INSERT IGNORE INTO `messages_hidden` ?", [
                "owner_id"   => $this->correspondents[0]->getId(),
                "message_id" => (int) $id,
            ]);
        }
    }

    public function getPinnedMessage(): ?Message
    {
        $id = (int) (new MessengerDialogs())->get($this->correspondents[0], $this->correspondents[1])["pinned_message"];
        if ($id === 0) {
            return null;
        }

        $message = (new \openvk\Web\Models\Repositories\Messages())->get($id);
        if (!$message || $message->isDeleted() || $id <= $this->getClearedTill()) {
            return null;
        }

        return $message;
    }

    /**
     * Закреп общий для обоих собеседников. 0 — открепить.
     */
    public function setPinnedMessage(int $messageId): void
    {
        $dialogs = new MessengerDialogs();
        $dialogs->set($this->correspondents[0], $this->correspondents[1], ["pinned_message" => $messageId]);
        if ($this->correspondents[0]->getId() !== $this->correspondents[1]->getId() || get_class($this->correspondents[0]) !== get_class($this->correspondents[1])) {
            $dialogs->set($this->correspondents[1], $this->correspondents[0], ["pinned_message" => $messageId]);
        }
    }

    private function getPairCondition(): array
    {
        return [
            "((`sender_type` = ? AND `recipient_type` = ? AND `sender_id` = ? AND `recipient_id` = ?) OR (`sender_type` = ? AND `recipient_type` = ? AND `sender_id` = ? AND `recipient_id` = ?))",
            get_class($this->correspondents[0]), get_class($this->correspondents[1]),
            $this->correspondents[0]->getId(), $this->correspondents[1]->getId(),
            get_class($this->correspondents[1]), get_class($this->correspondents[0]),
            $this->correspondents[1]->getId(), $this->correspondents[0]->getId(),
        ];
    }

    /**
     * Скрыть всю переписку у первого корреспондента (удаление "только для себя").
     */
    public function clearForOwner(): void
    {
        $params = $this->getPairCondition();
        $cond   = array_shift($params);
        $lastId = DatabaseConnection::i()->getConnection()->query("SELECT MAX(`id`) AS id FROM `messages` WHERE $cond", ...$params)->fetch()->id;

        (new MessengerDialogs())->set($this->correspondents[0], $this->correspondents[1], [
            "cleared_till" => (int) $lastId,
            "pinned"       => 0,
            "archived"     => 0,
        ]);
        $this->clearedTill = (int) $lastId;
    }

    /**
     * Удалить всю переписку у обоих корреспондентов.
     */
    public function deleteForAll(): void
    {
        $params = $this->getPairCondition();
        $cond   = array_shift($params);
        DatabaseConnection::i()->getConnection()->query("UPDATE `messages` SET `deleted` = 1, `edited` = ? WHERE `deleted` = 0 AND $cond", time(), ...$params);

        (new MessengerDialogs())->set($this->correspondents[0], $this->correspondents[1], [
            "pinned"   => 0,
            "archived" => 0,
        ]);
    }

    /**
     * Get /im?sel url.
     *
     * @returns string - URL
     */
    public function getURL(): string
    {
        $id = $this->correspondents[1]->getId();
        $id = get_class($this->correspondents[1]) === 'openvk\Web\Models\Entities\Club' ? $id * -1 : $id;

        return "/im?sel=$id";
    }

    public function getID(): int
    {
        $id = $this->correspondents[1]->getId();
        $id = get_class($this->correspondents[1]) === 'openvk\Web\Models\Entities\Club' ? $id * -1 : $id;

        return $id;
    }

    /**
     * Get correspondents as array.
     *
     * @returns RowModel[] Array of correspondents (usually two)
     */
    public function getCorrespondents(): array
    {
        return $this->correspondents;
    }

    /**
     * Fetch messages.
     *
     * Fetch messages on per page basis.
     *
     * @param $cap - page (defaults to first)
     * @param $limit  - messages per page (defaults to default per page count)
     * @returns \Traversable - iterable messages cursor
     */
    public function getMessages(int $capBehavior = 1, ?int $cap = null, ?int $limit = null, ?int $padding = null, bool $reverse = false): array
    {
        $query  = file_get_contents(__DIR__ . "/../sql/get-messages.tsql");
        $query = str_replace("(`deleted` = 0)", "(`deleted` = 0)\n  AND (`id` > " . $this->getClearedTill() . ")" . $this->getHiddenCondition(), $query);

        $params = [
            [get_class($this->correspondents[0]), get_class($this->correspondents[1])],
            [$this->correspondents[0]->getId(), $this->correspondents[1]->getId()],
            [$limit ?? OPENVK_DEFAULT_PER_PAGE],
        ];
        $params = array_merge($params[0], $params[1], array_reverse($params[0]), array_reverse($params[1]), $params[2]);

        if ($limit === null) {
            DatabaseConnection::i()->getConnection()->query("UPDATE messages SET unread = 0 WHERE sender_id = " . $this->correspondents[1]->getId() . " AND recipient_id = " . $this->correspondents[0]->getId());
        }

        if (is_null($cap)) {
            $query = str_replace("\n  AND (`id` > ?)", "", $query);
        } else {
            if ($capBehavior === 1) {
                $query = str_replace("\n  AND (`id` > ?)", "\n  AND (`id` < ?)", $query);
            }

            array_unshift($params, $cap);
        }

        if (is_null($padding)) {
            $query = str_replace("\nOFFSET\n?", "", $query);
        } else {
            $params[] = $padding;
        }

        if ($reverse) {
            $query = str_replace("`created` DESC", "`created` ASC", $query);
        }

        $msgs   = DatabaseConnection::i()->getConnection()->query($query, ...$params);
        $msgs   = array_map(function ($message) {
            $message = new ActiveRow((array) $message, $this->messages); #Directly creating ActiveRow is faster than making query

            return new Message($message);
        }, iterator_to_array($msgs));

        return $msgs;
    }

    // условие на сообщения переписки, видимые первому корреспонденту; первым элементом идёт сам SQL
    private function getVisibleCondition(): array
    {
        $params = $this->getPairCondition();
        $cond   = array_shift($params);

        return ["`deleted` = 0 AND `id` > " . $this->getClearedTill() . $this->getHiddenCondition() . " AND $cond", ...$params];
    }

    private function toMessages(iterable $rows): array
    {
        $messages = [];
        foreach ($rows as $row) {
            $messages[] = new Message(new ActiveRow((array) $row, $this->messages));
        }

        return $messages;
    }

    /**
     * Поиск по тексту сообщений, новые первыми.
     */
    public function searchMessages(string $query, int $offset = 0, int $limit = 20): array
    {
        $params = $this->getVisibleCondition();
        $cond   = array_shift($params);
        $rows   = DatabaseConnection::i()->getConnection()->query(
            "SELECT * FROM `messages` WHERE $cond AND `content` LIKE ? ORDER BY `id` DESC LIMIT ? OFFSET ?",
            ...$params,
            ...[Messages::likePattern($query), $limit, $offset]
        );

        return $this->toMessages($rows);
    }

    public function searchMessagesCount(string $query): int
    {
        $params = $this->getVisibleCondition();
        $cond   = array_shift($params);

        return (int) DatabaseConnection::i()->getConnection()->query(
            "SELECT COUNT(*) AS cnt FROM `messages` WHERE $cond AND `content` LIKE ?",
            ...$params,
            ...[Messages::likePattern($query)]
        )->fetch()->cnt;
    }

    /**
     * Кусок истории вокруг сообщения: до $before более ранних, само сообщение и до $after более поздних.
     *
     * @returns array [Message[] по возрастанию, есть ли ещё более ранние, есть ли ещё более поздние]
     */
    public function getMessagesAround(int $messageId, int $before, int $after): array
    {
        $older = $this->getMessages(self::CAP_BEHAVIOUR_END_MESSAGE_ID, $messageId, $before + 1, 0);
        $newer = $this->getMessages(self::CAP_BEHAVIOUR_START_MESSAGE_ID, $messageId - 1, $after + 2, 0, true);

        return [
            array_merge(array_reverse(array_slice($older, 0, $before)), array_slice($newer, 0, $after + 1)),
            sizeof($older) > $before,
            sizeof($newer) > $after + 1,
        ];
    }

    /**
     * Первое сообщение начиная с $timestamp, а если позже ничего нет — самое последнее.
     */
    public function getNearestMessageId(int $timestamp): ?int
    {
        $params     = $this->getVisibleCondition();
        $cond       = array_shift($params);
        $connection = DatabaseConnection::i()->getConnection();

        $row = $connection->query("SELECT `id` FROM `messages` WHERE $cond AND `created` >= ? ORDER BY `created` ASC, `id` ASC LIMIT 1", ...$params, ...[$timestamp])->fetch()
            ?? $connection->query("SELECT `id` FROM `messages` WHERE $cond ORDER BY `created` DESC, `id` DESC LIMIT 1", ...$params)->fetch();

        return $row ? (int) $row->id : null;
    }

    /**
     * Дни, в которые были сообщения, — номера суток от эпохи со сдвигом часового пояса $tzOffset (в секундах).
     */
    public function getMessageDays(int $from, int $to, int $tzOffset): array
    {
        $params = $this->getVisibleCondition();
        $cond   = array_shift($params);
        $rows   = DatabaseConnection::i()->getConnection()->query(
            "SELECT DISTINCT FLOOR((`created` + ?) / 86400) AS `day` FROM `messages` WHERE $cond AND `created` >= ? AND `created` < ?",
            $tzOffset,
            ...$params,
            ...[$from, $to]
        );

        $days = [];
        foreach ($rows as $row) {
            $days[] = (int) $row->day;
        }

        return $days;
    }

    /**
     * Время первого и последнего сообщения переписки.
     *
     * @returns array [int|null, int|null]
     */
    public function getTimeBounds(): array
    {
        $params = $this->getVisibleCondition();
        $cond   = array_shift($params);
        $row    = DatabaseConnection::i()->getConnection()->query("SELECT MIN(`created`) AS `first`, MAX(`created`) AS `last` FROM `messages` WHERE $cond", ...$params)->fetch();

        return [is_null($row->first) ? null : (int) $row->first, is_null($row->last) ? null : (int) $row->last];
    }

    public const ATTACHMENT_KINDS = ["photo", "video", "doc", "audio", "gif"];

    // тип вложения из attachments (a) и documents (d), общий для одного диалога и поиска по всем
    public const ATTACHMENT_KIND_SQL = "CASE a.attachable_type
            WHEN 'openvk\\\\Web\\\\Models\\\\Entities\\\\Photo' THEN 'photo'
            WHEN 'openvk\\\\Web\\\\Models\\\\Entities\\\\Video' THEN 'video'
            WHEN 'openvk\\\\Web\\\\Models\\\\Entities\\\\Audio' THEN 'audio'
            WHEN 'openvk\\\\Web\\\\Models\\\\Entities\\\\Document' THEN IF(d.type = 3, 'gif', 'doc')
        END";

    // общая часть выборки вложений из видимых владельцу сообщений диалога
    private function attachmentsQuery(string $select, string $tail = ""): array
    {
        $kind = self::ATTACHMENT_KIND_SQL;

        $query = "SELECT $select FROM (
            SELECT a.attachable_type, a.attachable_id, m.id AS message_id, $kind AS kind
            FROM attachments a
            JOIN messages m ON a.target_type = ? AND a.target_id = m.id
            LEFT JOIN documents d ON a.attachable_type = ? AND d.id = a.attachable_id
            WHERE m.deleted = 0
              AND m.id > " . $this->getClearedTill() . str_replace("`id`", "m.id", $this->getHiddenCondition()) . "
              AND ((m.sender_type = ? AND m.sender_id = ? AND m.recipient_type = ? AND m.recipient_id = ?)
                OR (m.sender_type = ? AND m.sender_id = ? AND m.recipient_type = ? AND m.recipient_id = ?))
        ) att WHERE att.kind IS NOT NULL $tail";

        [$a, $b] = $this->correspondents;
        return [$query, Message::class, Document::class,
            get_class($a), $a->getId(), get_class($b), $b->getId(),
            get_class($b), $b->getId(), get_class($a), $a->getId()];
    }

    /**
     * Number of attachments of each kind in the dialog.
     */
    public function getAttachmentsCount(): array
    {
        $counts = array_fill_keys(self::ATTACHMENT_KINDS, 0);
        $args   = $this->attachmentsQuery("att.kind, COUNT(*) AS cnt", "GROUP BY att.kind");
        foreach (DatabaseConnection::i()->getConnection()->query(...$args) as $row) {
            $counts[$row->kind] = (int) $row->cnt;
        }

        return $counts;
    }

    /**
     * Attachments of one kind, newest first.
     */
    public function getAttachments(string $kind, int $offset = 0, int $limit = 30): array
    {
        $args = $this->attachmentsQuery("att.attachable_type, att.attachable_id", "AND att.kind = ? ORDER BY att.message_id DESC, att.attachable_id DESC LIMIT ? OFFSET ?");
        array_push($args, $kind, $limit, $offset);

        $items = [];
        foreach (DatabaseConnection::i()->getConnection()->query(...$args) as $row) {
            $repo = str_replace("Entities", "Repositories", $row->attachable_type . "s");
            $item = (new $repo())->get((int) $row->attachable_id);
            if ($item && !$item->isDeleted()) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Get messages that were edited or deleted (soft-deleted) after $since.
     * Used for polling-based sync instead of long-poll/signaler push.
     *
     * @returns Message[] - messages touched (edited or deleted) since $since, deleted included
     */
    public function getChangedMessages(int $since): array
    {
        $connection = DatabaseConnection::i()->getConnection();
        $msgs = $connection->query(
            "SELECT * FROM `messages`
             WHERE (`edited` > ? OR `reactions_changed` >= ?)
               AND (`id` > ?)" . $this->getHiddenCondition() . "
               AND (
                 (`sender_type` = ? AND `recipient_type` = ? AND `sender_id` = ? AND `recipient_id` = ?)
                 OR
                 (`sender_type` = ? AND `recipient_type` = ? AND `sender_id` = ? AND `recipient_id` = ?)
               )
             ORDER BY `edited` ASC",
            $since,
            $since,
            $this->getClearedTill(),
            get_class($this->correspondents[0]), get_class($this->correspondents[1]),
            $this->correspondents[0]->getId(), $this->correspondents[1]->getId(),
            get_class($this->correspondents[1]), get_class($this->correspondents[0]),
            $this->correspondents[1]->getId(), $this->correspondents[0]->getId()
        );

        return array_map(function ($message) {
            $message = new ActiveRow((array) $message, $this->messages);

            return new Message($message);
        }, iterator_to_array($msgs));
    }

    /**
     * Get last message from correspondence.
     *
     * @returns Message|null - message, if any
     */
    public function getPreviewMessage(): ?Message
    {
        $messages = $this->getMessages(1, null, 1, 0);
        return $messages[0] ?? null;
    }

    /**
     * Get last message from correspondence from user.
     *
     * @returns Message|null - message, if any
     */
    public function getLastReadedMessage(int $user_id): ?Message
    {
        $query = file_get_contents(__DIR__ . "/../sql/get-messages.tsql");
        $query = str_replace("\n  AND (`id` > ?)", "\n  AND (`unread` = 0)", $query);
        $params = [
            [get_class($this->correspondents[0]), get_class($this->correspondents[1])],
            [$this->correspondents[0]->getId(), $this->correspondents[1]->getId()],
            [1], // limit
            [0], // offset
        ];

        if ($user_id == $this->correspondents[0]->getId()) {
            $params = array_merge($params[0], $params[1], $params[0], $params[1], $params[2], $params[3]);
        } elseif ($user_id == $this->correspondents[1]->getId()) {
            $params = array_merge(array_reverse($params[0]), array_reverse($params[1]), array_reverse($params[0]), array_reverse($params[1]), $params[2], $params[3]);
        }

        $connection = DatabaseConnection::i()->getConnection();
        $msgs = $connection->query($query, ...$params);
        $msgRow = $msgs->fetch();
        if ($msgRow !== null) {
            $msg = new ActiveRow((array) $msgRow, $this->messages);
            return new Message($msg);
        } else {
            return null;
        }
    }

    /**
     * Send message.
     *
     * @deprecated
     * @returns Message|false - resulting message, or false in case of non-successful transaction
     */
    public function sendMessage(Message $message, bool $dontReverse = false)
    {
        if (!$dontReverse) {
            $user = (new Users())->getByChandlerUser(Authenticator::i()->getUser());
            if (!$user) {
                return false;
            }
        }

        $ids     = [$this->correspondents[0]->getId(), $this->correspondents[1]->getId()];
        $classes = [get_class($this->correspondents[0]), get_class($this->correspondents[1])];
        if (!$dontReverse && $ids[1] === $user->getId()) {
            $ids     = array_reverse($ids);
            $classes = array_reverse($classes);
        }

        $message->setSender_Id($ids[0]);
        $message->setRecipient_Id($ids[1]);
        $message->setSender_Type($classes[0]);
        $message->setRecipient_Type($classes[1]);
        $message->setCreated(time());
        $message->setUnread(1);
        $message->save();

        DatabaseConnection::i()->getConnection()->query("UPDATE messages SET unread = 0 WHERE sender_id = " . $ids[1] . " AND recipient_id = " . $ids[0]);

        # да
        if ($ids[0] !== $ids[1]) {
            $event = new NewMessageEvent($message);
            (SignalManager::i())->triggerEvent($event, $ids[1]);

            $this->pushMessageNotification($message, $ids[0], $ids[1], $classes[0]);
        }

        return $message;
    }

    private function pushMessageNotification(Message $message, int $senderId, int $recipientId, string $senderClass): void
    {
        $notifConf = OPENVK_ROOT_CONF["openvk"]["credentials"]["notificationsBroker"] ?? [];
        if (!($notifConf["enable"] ?? false)) {
            return;
        }

        $sender = $message->getSender();
        if (!$sender) {
            return;
        }

        $recipient = $message->getRecipient();
        if ($recipient && (new MessengerDialogs())->get($recipient, $sender)["muted"]) {
            return;
        }

        $preview = trim(strip_tags($message->getPreviewText()));
        if (iconv_strlen($preview) > 100) {
            $preview = iconv_substr($preview, 0, 100) . "…";
        }

        $senderUrlId = $senderClass === Club::class ? $senderId * -1 : $senderId;

        try {
            NotificationBroker::i()->push($recipientId, [
                "kind" => "message",
                "data" => [
                    "senderId"   => $senderUrlId,
                    "senderName" => $sender->getCanonicalName(),
                    "body"       => $preview,
                    "ava"        => $sender->getAvatarUrl(),
                    "url"        => "/im?sel=$senderUrlId",
                    "timestamp"  => time(),
                ],
            ]);
        } catch (\Throwable $e) {
            error_log("Message notification push error: " . $e->getMessage());
        }
    }

    /**
     * Send typing event.
     *
     * @returns true|false
     */
    public function sendTypingEvent()
    {
        $ids     = [$this->correspondents[0]->getId(), $this->correspondents[1]->getId()];

        if ($ids[0] !== $ids[1]) {
            // читается MessengerPresenter::apiSync() при поллинге; централизовано здесь,
            // а не в веб-презентере, чтобы работало и для VKAPI messages.setActivity (Matcha и т.п.)
            if (function_exists("apcu_store")) {
                apcu_store("typing:{$ids[0]}:{$ids[1]}", 1, 5);
            }

            try {
                (SignalManager::i())->triggerEvent(new TypingEvent($ids[0]), $ids[1]);
            } catch (\Throwable $e) {
                error_log("Typing event error: " . $e->getMessage());
            }
        }

        return true;
    }
}
