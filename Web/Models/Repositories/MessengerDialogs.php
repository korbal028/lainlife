<?php

declare(strict_types=1);

namespace openvk\Web\Models\Repositories;

use openvk\Web\Models\RowModel;
use openvk\Web\Models\Entities\Club;
use openvk\Web\Models\Entities\GroupChat;
use openvk\Web\Models\Entities\User;
use Chandler\Database\DatabaseConnection;

class MessengerDialogs
{
    private const DEFAULTS = [
        "pinned"       => 0,
        "archived"     => 0,
        "muted"        => 0,
        "cleared_till"   => 0,
        "read_till"      => 0,
        "joined_till"    => 0,
        "pinned_message" => 0,
    ];

    private $connection;

    public function __construct()
    {
        $this->connection = DatabaseConnection::i()->getConnection();
    }

    public function get(RowModel $owner, RowModel $peer): array
    {
        $row = $this->connection->query(
            "SELECT * FROM `messenger_dialogs` WHERE `owner_type` = ? AND `owner_id` = ? AND `peer_type` = ? AND `peer_id` = ?",
            get_class($owner),
            $owner->getId(),
            get_class($peer),
            $peer->getId()
        )->fetch();

        if (!$row) {
            return self::DEFAULTS;
        }

        return array_merge(self::DEFAULTS, array_intersect_key((array) $row, self::DEFAULTS));
    }

    /**
     * Настройки всех диалогов владельца, ключ — id собеседника как в /im?sel= (у клубов отрицательный).
     */
    public function getAllFor(RowModel $owner): array
    {
        $rows = $this->connection->query(
            "SELECT * FROM `messenger_dialogs` WHERE `owner_type` = ? AND `owner_id` = ?",
            get_class($owner),
            $owner->getId()
        );

        $result = [];
        foreach ($rows as $row) {
            $sel = match ($row->peer_type) {
                Club::class      => -1 * (int) $row->peer_id,
                GroupChat::class => GroupChat::PEER_OFFSET + (int) $row->peer_id,
                default          => (int) $row->peer_id,
            };
            $result[$sel] = array_intersect_key((array) $row, self::DEFAULTS);
        }

        return $result;
    }

    /**
     * Отметки прочтения участников беседы: id участника => [read_till, joined_till].
     */
    public function getChatReadMarks(int $chatId): array
    {
        $rows = $this->connection->query(
            "SELECT `owner_id`, `read_till`, `joined_till` FROM `messenger_dialogs` WHERE `peer_type` = ? AND `peer_id` = ? AND `owner_type` = ? AND `read_till` > 0",
            GroupChat::class,
            $chatId,
            User::class
        );

        $marks = [];
        foreach ($rows as $row) {
            $marks[(int) $row->owner_id] = [(int) $row->read_till, (int) $row->joined_till];
        }

        return $marks;
    }

    public function pinToTop(RowModel $owner, RowModel $peer): void
    {
        $this->connection->query(
            "UPDATE `messenger_dialogs` SET `pinned` = `pinned` + 1 WHERE `owner_type` = ? AND `owner_id` = ? AND `pinned` > 0",
            get_class($owner),
            $owner->getId()
        );

        $this->set($owner, $peer, ["pinned" => 1, "archived" => 0]);
    }

    public function set(RowModel $owner, RowModel $peer, array $fields): void
    {
        $fields = array_intersect_key($fields, self::DEFAULTS);
        if (sizeof($fields) === 0) {
            return;
        }

        $data = array_merge([
            "owner_type" => get_class($owner),
            "owner_id"   => $owner->getId(),
            "peer_type"  => get_class($peer),
            "peer_id"    => $peer->getId(),
        ], $fields);

        $this->connection->query("INSERT INTO `messenger_dialogs` ? ON DUPLICATE KEY UPDATE ?", $data, $fields);
    }
}
