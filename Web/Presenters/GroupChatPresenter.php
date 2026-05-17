<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use Chandler\Signaling\SignalManager;
use openvk\Web\Events\NewGroupMessageEvent;
use openvk\Web\Events\DeleteMessageEvent;
use openvk\Web\Events\EditMessageEvent;
use openvk\Web\Events\ForwardMessageEvent;
use openvk\Web\Models\Repositories\{Users, Clubs, GroupMessages, GroupChats, Albums};
use openvk\Web\Models\Entities\{GroupMessage, GroupChat, Photo};

final class GroupChatPresenter extends OpenVKPresenter
{
    private $groupMessages;
    private $groupChats;
    private $signaler;
    protected $presenterName = "groupchat";

    public function __construct(GroupMessages $groupMessages, GroupChats $groupChats)
    {
        $this->groupMessages = $groupMessages;
        $this->groupChats = $groupChats;
        $this->signaler = SignalManager::i();

        parent::__construct();
    }

    private function getFriendsSortedByMessages(): array
    {
        $friends = iterator_to_array($this->user->identity->getFriends(1, 100));
        $db = \Chandler\Database\DatabaseConnection::i()->getContext();
        $myId = $this->user->identity->getId();

        $lastMsgTimes = [];
        foreach ($friends as $friend) {
            $fid = $friend->getId();
            $last = $db->table("messages")
                ->where("(sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)", $myId, $fid, $fid, $myId)
                ->order("created DESC")
                ->fetch();
            $lastMsgTimes[$fid] = $last ? $last->created : 0;
        }

        usort($friends, function($a, $b) use ($lastMsgTimes) {
            return $lastMsgTimes[$b->getId()] - $lastMsgTimes[$a->getId()];
        });

        return $friends;
    }

    /**
     * List all group chats for current user.
     */
    public function renderIndex(): void
    {
        $this->assertUserLoggedIn();

        $page = (int) ($_GET["p"] ?? 1);
        $chats = iterator_to_array($this->groupChats->getUserChats($this->user->identity->getId(), $page));

        $this->template->chats = $chats;
        $this->template->paginatorConf = (object) [
            "count"   => 0,
            "page"    => (int) ($_GET["p"] ?? 1),
            "amount"  => sizeof($this->template->chats),
            "perPage" => OPENVK_DEFAULT_PER_PAGE,
            "tidy"    => false,
            "atTop"   => false,
        ];
        try {
            $this->template->paginatorConf->count = $this->groupChats->getUserChatsCount($this->user->identity->getId());
        } catch (\Throwable $e) {}
    }

    /**
     * View group chat and its messages.
     */
    public function renderApp(int $chatId): void
    {
        $this->assertUserLoggedIn();

        $chat = $this->groupChats->get($chatId);
        if (!$chat) {
            $this->notFound();
        }

        if (!$this->groupChats->isParticipant($chatId, $this->user->identity->getId())) {
            $this->flash("err", tr("warning"), "Вы не являетесь участником этого чата");
            $this->redirect("/im/convs");
        }

        $this->template->disable_ajax = 1;
        $this->template->chatId = $chatId;
        $this->template->chat = $chat;
    }

    /**
     * Longpool for group chat events.
     */
    public function renderEvents(int $chatId): void
    {
        $this->assertUserLoggedIn();

        if (!$this->groupChats->isParticipant($chatId, $this->user->identity->getId())) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        header("Content-Type: application/json");
        $this->signaler->listen(function ($event, $id) {
            exit(json_encode([[
                "UUID"  => $id,
                "event" => $event->getLongPoolSummary(),
            ]]));
        }, $this->user->id);
    }

