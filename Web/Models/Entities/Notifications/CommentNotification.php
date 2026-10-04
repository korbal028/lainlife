<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities\Notifications;

use openvk\Web\Models\Entities\{User, Post, Comment};

final class CommentNotification extends Notification
{
    protected $actionCode = 2;

    // $commenter без жёсткого типа: обычно User, но для коммента от имени группы
    // сюда приходит Club (чтобы в уведомлении автором показывалась группа).
    public function __construct(User $recipient, Comment $comment, $postable, $commenter)
    {
        parent::__construct($recipient, $postable, $commenter, time(), ovk_proc_strtr(strip_tags($comment->getText()), 400));
    }
}
