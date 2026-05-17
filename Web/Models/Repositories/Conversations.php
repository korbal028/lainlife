<?php
declare(strict_types=1);
namespace openvk\Web\Models\Repositories;
use Chandler\Database\DatabaseConnection;
use openvk\Web\Models\Entities\Conversation;

class Conversations
{
    private $context;
    private $conversations;

    public function __construct()
    {
        $this->context       = DatabaseConnection::i()->getContext();
        $this->conversations = $this->context->table("conversations");
    }

    public function get(int $id): ?Conversation
    {
        $row = $this->conversations->where("id", $id)->where("deleted", 0)->fetch();
        if(!$row) return null;
        return new Conversation($row);
    }

    public function getByUser(\openvk\Web\Models\Entities\User $user): array
    {
        $memberRows = $this->context->table("conversation_members")
            ->where("user_id", $user->getId())
            //->whereNull("left_at IS NULL")//
            ->fetchAll();
        $result = [];
        foreach($memberRows as $row) {
            $conv = $this->get($row->conversation_id);
            if($conv) $result[] = $conv;
        }
        return $result;
    }

    public function create(string $name, \openvk\Web\Models\Entities\User $owner): Conversation
    {
    $conv = new Conversation();
    $conv->setName($name);
    $conv->setOwner_id($owner->getId());
    $conv->setCreated(time());
    $conv->setDeleted(0);
    $conv->save();
    
    $conv->addMember($owner);
    return $conv;
    }
}