    /**
     * API: Get messages from group chat.
     */
    public function renderApiGetMessages(int $chatId, int $lastMsg): void
    {
        $this->assertUserLoggedIn();

        $chat = $this->groupChats->get($chatId);
        if (!$chat) {
            $this->notFound();
        }

        if (!$this->groupChats->isParticipant($chatId, $this->user->identity->getId())) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $messages = [];
        foreach ($this->groupMessages->getMessagesFromChat($chatId, $lastMsg === 0 ? 0 : $lastMsg) as $message) {
            $simple = $message->simplify();
            $this->enrichAttachmentsWithHTML($message, $simple);
            $messages[] = $simple;
        }

        header("Content-Type: application/json");
        exit(json_encode($messages));
    }

    /**
     * API: Send message to group chat.
     */
    public function renderApiWriteMessage(int $chatId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        if (empty($this->postParam("content")) && empty($this->postParam("attachments"))) {
            header("HTTP/1.1 400 Bad Request");
            exit("<b>Argument error</b>: param 'content' expected to be string, undefined given.");
        }

        $chat = $this->groupChats->get($chatId);
        if (!$chat) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        if (!$this->groupChats->isParticipant($chatId, $this->user->identity->getId())) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $attachments = [];
        if (!empty($this->postParam("attachments"))) {
            $attachments_array = array_slice(explode(",", $this->postParam("attachments")), 0, OPENVK_ROOT_CONF["openvk"]["preferences"]["wall"]["postSizes"]["maxAttachments"]);
            if (sizeof($attachments_array) > 0) {
                $attachments = parseAttachments($attachments_array, ['photo', 'video', 'audio', 'note', 'doc']);
            }
        }

        $db = \Chandler\Database\DatabaseConnection::i()->getContext();
        $row = $db->table("group_messages")->insert([
            "chat_id"        => $chatId,
            "sender_id"      => $this->user->identity->getId(),
            "sender_type"    => get_class($this->user->identity),
            "content"        => $this->postParam("content"),
            "created"        => time(),
            "unread"         => 0,
            "deleted"        => 0,
            "forwarded_from" => !empty($this->postParam("reply_to")) ? (int)$this->postParam("reply_to") : null,
        ]);
        $msgId = (int) $row->id;
        $msg = (new GroupMessages())->get($msgId);

        foreach ($attachments as $attachment) {
            if (!$attachment || $attachment->isDeleted() || !$attachment->canBeViewedBy($this->user->identity)) {
                continue;
            }
            $msg->attach($attachment);
        }

        // Notify other participants
        $participants = explode(",", $chat->getRecord()->participants);
        foreach ($participants as $participantId) {
            if ((int)$participantId !== $this->user->identity->getId()) {
                $this->signaler->triggerEvent(new NewGroupMessageEvent($msg), (int)$participantId);
            }
        }

        header("HTTP/1.1 202 Accepted");
        header("Content-Type: application/json");
        $simple = $msg->simplify();
        $this->enrichAttachmentsWithHTML($msg, $simple);
        exit(json_encode($simple));
    }

    /**
     * API: Edit message in group chat.
     */
    public function renderApiEditMessage(int $chatId, int $msgId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        if (empty($this->postParam("content")) && empty($this->postParam("attachments"))) {
            header("HTTP/1.1 400 Bad Request");
            exit();
        }

        $msg = (new GroupMessages())->get($msgId);
        if (!$msg) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        if ($msg->getSender()->getId() !== $this->user->id) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $msg->setContent($this->postParam("content"));
        $msg->setEdited(time());
        $msg->save();

        // Notify participants
        $chat = $this->groupChats->get($chatId);
        $participants = explode(",", $chat->getRecord()->participants);
        foreach ($participants as $participantId) {
            $this->signaler->triggerEvent(new EditMessageEvent($msg), (int)$participantId);
        }

        header("HTTP/1.1 200 OK");
        header("Content-Type: application/json");
        $simple = $msg->simplify();
        exit(json_encode($simple));
    }

