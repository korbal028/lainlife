<?php
declare(strict_types=1);
namespace openvk\Web\Events;
use openvk\Web\Models\Entities\Message;

class ForwardMessageEvent implements ILPEmitable
{
    protected $payload;

    public function __construct(Message $message)
    {
        $this->payload = $message->simplify();
    }

    public function getLongPoolSummary(): object
    {
        return (object) [
            "type"    => "newMessage",
            "message" => $this->payload,
        ];
    }

    public function getVKAPISummary(int $userId): array
    {
        return [];
    }
}
