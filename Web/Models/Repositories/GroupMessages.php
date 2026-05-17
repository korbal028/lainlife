<?php

declare(strict_types=1);

namespace openvk\Web\Models\Repositories;

use openvk\Web\Models\Entities\GroupChat;
use openvk\Web\Models\Entities\GroupMessage;
use Chandler\Database\DatabaseConnection;

class GroupMessages
{
    private $context;
    private $messages;

    public function __construct()
    {
        $this->context  = DatabaseConnection::i()->getContext();
        $this->messages = $this->context->table("group_messages");
    }

    public function get(int $id): ?GroupMessage
    {
        $msg = $this->messages->get($id);
        if (!$msg) {
            return null;
        }

        return new GroupMessage($msg);
    }

    /**
     * Get messages from a specific group chat.
     *
     * @param int $chatId Group chat ID
     * @param int $lastMsg Last message ID for pagination
     * @param int $limit Number of messages to retrieve
     * @return GroupMessage[]
     */
    public function getMessagesFromChat(int $chatId, int $lastMsg = 0, int $limit = 20): array
    {
        $messages = [];
        $query = $this->messages
            ->where("chat_id", $chatId)
            ->where("deleted", 0)
            ->order("created DESC");

        if ($lastMsg > 0) {
            $query = $query->where("id < ?", $lastMsg);
        }

        foreach ($query->limit($limit) as $msg) {
            $messages[] = new GroupMessage($msg);
        }

        return array_reverse($messages);
    }

    /**
     * Get message count for a group chat.
     */
    public function getMessagesCount(int $chatId): int
    {
        return $this->messages
            ->where("chat_id", $chatId)
            ->where("deleted", 0)
            ->count("*");
    }
}