    /**
     * API: Forward message to group chat.
     */
    public function renderApiForwardMessage(int $chatId, int $msgId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $origMsg = (new GroupMessages())->get($msgId);
        if (!$origMsg) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        $chat = $this->groupChats->get($chatId);
        if (!$chat) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        if (!$this->groupChats->isParticipant($chatId, $this->user->identity->getId())) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $db = \Chandler\Database\DatabaseConnection::i()->getContext();
        $row = $db->table("group_messages")->insert([
            "chat_id"        => $chatId,
            "sender_id"      => $this->user->identity->getId(),
            "sender_type"    => get_class($this->user->identity),
            "content"        => $this->postParam("content") ?? "",
            "created"        => time(),
            "unread"         => 0,
            "deleted"        => 0,
            "forwarded_from" => $msgId,
        ]);
        $newMsgId = (int) $row->id;
        $msg = (new GroupMessages())->get($newMsgId);

        // Notify participants
        $participants = explode(",", $chat->getRecord()->participants);
        foreach ($participants as $participantId) {
            $this->signaler->triggerEvent(new ForwardMessageEvent($msg), (int)$participantId);
        }

        header("HTTP/1.1 202 Accepted");
        header("Content-Type: application/json");
        $simple = $msg->simplify();
        $this->enrichAttachmentsWithHTML($msg, $simple);
        exit(json_encode($simple));
    }

    /**
     * API: Delete message from group chat.
     */
    public function renderApiDeleteMessage(int $chatId, int $msgId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $msg = (new GroupMessages())->get($msgId);
        if (!$msg) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        if ($msg->getSender()->getId() !== $this->user->id) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $msg->setDeleted(1);
        $msg->save();

        // Notify participants
        $chat = $this->groupChats->get($chatId);
        $participants = explode(",", $chat->getRecord()->participants);
        foreach ($participants as $participantId) {
            $this->signaler->triggerEvent(new DeleteMessageEvent($msgId), (int)$participantId);
        }

        header("HTTP/1.1 200 OK");
        header("Content-Type: application/json");
        exit(json_encode(["success" => true]));
    }

    /**
     * API: Create new group chat.
     */
    public function renderApiCreateChat(): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        if (empty($this->postParam("name"))) {
            header("HTTP/1.1 400 Bad Request");
            exit("<b>Argument error</b>: param 'name' expected.");
        }

        $participants = $this->postParam("participants") ?? "";
        $participantsArray = $participants ? explode(",", $participants) : [];
        $participantsArray[] = $this->user->identity->getId();
        $participantsArray = array_unique($participantsArray);

        $chat = $this->groupChats->createChat([
            "name"               => $this->postParam("name"),
            "description"        => $this->postParam("description"),
            "creator_id"         => $this->user->identity->getId(),
            "participants"       => implode(",", $participantsArray),
            "participants_count" => count($participantsArray),
        ]);

