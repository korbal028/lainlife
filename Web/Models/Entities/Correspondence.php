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
use openvk\Web\Models\Repositories\{Users, MessengerDialogs};
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
        [$cond, ...$params] = $this->getPairCondition();
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
        [$cond, ...$params] = $this->getPairCondition();
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
        if ($this->getClearedTill() > 0) {
            $query = str_replace("(`deleted` = 0)", "(`deleted` = 0)\n  AND (`id` > " . $this->getClearedTill() . ")", $query);
        }

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
             WHERE (`edited` > ?)
               AND (`id` > ?)
               AND (
                 (`sender_type` = ? AND `recipient_type` = ? AND `sender_id` = ? AND `recipient_id` = ?)
                 OR
                 (`sender_type` = ? AND `recipient_type` = ? AND `sender_id` = ? AND `recipient_id` = ?)
               )
             ORDER BY `edited` ASC",
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
            $event = new TypingEvent($ids[0]);
            (SignalManager::i())->triggerEvent($event, $ids[1]);

            // читается MessengerPresenter::apiSync() при поллинге; централизовано здесь,
            // а не в веб-презентере, чтобы работало и для VKAPI messages.setActivity (Matcha и т.п.)
            if (function_exists("apcu_store")) {
                apcu_store("typing:{$ids[0]}:{$ids[1]}", 1, 5);
            }
        }

        return true;
    }
}
