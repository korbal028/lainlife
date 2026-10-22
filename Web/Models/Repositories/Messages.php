<?php

declare(strict_types=1);

namespace openvk\Web\Models\Repositories;

use openvk\Web\Models\RowModel;
use openvk\Web\Models\Entities\User;
use openvk\Web\Models\Entities\Club;
use openvk\Web\Models\Entities\Document;
use openvk\Web\Models\Entities\Message;
use openvk\Web\Models\Entities\Correspondence;
use openvk\Web\Models\Entities\GroupChat;
use Chandler\Database\DatabaseConnection;
use Nette\Database\Table\ActiveRow;

class Messages
{
    private $context;
    private $messages;

    /* aggressive sql caching */
    private static $cache = [];

    public function __construct()
    {
        $this->context  = DatabaseConnection::i()->getContext();
        $this->messages = $this->context->table("messages");
    }

    private function toMessage(?ActiveRow $ar): ?Message
    {
        return is_null($ar) ? null : new Message($ar);
    }

    public function get(int $id): ?Message
    {
        return self::$cache[$id] ??= $this->toMessage($this->messages->get($id));
    }

    // $withGroupChats — подмешать беседы пользователя (GroupChat) в общий список
    public function getCorrespondencies(RowModel $correspondent, int $page = 1, ?int $perPage = null, ?int $offset = null, bool $archived = false, bool $withGroupChats = false): \Traversable
    {
        $id      = $correspondent->getId();
        $class   = get_class($correspondent);
        $limit   = $perPage ?? OPENVK_DEFAULT_PER_PAGE;
        $offset ??= ($page - 1) * $limit;
        $query   = file_get_contents(__DIR__ . "/../sql/get-correspondencies.tsql");
        DatabaseConnection::i()->getConnection()->query(file_get_contents(__DIR__ . "/../sql/mysql-msg-fix.tsql"));
        $hiddenOwner = $correspondent instanceof User ? $id : 0;
        $rows = DatabaseConnection::i()->getConnection()->query($query, $id, $class, $id, $class, $id, $class, $hiddenOwner, (int) $archived)->fetchAll();

        // закреплённые — сверху в своём порядке, остальные — от новых к старым. Порядок берётся из тех же настроек диалогов,
        // по которым рисуется значок закрепа, чтобы чат с новым сообщением никогда не оказывался выше закреплённых
        $settings = (new MessengerDialogs())->getAllFor($correspondent);
        if ($withGroupChats) {
            $rows = array_merge($rows, $this->getGroupChatRows($correspondent, $settings, $archived));
        }

        $pinned   = [];
        $rest     = [];
        foreach ($rows as $i => $row) {
            $sel = match ($row->class) {
                Club::class      => -(int) $row->id,
                GroupChat::class => GroupChat::PEER_OFFSET + (int) $row->id,
                default          => (int) $row->id,
            };
            $pos = (int) ($settings[$sel]["pinned"] ?? 0);
            if ($pos > 0) {
                $pinned[] = [$pos, $i, $row];
            } else {
                $rest[] = [(int) $row->time, $i, $row];
            }
        }
        usort($pinned, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        usort($rest, fn ($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        $coresps = array_slice(array_merge(array_column($pinned, 2), array_column($rest, 2)), $offset, $limit);
        foreach ($coresps as $c) {
            if ($c->class === GroupChat::class) {
                yield (new GroupChats())->get((int) $c->id);
                continue;
            }

            if ($c->class === 'openvk\Web\Models\Entities\User') {
                $anotherCorrespondent = (new Users())->get($c->id);
            } elseif ($c->class === 'openvk\Web\Models\Entities\Club') {
                $anotherCorrespondent = (new Clubs())->get($c->id);
            }

            yield new Correspondence($correspondent, $anotherCorrespondent);
        }
    }

    public function getCorrespondenciesCount(RowModel $correspondent, bool $archived = false, bool $withGroupChats = false): ?int
    {
        $id    = $correspondent->getId();
        $class = get_class($correspondent);
        $query = file_get_contents(__DIR__ . "/../sql/get-correspondencies-count.tsql");
        DatabaseConnection::i()->getConnection()->query(file_get_contents(__DIR__ . "/../sql/mysql-msg-fix.tsql"));
        $hiddenOwner = $correspondent instanceof User ? $id : 0;
        $count = DatabaseConnection::i()->getConnection()->query($query, $id, $class, $id, $class, $id, $class, $hiddenOwner, (int) $archived)->fetch()->cnt;
        if ($withGroupChats) {
            $count += sizeof($this->getGroupChatRows($correspondent, (new MessengerDialogs())->getAllFor($correspondent), $archived));
        }

        return $count;
    }

    // беседы пользователя в том же виде, что строки get-correspondencies.tsql; время — последнее сообщение или создание беседы
    private function getGroupChatRows(RowModel $owner, array $settings, bool $archived): array
    {
        if (!($owner instanceof User)) {
            return [];
        }

        $rows = $this->context->query(
            "SELECT c.`id`, COALESCE(MAX(m.`created`), c.`created`) AS `time`
             FROM `group_chats` c
             LEFT JOIN `group_messages` m ON m.`chat_id` = c.`id` AND m.`deleted` = 0
             WHERE c.`deleted` = 0 AND FIND_IN_SET(?, c.`participants`) > 0
             GROUP BY c.`id`, c.`created`",
            (string) $owner->getId()
        );

        $result = [];
        foreach ($rows as $row) {
            $sel = GroupChat::PEER_OFFSET + (int) $row->id;
            if ((bool) ($settings[$sel]["archived"] ?? 0) !== $archived) {
                continue;
            }

            $result[] = (object) ["id" => (int) $row->id, "class" => GroupChat::class, "time" => (int) $row->time];
        }

        return $result;
    }

    // подстрока для LIKE, без подстановочных символов из самого запроса
    // сколько раз пользователь ставит каждую реакцию, для строки частых в меню
    public function getReactionStats(User $user): array
    {
        $rows = $this->context->query("SELECT `reaction`, COUNT(*) AS `cnt` FROM `message_reactions` WHERE `user` = ? GROUP BY `reaction`", $user->getId());

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row->reaction] = (int) $row->cnt;
        }

        return $stats;
    }

    public static function likePattern(string $query): string
    {
        return "%" . addcslashes($query, "%_\\") . "%";
    }

    private function toCorrespondence(RowModel $owner, object $row): ?Correspondence
    {
        if ($row->class === User::class) {
            $peer = (new Users())->get((int) $row->id);
        } elseif ($row->class === Club::class) {
            $peer = (new Clubs())->get((int) $row->id);
        }

        return isset($peer) ? new Correspondence($owner, $peer) : null;
    }

    private function searchCorrespondenciesQuery(RowModel $owner, string $query, bool $matchSelf): array
    {
        $id    = $owner->getId();
        $class = get_class($owner);
        $like  = self::likePattern($query);
        DatabaseConnection::i()->getConnection()->query(file_get_contents(__DIR__ . "/../sql/mysql-msg-fix.tsql"));

        return [
            file_get_contents(__DIR__ . "/../sql/search-correspondencies.tsql"),
            $id, $class, $id, $class, $id, $class, User::class, Club::class, $owner instanceof User ? $id : 0,
            $class, $id, (int) $matchSelf, $like, $like, $like,
        ];
    }

    /**
     * Диалоги владельца (и из архива), собеседник которых подходит под запрос по имени.
     *
     * @param $matchSelf - подходит ли под запрос название чата с самим собой
     */
    public function searchCorrespondencies(RowModel $owner, string $query, bool $matchSelf, int $page = 1, ?int $perPage = null): array
    {
        $limit  = $perPage ?? OPENVK_DEFAULT_PER_PAGE;
        $params = $this->searchCorrespondenciesQuery($owner, $query, $matchSelf);
        $sql    = array_shift($params);

        $result = [];
        foreach (DatabaseConnection::i()->getConnection()->query("$sql LIMIT ? OFFSET ?", ...$params, ...[$limit, ($page - 1) * $limit]) as $row) {
            $cor = $this->toCorrespondence($owner, $row);
            if ($cor) {
                $result[] = $cor;
            }
        }

        return $result;
    }

    public function searchCorrespondenciesCount(RowModel $owner, string $query, bool $matchSelf): int
    {
        $params = $this->searchCorrespondenciesQuery($owner, $query, $matchSelf);
        $sql    = array_shift($params);

        return (int) DatabaseConnection::i()->getConnection()->query("SELECT COUNT(*) AS cnt FROM ($sql) x", ...$params)->fetch()->cnt;
    }

    // сообщения всех диалогов, видимые владельцу: без удалённых, очищенных и скрытых у него
    private function visibleMessagesQuery(RowModel $owner): array
    {
        $id    = $owner->getId();
        $class = get_class($owner);

        return [
            "SELECT m.* FROM messages m
            LEFT JOIN messenger_dialogs md
              ON md.owner_type = ? AND md.owner_id = ?
              AND md.peer_type = IF(m.sender_type = ? AND m.sender_id = ?, m.recipient_type, m.sender_type)
              AND md.peer_id = IF(m.sender_type = ? AND m.sender_id = ?, m.recipient_id, m.sender_id)
            WHERE m.deleted = 0
              AND ((m.sender_type = ? AND m.sender_id = ?) OR (m.recipient_type = ? AND m.recipient_id = ?))
              AND m.id > COALESCE(md.cleared_till, 0)
              AND m.id NOT IN (SELECT message_id FROM messages_hidden WHERE owner_id = ?)",
            $class, $id, $class, $id, $class, $id, $class, $id, $class, $id, $owner instanceof User ? $id : 0,
        ];
    }

    /**
     * Поиск по тексту сообщений во всех диалогах владельца, новые первыми.
     */
    public function searchMessages(RowModel $owner, string $query, int $offset = 0, int $limit = 20): array
    {
        $params = $this->visibleMessagesQuery($owner);
        $sql    = array_shift($params);
        $rows   = DatabaseConnection::i()->getConnection()->query(
            "SELECT vm.* FROM ($sql) vm WHERE vm.content LIKE ? ORDER BY vm.id DESC LIMIT ? OFFSET ?",
            ...$params,
            ...[self::likePattern($query), $limit, $offset]
        );

        $messages = [];
        foreach ($rows as $row) {
            $messages[] = new Message(new ActiveRow((array) $row, $this->messages));
        }

        return $messages;
    }

    public function searchMessagesCount(RowModel $owner, string $query): int
    {
        $params = $this->visibleMessagesQuery($owner);
        $sql    = array_shift($params);

        return (int) DatabaseConnection::i()->getConnection()->query("SELECT COUNT(*) AS cnt FROM ($sql) vm WHERE vm.content LIKE ?", ...$params, ...[self::likePattern($query)])->fetch()->cnt;
    }

    // как Correspondence::attachmentsQuery(), только по всем диалогам владельца
    private function attachmentsQuery(RowModel $owner, string $select, string $tail = ""): array
    {
        $params = $this->visibleMessagesQuery($owner);
        $sql    = array_shift($params);
        $kind   = Correspondence::ATTACHMENT_KIND_SQL;

        return [
            "SELECT $select FROM (
                SELECT a.attachable_type, a.attachable_id, m.id AS message_id, $kind AS kind
                FROM attachments a
                JOIN ($sql) m ON a.target_type = ? AND a.target_id = m.id
                LEFT JOIN documents d ON a.attachable_type = ? AND d.id = a.attachable_id
            ) att WHERE att.kind IS NOT NULL $tail",
            ...$params,
            ...[Message::class, Document::class],
        ];
    }

