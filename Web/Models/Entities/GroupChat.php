<?php declare(strict_types=1);
namespace openvk\Web\Models\Entities;
use Chandler\Database\DatabaseConnection;
use openvk\Web\Models\Repositories\{Users, Messages};
use openvk\Web\Models\Entities\Messages\Sticker;
use openvk\Web\Models\RowModel;
use openvk\Web\Util\DateTime;

/**
 * GroupChat entity.
 */
class GroupChat extends RowModel
{
    // peer_id беседы = PEER_OFFSET + id, как в VK/OpenVK
    public const PEER_OFFSET = 2000000000;

    public const PERMISSIONS = [
        "send"      => true,
        "photos"    => true,
        "videos"    => true,
        "audios"    => true,
        "docs"      => true,
        "stickers"  => true,
        "reactions" => true,
        "invite"    => false,
        "pin"       => true,
        "tags"      => false,
        "info"      => false,
    ];

    public const ADMIN_RIGHTS = [
        "delete"     => true,
        "ban"        => true,
        "invite"     => true,
        "pin"        => true,
        "tags"       => true,
        "add_admins" => false,
    ];

    public const LOG_TTL = 604800;

    public const SLOWMODE_INTERVALS = [0, 5, 10, 30, 60, 300, 900, 3600];

    public const TAG_MAX_LENGTH         = 16;
    public const NAME_MAX_LENGTH        = 64;
    public const DESCRIPTION_MAX_LENGTH = 1000;

    protected $tableName = "group_chats";

    public function getRecord(): ?\Nette\Database\Table\ActiveRow
    {
        return $this->record;
    }

    /**
     * Get chat creator/owner.
     */
    function getCreator(): ?User
    {
        return (new Users())->get($this->getRecord()->creator_id);
    }

    /**
     * Check if user is the creator.
     */
    function isCreator(int $userId): bool
    {
        return $this->getRecord()->creator_id == $userId;
    }

    /**
     * Get chat name.
     */
    function getName(): string
    {
    $db = \Chandler\Database\DatabaseConnection::i()->getContext();
    $fresh = $db->table("group_chats")->get($this->getId());
    return $fresh->name ?? "Безымянный чат";
    }

    /**
     * Get chat description.
     */
    function getDescription(): ?string
    {
        return DatabaseConnection::i()->getContext()->table("group_chats")->get($this->getId())?->description;
    }

    /**
     * Get chat avatar URL.
     */
function getAvatarUrl(string $size = 'normal'): string
{
    $db = \Chandler\Database\DatabaseConnection::i()->getContext();
    $fresh = $db->table("group_chats")->get($this->getId());
    $avatar = $fresh->avatar ?? null;
    if(empty($avatar)) {
        return ovk_scheme(true) . $_SERVER["HTTP_HOST"] . "/assets/packages/static/openvk/img/camera_200.png";
    }
    if(preg_match('/\/photo(\d+)_(\d+)/', $avatar, $matches)) {
        $ownerId = (int)$matches[1];
        $photoId = (int)$matches[2];
        $photoRow = $db->table("photos")
            ->where("owner", $ownerId)
            ->where("virtual_id", $photoId)
            ->fetch();
        if($photoRow) {
            $photo = new \openvk\Web\Models\Entities\Photo($photoRow);
            return $photo->getURLBySizeId($size === 'miniscule' ? 'miniscule' : 'normal');
        }
    }
    return ovk_scheme(true) . $_SERVER["HTTP_HOST"] . "/assets/packages/static/openvk/img/camera_200.png";
}
    /**
     * Get chat creation date.
     */
    function getCreatedTime(): DateTime
    {
        return new DateTime($this->getRecord()->created);
    }

    /**
     * Get participants count.
     */
    function getParticipantsCount(): int
{
    $db = \Chandler\Database\DatabaseConnection::i()->getContext();
    $fresh = $db->table("group_chats")->get($this->getId());
    $participants = $fresh->participants ?? "";
    if(empty($participants)) return 0;
    return count(array_filter(explode(",", $participants)));
}
    /**
     * Get last message in chat.
     */
    function getLastMessage(): ?Message
    {
        $row = DatabaseConnection::i()->getContext()->table("messages")
            ->where(["recipient_type" => self::class, "recipient_id" => $this->getId(), "deleted" => 0, "visible_to" => null])
            ->order("id DESC")
            ->fetch();

        return $row ? (new Messages())->get((int) $row->id) : null;
    }

