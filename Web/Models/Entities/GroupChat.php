<?php declare(strict_types=1);
namespace openvk\Web\Models\Entities;
use Chandler\Database\DatabaseConnection;
use openvk\Web\Models\Repositories\{Users, Messages};
use openvk\Web\Models\RowModel;
use openvk\Web\Util\DateTime;

/**
 * GroupChat entity.
 */
class GroupChat extends RowModel
{
    // peer_id беседы = PEER_OFFSET + id, как в VK/OpenVK
    public const PEER_OFFSET = 2000000000;

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
        try {
            return $this->getRecord()->description;
        } catch (\Throwable $e) {
            return null;
        }
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
            ->where(["recipient_type" => self::class, "recipient_id" => $this->getId(), "deleted" => 0])
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
}