    /**
     * Number of attachments of each kind in all dialogs of the owner.
     */
    public function getAttachmentsCount(RowModel $owner): array
    {
        $counts = array_fill_keys(Correspondence::ATTACHMENT_KINDS, 0);
        foreach (DatabaseConnection::i()->getConnection()->query(...$this->attachmentsQuery($owner, "att.kind, COUNT(*) AS cnt", "GROUP BY att.kind")) as $row) {
            $counts[$row->kind] = (int) $row->cnt;
        }

        return $counts;
    }

    /**
     * Attachments of one kind from all dialogs of the owner, newest first.
     *
     * @returns array пары [вложение, сообщение с ним]
     */
    public function getAttachments(RowModel $owner, string $kind, int $offset = 0, int $limit = 30): array
    {
        $args = $this->attachmentsQuery($owner, "att.attachable_type, att.attachable_id, att.message_id", "AND att.kind = ? ORDER BY att.message_id DESC, att.attachable_id DESC LIMIT ? OFFSET ?");
        array_push($args, $kind, $limit, $offset);

        $items = [];
        foreach (DatabaseConnection::i()->getConnection()->query(...$args) as $row) {
            $repo    = str_replace("Entities", "Repositories", $row->attachable_type . "s");
            $item    = (new $repo())->get((int) $row->attachable_id);
            $message = $this->get((int) $row->message_id);
            if ($item && !$item->isDeleted() && $message) {
                $items[] = [$item, $message];
            }
        }

        return $items;
    }
}