    function getParticipantIds(): array
    {
        $fresh = DatabaseConnection::i()->getContext()->table("group_chats")->get($this->getId());

        return array_values(array_map("intval", array_filter(explode(",", (string) ($fresh->participants ?? "")))));
    }

    function isParticipant(int $userId): bool
    {
        return in_array($userId, $this->getParticipantIds(), true);
    }

    /**
     * Get chat URL.
     */
    function getURL(): string
    {
        return "/im?sel=" . $this->getPeerId();
    }

    // для шаблонов списка диалогов, где рядом лежат User и Club
    function getCanonicalName(): string
    {
        return $this->getName();
    }

    // ссылка-приглашение; код создаётся при первом запросе, $reset выдаёт новый и старая ссылка перестаёт работать
    // видят ли новые участники сообщения, отправленные до их вступления
    function isHistoryVisible(): bool
    {
        $fresh = DatabaseConnection::i()->getContext()->table("group_chats")->get($this->getId());

        return (bool) ($fresh->history_visible ?? 1);
    }

    function setHistoryVisible(bool $visible): void
    {
        DatabaseConnection::i()->getContext()->table("group_chats")->where("id", $this->getId())->update(["history_visible" => (int) $visible]);
    }

    function getPermissions(): array
    {
        $fresh = DatabaseConnection::i()->getContext()->table("group_chats")->get($this->getId());
        $saved = json_decode((string) ($fresh->permissions ?? ""), true);

        return array_merge(self::PERMISSIONS, array_intersect_key(is_array($saved) ? $saved : [], self::PERMISSIONS));
    }

    function setPermissions(array $permissions): void
    {
        $permissions = array_merge($this->getPermissions(), array_map("boolval", array_intersect_key($permissions, self::PERMISSIONS)));

        DatabaseConnection::i()->getContext()->table("group_chats")->where("id", $this->getId())->update(["permissions" => json_encode($permissions)]);
    }

    function getPermissionsFor(int $userId): array
    {
        if ($this->canManageParticipants($userId)) {
            $restricted = $this->isCreator($userId) ? null : ($this->getException($userId)["permissions"] ?? null);
            $base       = $restricted ?? $this->getPermissions();
            $rights     = $restricted ?? array_fill_keys(array_keys(self::PERMISSIONS), true);
            $rights["info"]   = true;
            $rights["pin"]    = $base["pin"] || $this->hasAdminRight("pin", $userId);
            $rights["invite"] = $base["invite"] || $this->hasAdminRight("invite", $userId);

            return $rights;
        }

        return $this->getException($userId)["permissions"] ?? $this->getPermissions();
    }

    function getExceptions(): array
    {
        $rows = DatabaseConnection::i()->getConnection()->query("SELECT `user`, `permissions`, `slowmode` FROM `group_chat_exceptions` WHERE `chat` = ?", $this->getId());

        $exceptions = [];
        foreach ($rows as $row) {
            $saved = json_decode((string) $row->permissions, true);
            $exceptions[(int) $row->user] = [
                "id"          => (int) $row->user,
                "permissions" => array_merge(self::PERMISSIONS, array_intersect_key(is_array($saved) ? $saved : [], self::PERMISSIONS)),
                "slowmode"    => (int) $row->slowmode,
            ];
        }

        return $exceptions;
    }

    function getException(int $userId): ?array
    {
        return $this->getExceptions()[$userId] ?? null;
    }

    function setException(int $userId, array $permissions, ?int $slowmode): void
    {
        $current     = $this->getException($userId);
        $permissions = array_merge($current["permissions"] ?? $this->getPermissions(), array_map("boolval", array_intersect_key($permissions, self::PERMISSIONS)));
        if (!in_array($slowmode, self::SLOWMODE_INTERVALS, true)) {
            $slowmode = $current["slowmode"] ?? $this->getSlowmode();
        }

        $row = ["chat" => $this->getId(), "user" => $userId, "permissions" => json_encode($permissions), "slowmode" => $slowmode];
        DatabaseConnection::i()->getConnection()->query("INSERT INTO `group_chat_exceptions` ? ON DUPLICATE KEY UPDATE ?", $row, ["permissions" => $row["permissions"], "slowmode" => $slowmode]);
    }

