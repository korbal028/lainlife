<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use openvk\Web\Models\Repositories\{Users, GroupChats};
use openvk\Web\Models\Entities\{GroupChat, Photo, Correspondence};

// управление беседами; сами сообщения бесед обслуживает MessengerPresenter
final class GroupChatPresenter extends OpenVKPresenter
{
    private $groupChats;
    protected $presenterName = "groupchat";

    public function __construct(GroupChats $groupChats)
    {
        $this->groupChats = $groupChats;

        parent::__construct();
    }

    // беседа по peer_id (2000000000 + id), только для её участников
    private function getChat(int $peer): GroupChat
    {
        $chat = $this->groupChats->getByPeerId($peer);
        if (!$chat || $chat->getRecord()->deleted || !$chat->isParticipant($this->user->id)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        return $chat;
    }

    private function getManagedChat(int $peer): GroupChat
    {
        $chat = $this->getChat($peer);
        if (!$chat->canManageParticipants($this->user->id)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        return $chat;
    }

    private function getCreatedChat(int $peer): GroupChat
    {
        $chat = $this->getChat($peer);
        if (!$chat->isCreator($this->user->id)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        return $chat;
    }

    private function getParticipants(GroupChat $chat): array
    {
        $users = new Users();
        $participants = [];
        foreach ($chat->getParticipantIds() as $id) {
            $user = $users->get($id);
            if ($user) {
                $participants[] = $user;
            }
        }

        return $participants;
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
                ->where("(sender_id = ? AND recipient_id = ? AND recipient_type = ?) OR (sender_id = ? AND recipient_id = ? AND recipient_type = ?)", $myId, $fid, get_class($friend), $fid, $myId, get_class($friend))
                ->order("created DESC")
                ->fetch();
            $lastMsgTimes[$fid] = $last ? $last->created : 0;
        }

        usort($friends, function ($a, $b) use ($lastMsgTimes) {
            return $lastMsgTimes[$b->getId()] - $lastMsgTimes[$a->getId()];
        });

        return $friends;
    }

    private function createChat(string $name, array $members): GroupChat
    {
        $users     = new Users();
        $members[] = $this->user->id;
        $members   = array_values(array_unique(array_filter(array_map("intval", $members), fn($id) => $id > 0 && $users->get($id))));

        return $this->groupChats->createChat([
            "name"               => $name,
            "description"        => $this->postParam("description"),
            "creator_id"         => $this->user->id,
            "participants"       => implode(",", $members),
            "participants_count" => count($members),
        ]);
    }

    public function renderIndex(): void
    {
        $this->assertUserLoggedIn();

        $page  = (int) ($_GET["p"] ?? 1);
        $chats = iterator_to_array($this->groupChats->getUserChats($this->user->id, $page));

        $this->template->chats = $chats;
        $this->template->paginatorConf = (object) [
            "count"   => $this->groupChats->getUserChatsCount($this->user->id),
            "page"    => $page,
            "amount"  => sizeof($chats),
            "perPage" => OPENVK_DEFAULT_PER_PAGE,
            "tidy"    => false,
            "atTop"   => false,
        ];
    }

    public function renderCreate(): void
    {
        $this->assertUserLoggedIn();

        $this->template->friends = $this->getFriendsSortedByMessages();

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            return;
        }

        $this->willExecuteWriteAction();

        $name = trim((string) $this->postParam("name"));
        if ($name === "") {
            $this->flashFail("err", tr("error"), "Введите название беседы.");
        }

        $chat = $this->createChat($name, $_POST["members"] ?? []);
        $this->redirect($chat->getURL());
    }

    public function renderApiCreateChat(): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $name = trim((string) $this->postParam("name"));
        if ($name === "") {
            header("HTTP/1.1 400 Bad Request");
            exit("<b>Argument error</b>: param 'name' expected.");
        }

        $chat = $this->createChat($name, explode(",", (string) $this->postParam("participants")));

        header("HTTP/1.1 201 Created");
        $this->returnJson($chat->simplify());
    }

    public function renderEdit(int $peer): void
    {
        $this->assertUserLoggedIn();

        $chat = $this->getChat($peer);
        if (!$chat->isCreator($this->user->id)) {
            $this->flash("err", tr("warning"), "Только создатель может редактировать настройки чата");
            $this->redirect($chat->getURL());
        }

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $this->willExecuteWriteAction();

            $name = trim((string) $this->postParam("name"));
            if ($name === "") {
                $this->flash("err", tr("warning"), "Название чата не может быть пустым");
                $this->redirect("/im/sel" . $chat->getPeerId() . "/edit");
            }

            $chat->getRecord()->update([
                "name"        => $name,
                "description" => $this->postParam("description"),
            ]);

            $this->flash("ok", tr("ok"), tr("settings_saved"));
            $this->redirect("/im/sel" . $chat->getPeerId() . "/edit");
        }

