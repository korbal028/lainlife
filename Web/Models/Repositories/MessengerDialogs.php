<?php

declare(strict_types=1);

namespace openvk\Web\Models\Repositories;

use openvk\Web\Models\RowModel;
use openvk\Web\Models\Entities\Club;
use Chandler\Database\DatabaseConnection;

class MessengerDialogs
{
    private const DEFAULTS = [
        "pinned"       => 0,
        "archived"     => 0,
        "muted"        => 0,
        "cleared_till" => 0,
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

        return array_intersect_key((array) $row, self::DEFAULTS);
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
            $sel = $row->peer_type === Club::class ? -1 * (int) $row->peer_id : (int) $row->peer_id;
            $result[$sel] = array_intersect_key((array) $row, self::DEFAULTS);
        }

        return $result;
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