    function removeException(int $userId): void
    {
        DatabaseConnection::i()->getConnection()->query("DELETE FROM `group_chat_exceptions` WHERE `chat` = ? AND `user` = ?", $this->getId(), $userId);
    }

    function can(string $permission, int $userId): bool
    {
        return $this->getPermissionsFor($userId)[$permission] ?? false;
    }

    function getSlowmode(): int
    {
        $fresh = DatabaseConnection::i()->getContext()->table("group_chats")->get($this->getId());

        return (int) ($fresh->slowmode ?? 0);
    }

    function setSlowmode(int $seconds): void
    {
        if (!in_array($seconds, self::SLOWMODE_INTERVALS, true)) {
            return;
        }

        DatabaseConnection::i()->getContext()->table("group_chats")->where("id", $this->getId())->update(["slowmode" => $seconds]);
    }

    function getSlowmodeFor(int $userId): int
    {
        if ($this->isCreator($userId)) {
            return 0;
        }

        return $this->getException($userId)["slowmode"] ?? ($this->isAdmin($userId) ? 0 : $this->getSlowmode());
    }

    function getSlowmodeWait(int $userId): int
    {
        $interval = $this->getSlowmodeFor($userId);
        if ($interval === 0) {
            return 0;
        }

        $last = DatabaseConnection::i()->getContext()->table("messages")
            ->where(["recipient_type" => self::class, "recipient_id" => $this->getId(), "sender_type" => User::class, "sender_id" => $userId, "action" => null])
            ->order("created DESC")
            ->fetch();

        return $last ? max(0, (int) $last->created + $interval - time()) : 0;
    }

    static function getAttachmentPermission(object $attachment): ?string
    {
        return match (true) {
            $attachment instanceof Photo    => "photos",
            $attachment instanceof Video    => "videos",
            $attachment instanceof Audio    => "audios",
            $attachment instanceof Sticker  => "stickers",
            $attachment instanceof Document => $attachment->isGif() ? "stickers" : "docs",
            default                         => null,
        };
    }

    function setInfo(string $name, string $description): void
    {
        DatabaseConnection::i()->getContext()->table("group_chats")->where("id", $this->getId())->update([
            "name"        => mb_substr($name, 0, self::NAME_MAX_LENGTH),
            "description" => mb_substr($description, 0, self::DESCRIPTION_MAX_LENGTH),
        ]);
    }

    function setAvatar(?string $avatar): void
    {
        DatabaseConnection::i()->getContext()->table("group_chats")->where("id", $this->getId())->update(["avatar" => $avatar]);
    }

    function getTags(): array
    {
        $rows = DatabaseConnection::i()->getConnection()->query("SELECT `user`, `tag`, `color` FROM `group_chat_tags` WHERE `chat` = ?", $this->getId());

        $tags = [];
        foreach ($rows as $row) {
            $tags[(int) $row->user] = ["text" => $row->tag, "color" => $row->color];
        }

        return $tags;
    }

    function setTag(int $userId, string $text, string $color): void
    {
        $text = mb_substr(trim(preg_replace("%\\s+%u", " ", $text)), 0, self::TAG_MAX_LENGTH);
        if ($text === "") {
            DatabaseConnection::i()->getConnection()->query("DELETE FROM `group_chat_tags` WHERE `chat` = ? AND `user` = ?", $this->getId(), $userId);
            return;
        }

        $row = ["chat" => $this->getId(), "user" => $userId, "tag" => $text, "color" => strtolower($color)];
        DatabaseConnection::i()->getConnection()->query("INSERT INTO `group_chat_tags` ? ON DUPLICATE KEY UPDATE ?", $row, ["tag" => $text, "color" => $row["color"]]);
    }

