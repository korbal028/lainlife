<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use openvk\Web\Models\Repositories\{Users, GroupChats};
use openvk\Web\Models\Entities\{GroupChat, Photo, Correspondence, User};

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

    private function getChatWithAdminRight(int $peer, string $right): GroupChat
    {
        $chat = $this->getChat($peer);
        if (!$chat->hasAdminRight($right, $this->user->id)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        return $chat;
    }

    private function getChatWithRight(int $peer, string $permission): GroupChat
    {
        $chat = $this->getChat($peer);
        if (!$chat->can($permission, $this->user->id)) {
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
        $users   = new Users();
        $me      = $this->user->identity;
        $members = array_filter(array_map("intval", $members), function ($id) use ($users, $me) {
            $user = $id > 0 ? $users->get($id) : null;

            return $user && $me->getSubscriptionStatus($user) === User::SUBSCRIPTION_MUTUAL;
        });
        $members = array_values(array_unique([$me->getId(), ...$members]));

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
            $this->flashFail("err", tr("error"), tr("messenger_create_chat_no_name"));
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

    public function renderApiAddParticipant(int $peer, int $userId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getChatWithRight($peer, "invite");
        $user = (new Users())->get($userId);
        if ($user && $this->user->identity->getSubscriptionStatus($user) !== User::SUBSCRIPTION_MUTUAL) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        if ($chat->isBanned($userId)) {
            if (!$chat->hasAdminRight("ban", $this->user->id)) {
                header("HTTP/1.1 403 Forbidden");
                $this->returnJson(["success" => false, "error" => "User was removed by an admin"]);
            }

            $chat->unban($userId);
        }

        if (!$user || !$this->groupChats->addParticipant($chat->getId(), $userId)) {
            header("HTTP/1.1 400 Bad Request");
            $this->returnJson(["success" => false, "error" => "User already in chat"]);
        }

        // история до вступления не считается непрочитанной
        (new Correspondence($user, $chat))->markAsJoined();
        $chat->logAction("added", $this->user->id, $userId);
        (new Correspondence($this->user->identity, $chat))->announce(["type" => "invite", "target" => $userId]);

        $this->returnJson(["success" => true]);
    }

    public function renderApiRemoveParticipant(int $peer, int $userId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getChatWithAdminRight($peer, "ban");

        // админ может выгнать только обычных участников и назначенных им админов
        if (!$chat->outranks($this->user->id, $userId)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        if (!$this->groupChats->removeParticipant($chat->getId(), $userId)) {
            header("HTTP/1.1 400 Bad Request");
            $this->returnJson(["success" => false, "error" => "User not in chat"]);
        }

        $this->groupChats->removeAdmin($chat->getId(), $userId);
        $chat->removeException($userId);
        $chat->ban($userId, $this->user->id);
        $chat->logAction("removed", $this->user->id, $userId);
        $this->returnJson(["success" => true, "removed" => $chat->getBans()]);
    }

    public function renderApiUnban(int $peer, int $userId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getChatWithAdminRight($peer, "ban");
        $chat->unban($userId);

        $this->returnJson(["removed" => $chat->getBans()]);
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
        $chat->logAction("left", $this->user->id);
        (new Correspondence($this->user->identity, $chat))->announce(["type" => "leave"]);
        $this->groupChats->removeAdmin($chat->getId(), $this->user->id);
        $chat->removeException($this->user->id);
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

        if ($this->groupChats->makeAdmin($chat->getId(), $userId)) {
            (new Correspondence($this->user->identity, $chat))->announce(["type" => "admin_add", "target" => $userId], $userId);
        }
        $this->returnJson(["success" => true]);
    }

    public function renderApiRemoveAdmin(int $peer, int $userId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getCreatedChat($peer);
        if ($chat->isAdmin($userId)) {
            $this->groupChats->removeAdmin($chat->getId(), $userId);
            (new Correspondence($this->user->identity, $chat))->announce(["type" => "admin_remove", "target" => $userId], $userId);
        }
        $this->returnJson(["success" => true]);
    }

    // назначение, правка и снятие админа; выдать можно только те права, что есть у себя
    public function renderApiAdmin(int $peer): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat   = $this->getChatWithAdminRight($peer, "add_admins");
        $me     = $this->user->id;
        $userId = (int) $this->postParam("user");
        if (!$chat->isParticipant($userId) || $chat->isCreator($userId) || $userId === $me) {
            header("HTTP/1.1 400 Bad Request");
            exit();
        }

        $isAdmin = $chat->isAdmin($userId);
        if ($isAdmin && !$chat->canEditAdmin($me, $userId)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        if ($this->postParam("act") === "dismiss") {
            if ($isAdmin) {
                $this->groupChats->removeAdmin($chat->getId(), $userId);
                (new Correspondence($this->user->identity, $chat))->announce(["type" => "admin_remove", "target" => $userId], $userId);
            }

            $this->returnJson(["members" => $chat->getMembersInfo(true)]);
        }

        $rights = [];
        foreach (array_keys(GroupChat::ADMIN_RIGHTS) as $key) {
            $rights[$key] = $this->postParam("right_$key") === "1" && $chat->hasAdminRight($key, $me);
        }

        if (!$isAdmin) {
            $this->groupChats->makeAdmin($chat->getId(), $userId);
            (new Correspondence($this->user->identity, $chat))->announce(["type" => "admin_add", "target" => $userId], $userId);
        }
        $chat->setAdminRights($userId, $rights, $isAdmin ? $chat->getPromoter($userId) : $me);

        if (!is_null($this->postParam("tag")) && $chat->hasAdminRight("tags", $me)) {
            $color = (string) $this->postParam("color");
            $this->setTagLogged($chat, $userId, (string) $this->postParam("tag"), preg_match("%^#[0-9a-f]{6}$%i", $color) ? $color : "#7a5bb5");
        }

        $this->returnJson(["members" => $chat->getMembersInfo(true)]);
    }

    public function renderApiAdminOrder(int $peer): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getChat($peer);
        if (!$chat->moveAdmin($this->user->id, (int) $this->postParam("user"), $this->postParam("dir") === "up")) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $this->returnJson(["members" => $chat->getMembersInfo(true)]);
    }

    public function renderApiSetAvatar(int $peer): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getChatWithRight($peer, "info");

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

        $chat->setAvatar("/photo" . $photo->getPrettyId());

        $this->returnJson(["avatar" => $chat->getAvatarUrl("normal")]);
    }

    public function renderApiDeleteAvatar(int $peer): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getChatWithRight($peer, "info");
        $chat->setAvatar(null);

        $this->returnJson(["avatar" => $chat->getAvatarUrl("normal")]);
    }

    public function renderApiInviteLink(int $peer): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $reset = $this->postParam("act") === "reset";
        $chat  = $reset ? $this->getChatWithAdminRight($peer, "invite") : $this->getChatWithRight($peer, "invite");

        $this->returnJson(["link" => $chat->getInviteLink($reset)]);
    }

    // настройки беседы из админ-панели
    public function renderApiSettings(int $peer): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getChat($peer);

        $permissions = [];
        foreach (array_keys(GroupChat::PERMISSIONS) as $key) {
            if (!is_null($this->postParam("perm_$key"))) {
                $permissions[$key] = $this->postParam("perm_$key") === "1";
            }
        }

        $changesInfo     = !is_null($this->postParam("name")) || !is_null($this->postParam("description"));
        $changesHistory = !is_null($this->postParam("history_visible"));
        $changesRights  = !is_null($this->postParam("slowmode")) || sizeof($permissions) > 0;
        if (($changesInfo && !$chat->can("info", $this->user->id))
            || ($changesHistory && !$chat->canManageParticipants($this->user->id))
            || ($changesRights && !$chat->hasAdminRight("ban", $this->user->id))) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        if ($changesInfo) {
            $name = trim((string) ($this->postParam("name") ?? $chat->getName()));
            if ($name === "") {
                header("HTTP/1.1 400 Bad Request");
                $this->returnJson(["error" => tr("messenger_create_chat_no_name")]);
            }

            $chat->setInfo($name, trim((string) ($this->postParam("description") ?? $chat->getDescription())));
        }

        if (!is_null($this->postParam("history_visible"))) {
            $chat->setHistoryVisible($this->postParam("history_visible") === "1");
        }

        if (sizeof($permissions) > 0) {
            $chat->setPermissions($permissions);
        }

        if (!is_null($this->postParam("slowmode"))) {
            $chat->setSlowmode((int) $this->postParam("slowmode"));
        }

        $this->returnJson([
            "name"            => $chat->getName(),
            "description"     => (string) $chat->getDescription(),
            "history_visible" => $chat->isHistoryVisible(),
            "permissions"     => $chat->getPermissions(),
            "slowmode"        => $chat->getSlowmode(),
        ]);
    }

    public function renderApiException(int $peer): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat   = $this->getChatWithAdminRight($peer, "ban");
        $userId = (int) $this->postParam("user");

        if ($chat->isAdmin($userId) && !$chat->outranks($this->user->id, $userId)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        if ($this->postParam("act") === "delete") {
            $chat->removeException($userId);
            $this->returnJson(["exceptions" => array_values($chat->getExceptions())]);
        }

        if (!$chat->isParticipant($userId) || $chat->isCreator($userId) || $userId === $this->user->id) {
            header("HTTP/1.1 400 Bad Request");
            exit();
        }

        $permissions = [];
        foreach (array_keys(GroupChat::PERMISSIONS) as $key) {
            if (!is_null($this->postParam("perm_$key"))) {
                $permissions[$key] = $this->postParam("perm_$key") === "1";
            }
        }

        $slowmode = $this->postParam("slowmode");
        $chat->setException($userId, $permissions, is_null($slowmode) ? null : (int) $slowmode);

        $this->returnJson(["exceptions" => array_values($chat->getExceptions())]);
    }

    public function renderApiTag(int $peer): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $chat = $this->getChat($peer);
        if (!$chat->can("tags", $this->user->id)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $color = (string) $this->postParam("color");
        if (!preg_match("%^#[0-9a-f]{6}$%i", $color)) {
            header("HTTP/1.1 400 Bad Request");
            exit();
        }

        $this->setTagLogged($chat, $this->user->id, (string) $this->postParam("tag"), $color);
        $this->returnJson(["tag" => $chat->getTags()[$this->user->id] ?? null]);
    }

    private function setTagLogged(GroupChat $chat, int $userId, string $text, string $color): void
    {
        $old = $chat->getTags()[$userId] ?? null;
        $chat->setTag($userId, $text, $color);
        $new = $chat->getTags()[$userId] ?? null;
        if ($old != $new) {
            $chat->logAction("tag", $this->user->id, $userId, $new ?? []);
        }
    }

    // недавние действия за неделю, видят только админы
    public function renderAudit(int $peer): void
    {
        $this->assertUserLoggedIn();

        $chat = $this->getChat($peer);
        if (!$chat->canManageParticipants($this->user->id)) {
            $this->flash("err", tr("error"), tr("forbidden"));
            $this->redirect($chat->getURL());
        }

        $link = fn(?User $user) => $user
            ? '<a href="' . htmlspecialchars($user->getURL()) . '">' . htmlspecialchars($user->getCanonicalName()) . '</a>'
            : htmlspecialchars(tr("messenger_chat_audit_unknown"));

        $entries = [];
        foreach ($chat->getActionLog() as $entry) {
            $key = "messenger_chat_audit_" . $entry->type;
            $tag = null;
            if ($entry->type === "tag") {
                $self = $entry->target?->getId() === $entry->actor?->getId();
                $key .= (empty($entry->data["text"]) ? "_removed" : "") . ($self ? "_self" : "_other");
                if (!empty($entry->data["text"])) {
                    $tag = '<span class="msg-tag im-tag" style="--tag: ' . htmlspecialchars((string) $entry->data["color"]) . '">' . htmlspecialchars((string) $entry->data["text"]) . '</span>';
                }
            }

            if ($entry->type === "deleted" && $entry->target?->getId() === $entry->actor?->getId()) {
                $key .= "_self";
            }

            $entries[] = (object) [
                "type"    => $entry->type,
                "actor"   => $entry->actor,
                "created" => $entry->created,
                "data"    => $entry->data,
                "html"    => str_replace(["%ACTOR%", "%TARGET%", "%TAG%"], [$link($entry->actor), $link($entry->target), $tag ?? ""], tr($key)),
            ];
        }

        $this->template->chat    = $chat;
        $this->template->entries = $entries;
    }

    public function renderJoin(string $hash): void
    {
        $this->assertUserLoggedIn();

        $chat = $this->groupChats->getByInviteHash($hash);
        if (!$chat) {
            $this->notFound();
        }

        if ($chat->isParticipant($this->user->id)) {
            $this->redirect($chat->getURL());
        }

        $this->template->banned = $chat->isBanned($this->user->id);
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $this->willExecuteWriteAction();

            if ($this->template->banned) {
                $this->flashFail("err", tr("error"), tr("messenger_join_chat_banned"));
            }

            $this->groupChats->addParticipant($chat->getId(), $this->user->id);
            (new Correspondence($this->user->identity, $chat))->markAsJoined();
            $chat->logAction("joined", $this->user->id);
            (new Correspondence($this->user->identity, $chat))->announce(["type" => "join"]);
            $this->redirect($chat->getURL());
        }

        $this->template->chat = $chat;
    }

    public function renderApiGetFriends(int $peer): void
    {
        $this->assertUserLoggedIn();

        $chat         = $this->getChat($peer);
        $participants = $chat->getParticipantIds();
        $canManage    = $chat->hasAdminRight("ban", $this->user->id);

        $result = [];
        foreach ($this->getFriendsSortedByMessages() as $friend) {
            $result[] = [
                "id"              => $friend->getId(),
                "name"            => $friend->getCanonicalName(),
                "avatar"          => $friend->getAvatarUrl("miniscule"),
                "already_in_chat" => in_array($friend->getId(), $participants, true),
                "banned"          => !$canManage && $chat->isBanned($friend->getId()),
            ];
        }

        $this->returnJson($result);
    }
}
