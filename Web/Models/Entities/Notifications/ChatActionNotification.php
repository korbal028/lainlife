<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities\Notifications;

use openvk\Web\Models\Entities\{User, Message};

final class ChatActionNotification extends Notification
{
    protected $actionCode = 9701;

    public function __construct(User $recipient, Message $message, User $actor)
    {
        parent::__construct($recipient, $message, $actor, time(), "");
    }
}