    function getBans(): array
    {
        $rows  = DatabaseConnection::i()->getConnection()->query("SELECT `user`, `admin`, `created` FROM `group_chat_bans` WHERE `chat` = ? ORDER BY `created` DESC", $this->getId());
        $users = new Users();

        $bans = [];
        foreach ($rows as $row) {
            $user  = $users->get((int) $row->user);
            $admin = $users->get((int) $row->admin);
            if (!$user) {
                continue;
            }

            $bans[] = [
                "id"     => $user->getId(),
                "name"   => $user->getCanonicalName(),
                "avatar" => $user->getAvatarUrl("miniscule"),
                "link"   => $user->getURL(),
                "by"     => $admin?->getCanonicalName(),
            ];
        }

        return $bans;
    }

    function isBanned(int $userId): bool
    {
        return (bool) DatabaseConnection::i()->getConnection()->query("SELECT 1 FROM `group_chat_bans` WHERE `chat` = ? AND `user` = ?", $this->getId(), $userId)->fetch();
    }

    function ban(int $userId, int $adminId): void
    {
        $row = ["chat" => $this->getId(), "user" => $userId, "admin" => $adminId, "created" => time()];
        DatabaseConnection::i()->getConnection()->query("INSERT INTO `group_chat_bans` ? ON DUPLICATE KEY UPDATE ?", $row, ["admin" => $adminId, "created" => $row["created"]]);
    }

    function unban(int $userId): void
    {
        DatabaseConnection::i()->getConnection()->query("DELETE FROM `group_chat_bans` WHERE `chat` = ? AND `user` = ?", $this->getId(), $userId);
    }

    function logAction(string $type, int $actor, ?int $target = null, array $data = []): void
    {
        $db = DatabaseConnection::i()->getConnection();
        $db->query("DELETE FROM `group_chat_log` WHERE `created` < ?", time() - self::LOG_TTL);
        $db->query("INSERT INTO `group_chat_log` ?", [
            "chat"    => $this->getId(),
            "actor"   => $actor,
            "target"  => $target,
            "type"    => $type,
            "data"    => json_encode($data, JSON_UNESCAPED_UNICODE),
            "created" => time(),
        ]);
    }

    function getActionLog(int $limit = 500): array
    {
        $rows  = DatabaseConnection::i()->getConnection()->query("SELECT * FROM `group_chat_log` WHERE `chat` = ? AND `created` >= ? ORDER BY `id` DESC LIMIT ?", $this->getId(), time() - self::LOG_TTL, $limit);
        $users = new Users();

        $log = [];
        foreach ($rows as $row) {
            $data  = json_decode((string) $row->data, true);
            $log[] = (object) [
                "type"    => $row->type,
                "actor"   => $users->get((int) $row->actor),
                "target"  => is_null($row->target) ? null : $users->get((int) $row->target),
                "data"    => is_array($data) ? $data : [],
                "created" => new DateTime((int) $row->created),
            ];
        }

        return $log;
    }

    function getInviteLink(bool $reset = false): string
    {
        $fresh = DatabaseConnection::i()->getContext()->table("group_chats")->get($this->getId());
        $hash  = $fresh->invite_hash ?? null;
        if (!$hash || $reset) {
            // только буквы: роутер превращает похожие на число параметры в int
            $hash = "";
            for ($i = 0; $i < 16; $i++) {
                $hash .= chr(97 + random_int(0, 25));
            }
            $fresh->update(["invite_hash" => $hash]);
        }

        return ovk_scheme(true) . $_SERVER["HTTP_HOST"] . "/im/join/" . $hash;
    }

    function getPeerId(): int
    {
        return self::PEER_OFFSET + $this->getId();
    }

    /**
     * Get simplified chat info.
     */
    function simplify(): array
    {
        return [
            "id"        => $this->getId(),
            "peer_id"   => $this->getPeerId(),
            "name"      => $this->getName(),
            "avatar"    => $this->getAvatarUrl('miniscule'),
            "members_count" => $this->getParticipantsCount(),
            "last_message" => $this->getLastMessage()?->simplify(),
        ];
    }


    function getAdmins(): array
    {
        $db = \Chandler\Database\DatabaseConnection::i()->getContext();
        $fresh = $db->table("group_chats")->get($this->getId());
        $admins = $fresh->admins ?? "";
        if(empty($admins)) return [];
        return array_filter(explode(",", $admins));
    }

