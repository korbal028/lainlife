<?php declare(strict_types=1);
namespace openvk\Web\Models\Entities;
use Chandler\Database\DatabaseConnection;
use openvk\Web\Models\Repositories\{Users, GroupMessages};
use openvk\Web\Models\RowModel;
use openvk\Web\Util\DateTime;

/**
 * GroupChat entity.
 */
class GroupChat extends RowModel
{
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
        return "/assets/packages/static/openvk/img/group_default.png";
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
    return "/assets/packages/static/openvk/img/group_default.png";
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
    function getLastMessage(): ?GroupMessage
    {
        if (!$this->getId()) {
            return null;
        }
        try {
            $messages = new GroupMessages();
            $msgs = $messages->getMessagesFromChat($this->getId(), 0, 1);
            return $msgs[0] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get chat URL.
     */
    function getURL(): string
    {
        return "/im/convs" . $this->getId();
    }

    /**
     * Get simplified chat info.
     */
    function simplify(): array
    {
        return [
            "id"        => $this->getId(),
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
