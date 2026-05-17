<?php
declare(strict_types=1);
namespace openvk\Web\Events;

class DeleteMessageEvent implements ILPEmitable
{
    protected $msgId;

    public function __construct(int $msgId)
    {
        $this->msgId = $msgId;
    }

    public function getLongPoolSummary(): object
    {
        return (object) [
            "type"    => "deleteMessage",
            "msgId"   => $this->msgId,
        ];
    }

    public function getVKAPISummary(int $userId): array
    {
        return [];
    }
}