    function isAdmin(int $userId): bool
    {
        return in_array((string)$userId, array_map('strval', $this->getAdmins()));
    }

    function canManageParticipants(int $userId): bool
    {
        return $this->isCreator($userId) || $this->isAdmin($userId);
    }

    private function getAdminRows(): array
    {
        $rows = DatabaseConnection::i()->getConnection()->query("SELECT `user`, `promoted_by`, `rights`, `position` FROM `group_chat_admin_rights` WHERE `chat` = ?", $this->getId());

        $admins = [];
        foreach ($rows as $row) {
            $saved = json_decode((string) $row->rights, true);
            $admins[(int) $row->user] = [
                "promoted_by" => (int) $row->promoted_by,
                "position"    => (int) $row->position,
                "rights"      => array_merge(self::ADMIN_RIGHTS, array_map("boolval", array_intersect_key(is_array($saved) ? $saved : [], self::ADMIN_RIGHTS))),
            ];
        }

        return $admins;
    }

    function getAdminRights(int $userId): array
    {
        if ($this->isCreator($userId)) {
            return array_fill_keys(array_keys(self::ADMIN_RIGHTS), true);
        }

        if (!$this->isAdmin($userId)) {
            return array_fill_keys(array_keys(self::ADMIN_RIGHTS), false);
        }

        return $this->getAdminRows()[$userId]["rights"] ?? self::ADMIN_RIGHTS;
    }

    function hasAdminRight(string $right, int $userId): bool
    {
        return $this->getAdminRights($userId)[$right] ?? false;
    }

    function getPromoter(int $userId): int
    {
        return $this->getAdminRows()[$userId]["promoted_by"] ?? (int) $this->getRecord()->creator_id;
    }

    function setAdminRights(int $userId, array $rights, int $promotedBy): void
    {
        $rights = json_encode(array_merge(self::ADMIN_RIGHTS, array_map("boolval", array_intersect_key($rights, self::ADMIN_RIGHTS))));
        $row    = ["chat" => $this->getId(), "user" => $userId, "promoted_by" => $promotedBy, "rights" => $rights];
        DatabaseConnection::i()->getConnection()->query("INSERT INTO `group_chat_admin_rights` ? ON DUPLICATE KEY UPDATE ?", $row, ["rights" => $rights]);
    }

    function getAdminRanking(): array
    {
        $rows    = $this->getAdminRows();
        $creator = (int) $this->getRecord()->creator_id;
        $admins  = array_values(array_filter(array_map("intval", $this->getAdmins()), fn($id) => $id !== $creator));
        $order   = array_flip($admins);

        $positions = array_map(fn($id) => $rows[$id]["position"] ?? 0, $admins);
        if (!in_array(0, $positions, true) && sizeof(array_unique($positions)) === sizeof($positions)) {
            usort($admins, fn($a, $b) => $rows[$a]["position"] <=> $rows[$b]["position"]);
            return $admins;
        }

        $weight = fn($id) => $this->getAdminWeight($id, $rows);
        usort($admins, fn($a, $b) => [$weight($b), $rows[$a]["position"] ?? 0, $order[$a]] <=> [$weight($a), $rows[$b]["position"] ?? 0, $order[$b]]);
        $this->saveAdminRanking($admins, $rows);

        return $admins;
    }

    private function getAdminWeight(int $userId, array $rows): int
    {
        return sizeof(array_filter($rows[$userId]["rights"] ?? self::ADMIN_RIGHTS));
    }

    private function saveAdminRanking(array $ranking, array $rows): void
    {
        $db      = DatabaseConnection::i()->getConnection();
        $creator = (int) $this->getRecord()->creator_id;
        foreach (array_values($ranking) as $index => $id) {
            $row = [
                "chat"        => $this->getId(),
                "user"        => $id,
                "promoted_by" => $rows[$id]["promoted_by"] ?? $creator,
                "rights"      => json_encode($rows[$id]["rights"] ?? self::ADMIN_RIGHTS),
                "position"    => $index + 1,
            ];
            $db->query("INSERT INTO `group_chat_admin_rights` ? ON DUPLICATE KEY UPDATE ?", $row, ["position" => $index + 1]);
        }
    }