        header("HTTP/1.1 201 Created");
        header("Content-Type: application/json");
        exit(json_encode($chat->simplify()));
    }

    /**
     * API: Add participant to group chat.
     */
    public function renderApiAddParticipant(int $chatId, int $userId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->groupChats->get($chatId);
        if (!$chat) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        if ($chat->getCreator()->getId() !== $this->user->id) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $success = $this->groupChats->addParticipant($chatId, $userId);

        if ($success) {
            header("HTTP/1.1 200 OK");
            exit(json_encode(["success" => true]));
        } else {
            header("HTTP/1.1 400 Bad Request");
            exit(json_encode(["success" => false, "error" => "User already in chat"]));
        }
    }

    /**
     * API: Remove participant from group chat.
     */
    public function renderApiRemoveParticipant(int $chatId, int $userId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->groupChats->get($chatId);
        if (!$chat) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        if ($chat->getCreator()->getId() !== $this->user->id) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $success = $this->groupChats->removeParticipant($chatId, $userId);

        if ($success) {
            header("HTTP/1.1 200 OK");
            exit(json_encode(["success" => true]));
        } else {
            header("HTTP/1.1 400 Bad Request");
            exit(json_encode(["success" => false, "error" => "User not in chat"]));
        }
    }

    /**
     * Page: Create new group chat (form).
     */
    public function renderCreate(): void
    {
        $this->assertUserLoggedIn();

        $this->template->friends = $this->getFriendsSortedByMessages();

        if($_SERVER["REQUEST_METHOD"] !== "POST") {
            return;
        }

        $this->willExecuteWriteAction();

        $name = $this->postParam("name");
        if(empty($name)) {
            $this->flashFail("err", tr("error"), "Введите название беседы.");
        }

        $members = $_POST["members"] ?? [];
        $members[] = $this->user->identity->getId();
        $members = array_unique(array_map('intval', $members));

        $chat = $this->groupChats->createChat([
            "name"               => $name,
            "creator_id"         => $this->user->identity->getId(),
            "participants"       => implode(",", $members),
            "participants_count" => count($members),
        ]);

        $this->redirect("/im/convs" . $chat->getId());
    }

    /**
     * Page: Edit group chat settings.
     */
    public function renderEdit(int $chatId): void
    {
        $this->assertUserLoggedIn();

        $chat = $this->groupChats->get($chatId);
        if (!$chat) {
            $this->notFound();
        }

        if ($chat->getCreator()->getId() !== $this->user->id) {
            $this->flash("err", tr("warning"), "Только создатель может редактировать настройки чата");
            $this->redirect("/im/convs" . $chatId);
        }

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $this->willExecuteWriteAction();

            $name = $this->postParam("name");
            if (empty($name)) {
                $this->flash("err", tr("warning"), "Название чата не может быть пустым");
                $this->redirect("/im/convs" . $chatId . "/edit");
            }

            $chat->getRecord()->update([
                "name"        => $name,
                "description" => $this->postParam("description"),
            ]);

            $this->flash("ok", tr("ok"), tr("settings_saved"));
            $this->redirect("/im/convs" . $chatId . "/edit");
        }

        $participantsIds = explode(",", $chat->getRecord()->participants);
        $participants = [];
        foreach ($participantsIds as $pid) {
            $user = (new Users())->get((int)$pid);
            if ($user) {
                $participants[] = $user;
            }
        }

        $friends = $this->getFriendsSortedByMessages();
        $friendsIds = array_map(fn($f) => $f->getId(), $friends);

        $this->template->chat           = $chat;
        $this->template->participants   = $participants;
        $this->template->friends        = $friends;
        $this->template->friendsIds     = $friendsIds;
        $this->template->participantsIds = $participantsIds;
    }

    /**
     * API: Set chat avatar (upload photo).
     */
    public function renderApiSetAvatar(int $chatId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->groupChats->get($chatId);
        if (!$chat) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        if ($chat->getCreator()->getId() !== $this->user->id) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        if (empty($_FILES["avatar"])) {
            header("HTTP/1.1 400 Bad Request");
            exit(json_encode(["error" => "No file uploaded"]));
        }

        $file = $_FILES["avatar"];
        if ($file["error"] !== UPLOAD_ERR_OK) {
            header("HTTP/1.1 400 Bad Request");
            exit(json_encode(["error" => "Upload error"]));
        }

        $photo = Photo::fastMake($this->user->identity->getId(), $chat->getName(), $file);
        if (!$photo) {
            header("HTTP/1.1 500 Internal Server Error");
            exit(json_encode(["error" => "Failed to create photo"]));
        }

        $chat->getRecord()->update([
            "avatar" => "/photo" . $photo->getPrettyId(),
        ]);

        header("HTTP/1.1 200 OK");
        header("Content-Type: application/json");
        exit(json_encode([
            "success"    => true,
            "avatar_url" => $photo->getURLBySizeId('miniscule'),
        ]));
    }

    /**
     * API: Delete chat avatar.
     */
    public function renderApiDeleteAvatar(int $chatId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->groupChats->get($chatId);
        if (!$chat) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        if ($chat->getCreator()->getId() !== $this->user->id) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $chat->getRecord()->update([
            "avatar" => null,
        ]);

        header("HTTP/1.1 200 OK");
        header("Content-Type: application/json");
        exit(json_encode(["success" => true]));
    }

    /**
     * API: Get friends list for adding to chat.
     */
    public function renderApiGetFriends(int $chatId): void
    {
        $this->assertUserLoggedIn();

        $chat = $this->groupChats->get($chatId);
        if (!$chat) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        $friends = $this->getFriendsSortedByMessages();
        $participantsIds = explode(",", $chat->getRecord()->participants);

        $result = [];
        foreach ($friends as $friend) {
            $result[] = [
                "id"            => $friend->getId(),
                "name"          => $friend->getCanonicalName(),
                "avatar"        => $friend->getAvatarUrl('miniscule'),
                "already_in_chat" => in_array((string)$friend->getId(), $participantsIds),
            ];
        }

        header("Content-Type: application/json");
        exit(json_encode($result));
    }

    private function enrichAttachmentsWithHTML(GroupMessage $messageObj, array &$simplifiedArray): void
    {
        $children = iterator_to_array($messageObj->getChildren());

        foreach ($simplifiedArray['attachments'] as $index => &$attachmentData) {
            if (!isset($children[$index])) {
                continue;
            }

            $originalObj = $children[$index];
            $html = "";

            if ($attachmentData['type'] === 'audio') {
                $html = $this->getTemplatingEngine()->renderToString(
                    dirname(__FILE__) . '/templates/Audio/player.latte',
                    [
                        'audio'       => $originalObj,
                        'thisUser'    => $this->user->identity,
                        'hideButtons' => false,
                        'club'        => null,
                    ]
                );
            }

            if ($html !== "") {
                $attachmentData['html'] = $html;
            }
        }
    }
