<?php

declare(strict_types=1);

namespace openvk\Web\Models\Repositories;

use openvk\Web\Models\Entities\GroupChat;
use openvk\Web\Models\Entities\User;
use Chandler\Database\DatabaseConnection;

class GroupChats
{
    private $context;
    private $chats;

    public function __construct()
    {
        $this->context = DatabaseConnection::i()->getContext();
        $this->chats   = $this->context->table("group_chats");
    }

    public function get(int $id): ?GroupChat
    {
        $chat = $this->chats->get($id);
        if (!$chat) {
            return null;
        }

        return new GroupChat($chat);
    }

    /**
     * Get all group chats for a user.
     */
public function getUserChats(int $userId, int $page = 1, ?int $perPage = null): \Traversable
{
    $limit  = $perPage ?? OPENVK_DEFAULT_PER_PAGE;
    $offset = ($page - 1) * $limit;
    $query = $this->chats
        ->where("participants = ? OR participants LIKE ? OR participants LIKE ? OR participants LIKE ?",
            (string)$userId,
            "{$userId},%",
            "%,{$userId},%",
            "%,{$userId}"
        )
        ->order("created DESC")
        ->limit($limit, $offset);
    foreach ($query as $chat) {
        yield new GroupChat($chat);
    }
}

    /**
     * Get user's group chats count.
     */
public function getUserChatsCount(int $userId): int
{
    return $this->chats
        ->where("participants = ? OR participants LIKE ? OR participants LIKE ? OR participants LIKE ?",
            (string)$userId,
            "{$userId},%",
            "%,{$userId},%",
            "%,{$userId}"
        )
        ->count("*");
}
    /**
     * Create a new group chat.
     */
    public function createChat(array $data): GroupChat
    {
        $record = $this->chats->insert([
            "name"               => $data["name"] ?? "Новый чат",
            "description"        => $data["description"] ?? null,
            "creator_id"         => $data["creator_id"],
            "participants"       => $data["participants"] ?? "",
            "participants_count" => $data["participants_count"] ?? 1,
            "avatar"             => $data["avatar"] ?? null,
            "created"            => time(),
        ]);

        return $this->get((int) $record->getPrimary());
    }

    /**
     * Add participant to chat.
     */
    public function addParticipant(int $chatId, int $userId): bool
    {
        $chat = $this->get($chatId);
        if (!$chat) return false;

        $participants = $chat->getRecord()->participants;
        $participantsArray = $participants ? explode(",", $participants) : [];

        if (in_array($userId, $participantsArray)) {
            return false; // Already in chat
        }

        $participantsArray[] = $userId;
        $newParticipants = implode(",", $participantsArray);

        $this->chats->get($chatId)->update([
            "participants"       => $newParticipants,
            "participants_count" => count($participantsArray),
        ]);

        return true;
    }

    /**
     * Remove participant from chat.
     */
    public function removeParticipant(int $chatId, int $userId): bool
    {
        $chat = $this->get($chatId);
        if (!$chat) return false;

        $participants = $chat->getRecord()->participants;
        $participantsArray = $participants ? explode(",", $participants) : [];

        $key = array_search($userId, $participantsArray);
        if ($key === false) {
            return false; // Not in chat
        }

        unset($participantsArray[$key]);
        $newParticipants = implode(",", $participantsArray);

        $this->chats->get($chatId)->update([
            "participants"       => $newParticipants,
            "participants_count" => count($participantsArray),
        ]);

        return true;
    }

    /**
     * Check if user is participant of chat.
     */
    public function isParticipant(int $chatId, int $userId): bool
    {
        $chat = $this->get($chatId);
        if (!$chat) return false;

        $participants = $chat->getRecord()->participants;
        $participantsArray = $participants ? explode(",", $participants) : [];

	return in_array((string)$userId, array_map('strval', $participantsArray));
    }


    public function makeAdmin(int $chatId, int $userId): bool
    {
        $chat = $this->get($chatId);
        if(!$chat) return false;
        $admins = $chat->getAdmins();
        if(in_array((string)$userId, array_map('strval', $admins))) return false;
        $admins[] = $userId;
        $this->chats->get($chatId)->update(["admins" => implode(",", $admins)]);
        return true;
    }

    public function removeAdmin(int $chatId, int $userId): bool
    {
        $chat = $this->get($chatId);
        if(!$chat) return false;
        $admins = array_filter($chat->getAdmins(), fn($a) => (int)$a !== $userId);
        $this->chats->get($chatId)->update(["admins" => implode(",", $admins)]);
        return true;
    }

    public function leaveChat(int $chatId, int $userId): bool
    {
        return $this->removeParticipant($chatId, $userId);
    }
}