    function placeAdmin(int $userId): void
    {
        $rows    = $this->getAdminRows();
        $ranking = array_values(array_filter($this->getAdminRanking(), fn($id) => $id !== $userId));
        $weight  = $this->getAdminWeight($userId, $rows);

        $index = sizeof($ranking);
        foreach ($ranking as $i => $id) {
            if ($this->getAdminWeight($id, $rows) < $weight) {
                $index = $i;
                break;
            }
        }

        array_splice($ranking, $index, 0, [$userId]);
        $this->saveAdminRanking($ranking, $this->getAdminRows());
    }

    function outranks(int $actorId, int $targetId): bool
    {
        if ($actorId === $targetId || $this->isCreator($targetId)) {
            return false;
        }

        if ($this->isCreator($actorId)) {
            return true;
        }

        if (!$this->isAdmin($actorId)) {
            return false;
        }

        if (!$this->isAdmin($targetId)) {
            return true;
        }

        $ranking = $this->getAdminRanking();
        return array_search($actorId, $ranking, true) < array_search($targetId, $ranking, true);
    }

    function reorderAdmins(int $actorId, array $order): bool
    {
        $ranking = $this->getAdminRanking();
        $order   = array_values(array_map("intval", $order));
        if (sizeof($order) !== sizeof($ranking) || array_diff($ranking, $order) || array_diff($order, $ranking)) {
            return false;
        }

        if (!$this->isCreator($actorId)) {
            if (($ranking[0] ?? null) !== $actorId || $order[0] !== $actorId) {
                return false;
            }

            $rows = $this->getAdminRows();
            foreach ($order as $i => $id) {
                if ($this->getAdminWeight($id, $rows) !== $this->getAdminWeight($ranking[$i], $rows)) {
                    return false;
                }
            }
        }

        $this->saveAdminRanking($order, $this->getAdminRows());
        return true;
    }

    function canEditAdmin(int $editorId, int $adminId): bool
    {
        if ($editorId === $adminId || !$this->isAdmin($adminId) || $this->isCreator($adminId)) {
            return false;
        }

        if ($this->isCreator($editorId)) {
            return true;
        }

        $promoter = $this->getPromoter($adminId);

        return $this->hasAdminRight("add_admins", $editorId)
            && $this->outranks($editorId, $adminId)
            && ($promoter === $editorId || $promoter !== (int) $this->getRecord()->creator_id);
    }

    function getRosterSignature(): string
    {
        $fresh = DatabaseConnection::i()->getContext()->table("group_chats")->get($this->getId());

        return md5(implode("|", [(string) $fresh->participants, (string) $fresh->admins, json_encode($this->getAdminRows()), json_encode($this->getTags())]));
    }

    function getMembersInfo(bool $withRights = false): array
    {
        $users     = new Users();
        $tags      = $this->getTags();
        $adminRows = $this->getAdminRows();
        $creator   = (int) $this->getRecord()->creator_id;
        $members   = [];
        foreach ($this->getParticipantIds() as $id) {
            $user = $users->get($id);
            if (!$user) {
                continue;
            }

            $role   = $id === $creator ? "owner" : ($this->isAdmin($id) ? "admin" : null);
            $member = [
                "id"     => $id,
                "name"   => $user->getCanonicalName(),
                "avatar" => $user->getAvatarUrl("miniscule"),
                "link"   => $user->getURL(),
                "role"   => $role,
                "tag"    => $tags[$id] ?? null,
            ];

            if ($role === "admin") {
                $promoter = $adminRows[$id]["promoted_by"] ?? $creator;
                $member["promoted_by"] = $users->get($promoter)?->getCanonicalName();
                if ($withRights) {
                    $member["promoter"] = $promoter;
                    $member["rights"]   = $adminRows[$id]["rights"] ?? self::ADMIN_RIGHTS;
                }
            }

            $members[] = $member;
        }

        $rank = array_flip($this->getAdminRanking());
        usort($members, fn($a, $b) => [$a["role"] === "owner" ? 0 : ($a["role"] ? 1 : 2), $rank[$a["id"]] ?? 0] <=> [$b["role"] === "owner" ? 0 : ($b["role"] ? 1 : 2), $rank[$b["id"]] ?? 0]);

        return $members;
    }
}