public function renderMembers(int $chatId): void
{
    $this->assertUserLoggedIn();
    $chat = $this->groupChats->get($chatId);
    if(!$chat || !$this->groupChats->isParticipant($chatId, $this->user->identity->getId())) {
        $this->notFound();
    }
    $participantsIds = explode(",", $chat->getRecord()->participants);
    $participants = [];
    foreach($participantsIds as $pid) {
        $user = (new Users())->get((int)$pid);
        if($user) $participants[] = $user;
    }
    $this->template->chat = $chat;
    $this->template->participants = $participants;
}

public function renderApiLeave(int $chatId): void
{
    $this->assertUserLoggedIn();
    $this->willExecuteWriteAction();
    $chat = $this->groupChats->get($chatId);
    if(!$chat) { header("HTTP/1.1 404 Not Found"); exit(); }
    if($chat->isCreator($this->user->id)) {
        header("HTTP/1.1 403 Forbidden");
        exit(json_encode(["error" => "Создатель не может покинуть беседу"]));
    }
    $this->groupChats->leaveChat($chatId, $this->user->id);
    header("HTTP/1.1 200 OK");
    header("Content-Type: application/json");
    exit(json_encode(["success" => true]));
}

public function renderApiMakeAdmin(int $chatId, int $userId): void
{
    $this->assertUserLoggedIn();
    $this->willExecuteWriteAction();
    $chat = $this->groupChats->get($chatId);
    if(!$chat || !$chat->isCreator($this->user->id)) {
        header("HTTP/1.1 403 Forbidden"); exit();
    }
    $this->groupChats->makeAdmin($chatId, $userId);
    header("HTTP/1.1 200 OK");
    header("Content-Type: application/json");
    exit(json_encode(["success" => true]));
}

public function renderApiRemoveAdmin(int $chatId, int $userId): void
{
    $this->assertUserLoggedIn();
    $this->willExecuteWriteAction();
    $chat = $this->groupChats->get($chatId);
    if(!$chat || !$chat->isCreator($this->user->id)) {
        header("HTTP/1.1 403 Forbidden"); exit();
    }
    $this->groupChats->removeAdmin($chatId, $userId);
    header("HTTP/1.1 200 OK");
    header("Content-Type: application/json");
    exit(json_encode(["success" => true]));
}
}

