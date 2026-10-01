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
use openvk\Web\Models\Repositories\Users;
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
        $params = [
            [get_class($this->correspondents[0]), get_class($this->correspondents[1])],
            [$this->correspondents[0]->getId(), $this->correspondents[1]->getId()],
            [$limit ?? OPENVK_DEFAULT_PER_PAGE],
        ];
        $params = array_merge($params[0], $params[1], array_reverse($params[0]), array_reverse($params[1]), $params[2]);

        if ($limit === null) {
            // Без фильтра по recipient_id это помечало прочитанными ВСЕ сообщения
            // от этого отправителя у ЛЮБОГО его собеседника, а не только у того,
            // кто сейчас реально открыл переписку - отсюда баг с "зависшим"
            // непрочитанным, которое пропадало только когда кто-то ДРУГОЙ отвечал
            // тому же отправителю (и попутно стирал счётчик не у себя, а у вас).
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
               AND (
                 (`sender_type` = ? AND `recipient_type` = ? AND `sender_id` = ? AND `recipient_id` = ?)
                 OR
                 (`sender_type` = ? AND `recipient_type` = ? AND `sender_id` = ? AND `recipient_id` = ?)
               )
             ORDER BY `edited` ASC",
            $since,
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

        // $ids уже учитывает возможный reverse выше - используем именно его,
        // а не $this->correspondents напрямую, и обязательно фильтруем по
        // recipient_id (см. тот же фикс и объяснение в getMessages()).
        DatabaseConnection::i()->getConnection()->query("UPDATE messages SET unread = 0 WHERE sender_id = " . $ids[1] . " AND recipient_id = " . $ids[0]);

        # да
        if ($ids[0] !== $ids[1]) {
            $event = new NewMessageEvent($message);
            (SignalManager::i())->triggerEvent($event, $ids[1]);

            $this->pushMessageNotification($message, $ids[0], $ids[1], $classes[0]);
        }

        return $message;
    }

    /**
     * Pushes a live toast notification about a new message to the recipient,
     * via the same broker/poll pipeline used for likes/comments (al_notifs.js).
     * Doesn't touch the /notifications feed - messages keep their own unread counter.
     *
     * Only raw, language-agnostic data is pushed here (this runs in the SENDER's
     * request/session). Title/body translation and relative time formatting are
     * done later in ServiceAPI\Notifications::fetch(), which runs in the
     * RECIPIENT's session - otherwise the notification ends up in the sender's
     * language/timezone instead of the recipient's.
     */
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