        $friends = $this->getFriendsSortedByMessages();

        $this->template->chat            = $chat;
        $this->template->participants    = $this->getParticipants($chat);
        $this->template->friends         = $friends;
        $this->template->friendsIds      = array_map(fn($f) => $f->getId(), $friends);
        $this->template->participantsIds = array_map("strval", $chat->getParticipantIds());
    }

    public function renderMembers(int $peer): void
    {
        $this->assertUserLoggedIn();

        $chat = $this->getChat($peer);

        $this->template->chat         = $chat;
        $this->template->participants = $this->getParticipants($chat);
    }

    public function renderApiAddParticipant(int $peer, int $userId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getManagedChat($peer);
        $user = (new Users())->get($userId);
        if (!$user || !$this->groupChats->addParticipant($chat->getId(), $userId)) {
            header("HTTP/1.1 400 Bad Request");
            $this->returnJson(["success" => false, "error" => "User already in chat"]);
        }

        // история до вступления не считается непрочитанной
        (new Correspondence($user, $chat))->markAsRead();

        $this->returnJson(["success" => true]);
    }

    public function renderApiRemoveParticipant(int $peer, int $userId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getManagedChat($peer);

        // админ не может выгнать создателя и других админов
        if ($chat->isCreator($userId) || (!$chat->isCreator($this->user->id) && $chat->isAdmin($userId))) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        if (!$this->groupChats->removeParticipant($chat->getId(), $userId)) {
            header("HTTP/1.1 400 Bad Request");
            $this->returnJson(["success" => false, "error" => "User not in chat"]);
        }

        $this->groupChats->removeAdmin($chat->getId(), $userId);
        $this->returnJson(["success" => true]);
    }

    public function renderApiLeave(int $peer): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getChat($peer);
        if ($chat->isCreator($this->user->id)) {
            header("HTTP/1.1 403 Forbidden");
            $this->returnJson(["error" => "Создатель не может покинуть беседу"]);
        }

        $this->groupChats->leaveChat($chat->getId(), $this->user->id);
        $this->groupChats->removeAdmin($chat->getId(), $this->user->id);
        $this->returnJson(["success" => true]);
    }

    public function renderApiMakeAdmin(int $peer, int $userId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getCreatedChat($peer);
        if (!$chat->isParticipant($userId)) {
            header("HTTP/1.1 400 Bad Request");
            exit();
        }

        $this->groupChats->makeAdmin($chat->getId(), $userId);
        $this->returnJson(["success" => true]);
    }

    public function renderApiRemoveAdmin(int $peer, int $userId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getCreatedChat($peer);
        $this->groupChats->removeAdmin($chat->getId(), $userId);
        $this->returnJson(["success" => true]);
    }

    public function renderApiSetAvatar(int $peer): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getCreatedChat($peer);

        $file = $_FILES["avatar"] ?? null;
        if (!$file || $file["error"] !== UPLOAD_ERR_OK) {
            header("HTTP/1.1 400 Bad Request");
            $this->returnJson(["error" => "Upload error"]);
        }

        $photo = Photo::fastMake($this->user->id, $chat->getName(), $file);
        if (!$photo) {
            header("HTTP/1.1 500 Internal Server Error");
            $this->returnJson(["error" => "Failed to create photo"]);
        }

        $chat->getRecord()->update([
            "avatar" => "/photo" . $photo->getPrettyId(),
        ]);

        $this->returnJson([
            "success"    => true,
            "avatar_url" => $photo->getURLBySizeId("miniscule"),
        ]);
    }

    public function renderApiDeleteAvatar(int $peer): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getCreatedChat($peer);
        $chat->getRecord()->update([
            "avatar" => null,
        ]);

        $this->returnJson(["success" => true]);
    }

    public function renderApiGetFriends(int $peer): void
    {
        $this->assertUserLoggedIn();

        $chat         = $this->getChat($peer);
        $participants = $chat->getParticipantIds();

        $result = [];
        foreach ($this->getFriendsSortedByMessages() as $friend) {
            $result[] = [
                "id"              => $friend->getId(),
                "name"            => $friend->getCanonicalName(),
                "avatar"          => $friend->getAvatarUrl("miniscule"),
                "already_in_chat" => in_array($friend->getId(), $participants, true),
            ];
        }

        $this->returnJson($result);
    }
}
