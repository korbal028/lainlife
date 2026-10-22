<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use Chandler\Signaling\SignalManager;
use openvk\Web\Events\NewMessageEvent;
use openvk\Web\Events\DeleteMessageEvent;
use openvk\Web\Events\EditMessageEvent;
use openvk\Web\Models\Repositories\{Users, Clubs, Messages, MessengerDialogs};
use openvk\Web\Models\Entities\Messages\Sticker;
use openvk\Web\Models\Entities\{Message, Correspondence, User, Club, Photo, Video, Audio, Document};

final class MessengerPresenter extends OpenVKPresenter
{
    private $messages;
    private $signaler;
    protected $presenterName = "messenger";

    public function __construct(Messages $messages)
    {
        $this->messages = $messages;
        $this->signaler = SignalManager::i();

        parent::__construct();
    }

    public function onStartup(): void
    {
        parent::onStartup();

        $this->template->hideFooter = true;
    }

    private function getCorrespondent(int $id): object
    {
        if ($id > 0) {
            return (new Users())->get($id);
        } elseif ($id < 0) {
            return (new Clubs())->get(abs($id));
        } elseif ($id === 0) {
            return $this->user->identity;
        }
    }

    // ответить можно только на живое сообщение из этого же диалога
    private function getReplyTarget(object $correspondent): ?Message
    {
        $id = (int) $this->postParam("reply_to");
        if ($id <= 0) {
            return null;
        }

        $msg = $this->messages->get($id);
        if (!$msg || $msg->isDeleted() || !$msg->isBetween($this->user->identity, $correspondent)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        return $msg;
    }

    // переслать можно любые сообщения из своих диалогов
    private function getForwardTargets(): array
    {
        $ids = array_unique(array_filter(array_map("intval", explode(",", (string) $this->postParam("forward_messages")))));
        if (sizeof($ids) > Message::MAX_FORWARDS) {
            header("HTTP/1.1 400 Bad Request");
            exit();
        }

        $messages = [];
        foreach ($ids as $id) {
            $msg = $this->messages->get($id);
            if (!$msg || $msg->isDeleted() || !$msg->hasParticipant($this->user->identity)) {
                header("HTTP/1.1 403 Forbidden");
                exit();
            }

            $messages[] = $msg;
        }

        usort($messages, fn($a, $b) => $a->getId() <=> $b->getId());
        return $messages;
    }

    public function renderIndex(): void
    {
        $this->assertUserLoggedIn();

        if (isset($_GET["sel"])) {
            $this->pass("openvk!Messenger->app", $_GET["sel"]);
        }

        $this->renderList(false);
    }

    public function renderArchive(): void
    {
        $this->assertUserLoggedIn();

        $this->template->_template = "Messenger/Index.latte";
        $this->renderList(true);
    }

    public function renderApiList(): void
    {
        $this->assertUserLoggedIn();

        $this->renderList(($_GET["archive"] ?? "0") === "1");
    }

    private function renderList(bool $archived): void
    {
        $page = (int) ($_GET["p"] ?? 1);
        $correspondences = iterator_to_array($this->messages->getCorrespondencies($this->user->identity, $page, null, null, $archived));

        $this->template->isArchive = $archived;
        $this->template->corresps = $correspondences;
        $this->template->dialogSettings = (new MessengerDialogs())->getAllFor($this->user->identity);
        $this->template->paginatorConf = (object) [
            "count"   => $this->messages->getCorrespondenciesCount($this->user->identity, $archived),
            "page"    => (int) ($_GET["p"] ?? 1),
            "amount"  => sizeof($this->template->corresps),
            "perPage" => OPENVK_DEFAULT_PER_PAGE,
            "tidy"    => false,
            "atTop"   => false,
        ];
    }

    public function renderApp(int $sel): void
    {
        $this->assertUserLoggedIn();

        $correspondent = $this->getCorrespondent($sel);
        if (!$correspondent) {
            $this->notFound();
        }

        if (!$this->user->identity->getPrivacyPermission('messages.write', $correspondent)) {
            $this->flash("err", tr("warning"), tr("user_may_not_reply"));
        }

        $this->template->disable_ajax  = 1;
        $this->template->selId         = $sel;
        $this->template->correspondent = $correspondent;
        $this->template->reactions     = Message::REACTIONS;
        $this->template->reactionStats = (object) $this->messages->getReactionStats($this->user->identity);
    }

    public function renderVKEvents(int $id): void
    {
        header("Access-Control-Allow-Origin: *");
        header("Content-Type: application/json");

        if ($this->queryParam("act") !== "a_check") {
            header("HTTP/1.1 400 Bad Request");
            exit();
        } elseif (!$this->queryParam("key")) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $key       = $this->queryParam("key");
        $payload   = hex2bin(substr($key, 0, 16));
        $signature = hex2bin(substr($key, 16));
        if (($signature ^ (~CHANDLER_ROOT_CONF["security"]["secret"] | ((string) $id))) !== $payload) {
            exit(json_encode([
                "failed" => 3,
            ]));
        }

        $legacy = $this->queryParam("version") < 3;

        $time = intval($this->queryParam("wait"));

        if ($time > 60) {
            $time = 60;
        } elseif ($time == 0) {
            $time = 25;
        } // default

        $this->signaler->listen(function ($event, $eId) use ($id) {
            exit(json_encode([
                "ts"      => time(),
                "updates" => [
                    $event->getVKAPISummary($id),
                ],
            ]));
        }, $id, $time);
    }

    public function renderApiGetMessages(int $sel, int $lastMsg): void
    {
        $this->assertUserLoggedIn();

        $correspondent = $this->getCorrespondent($sel);
        if (!$correspondent) {
            $this->notFound();
        }

        $messages       = [];
        $correspondence = new Correspondence($this->user->identity, $correspondent);
        foreach ($correspondence->getMessages(1, $lastMsg === 0 ? null : $lastMsg, null, 0) as $message) {
            $simple = $message->simplify();
            $this->enrichAttachmentsWithHTML($message, $simple);
            $messages[] = $simple;
        }

        header("Content-Type: application/json");
        exit(json_encode($messages));
    }

    // short-poll instead long-poll
    public function renderApiSync(int $sel, int $lastMsg): void
    {
        $this->assertUserLoggedIn();

        $correspondent = $this->getCorrespondent($sel);
        if (!$correspondent) {
            $this->notFound();
        }

        $since = (int) ($this->queryParam("since") ?? 0);
        $now   = time();

        $correspondence = new Correspondence($this->user->identity, $correspondent);

        // CAP_BEHAVIOUR_START_MESSAGE_ID => `id` > $lastMsg (в отличие от apiGetMessages/_loadHistory,
        // которым нужны СТАРЫЕ сообщения для пагинации вверх, здесь нужны НОВЫЕ — те, что пришли
        // после последнего известного клиенту id); reverse=true отдаёт их в хронологическом порядке
        $messages = [];
        foreach ($correspondence->getMessages(Correspondence::CAP_BEHAVIOUR_START_MESSAGE_ID, $lastMsg === 0 ? null : $lastMsg, null, 0, true) as $message) {
            $simple = $message->simplify();
            $this->enrichAttachmentsWithHTML($message, $simple);
            $messages[] = $simple;
        }

        $edited  = [];
        $deleted = [];
        if ($since > 0) {
            foreach ($correspondence->getChangedMessages($since) as $message) {
                if ($message->isDeleted()) {
                    $deleted[] = $message->getId();
                } else {
                    $simple = $message->simplify();
                    $this->enrichAttachmentsWithHTML($message, $simple);
                    $edited[] = $simple;
                }
            }
        }

        $typing = false;
        if (function_exists("apcu_fetch")) {
            $typing = (bool) apcu_fetch("typing:{$correspondent->getId()}:{$this->user->id}");
        }

        $pinned = $correspondence->getPinnedMessage();
        if ($pinned) {
            $pinned = $pinned->simplify();
        }

        header("Content-Type: application/json");
        exit(json_encode([
            "ts"       => $now,
            "messages" => $messages,
            "edited"   => $edited,
            "deleted"  => $deleted,
            "typing"   => $typing,
            "pinned"   => $pinned,
        ]));
    }

    public function renderApiWriteMessage(int $sel): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        if (empty($this->postParam("content")) && empty($this->postParam("attachments")) && empty($this->postParam("forward_messages"))) {
            header("HTTP/1.1 400 Bad Request");
            exit("<b>Argument error</b>: param 'content' expected to be string, undefined given.");
        }

        $sel = $this->getCorrespondent($sel);
        if ($sel->getId() !== $this->user->id && !$sel->getPrivacyPermission('messages.write', $this->user->identity)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $replyTo  = $this->getReplyTarget($sel);
        $forwards = $this->getForwardTargets();

        $attachments = [];
        if (!empty($this->postParam("attachments"))) {
            $attachments_array = array_slice(explode(",", $this->postParam("attachments")), 0, OPENVK_ROOT_CONF["openvk"]["preferences"]["wall"]["postSizes"]["maxAttachments"]);
            if (sizeof($attachments_array) > 0) {
                $attachments = parseAttachments($attachments_array, ['photo', 'video', 'audio', 'note', 'doc', 'sticker']);
            }
        }

        if (!Sticker::checkMessage($attachments, $this->postParam("content"), $this->user->identity) || ($forwards && array_filter($attachments, fn($a) => $a instanceof Sticker))) {
            header("HTTP/1.1 400 Bad Request");
            exit();
        }

        $cor = new Correspondence($this->user->identity, $sel);
        $msg = new Message();
        $msg->setContent($this->postParam("content") ?? "");
        if ($replyTo) {
            $msg->setReply_To($replyTo->getId());
        }
        $cor->sendMessage($msg);
        $msg->setForwardedMessages($forwards);

        foreach ($attachments as $attachment) {
            if (!$attachment || $attachment->isDeleted() || !$attachment->canBeViewedBy($this->user->identity)) {
                continue;
            }

            $msg->attach($attachment);
        }

        header("HTTP/1.1 202 Accepted");
        header("Content-Type: application/json");
        $simple = $msg->simplify();
        $this->enrichAttachmentsWithHTML($msg, $simple);
        exit(json_encode($simple));
    }

    private function enrichAttachmentsWithHTML(Message $messageObj, array &$simplifiedArray): void
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
                    # костыль жоский
                    dirname(__FILE__) . '/templates/Audio/player.latte',
                    [
                        'audio' => $originalObj,
                        'thisUser' => $this->user->identity,
                        'hideButtons' => false,
                        'club' => null,
                    ]
                );
            }
            if ($html !== "") {
                $attachmentData['html'] = $html;
            }
        }
        unset($attachmentData);

        // плееры нужны и во вложениях пересланных сообщений
        if (!empty($simplifiedArray['fwd'])) {
            foreach ($messageObj->getForwardedMessages() as $index => $fwd) {
                if (isset($simplifiedArray['fwd'][$index])) {
                    $this->enrichAttachmentsWithHTML($fwd, $simplifiedArray['fwd'][$index]);
                }
            }
        }
    }

    public function renderApiSendTypingStatus(int $sel): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $sel = $this->getCorrespondent($sel);
        if ($sel->getId() !== $this->user->id && !$sel->getPrivacyPermission('messages.write', $this->user->identity)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $cor = new Correspondence($this->user->identity, $sel);
        $result = $cor->sendTypingEvent();
        header("HTTP/1.1 202 Accepted");
        header("Content-Type: application/json");
        exit(json_encode($result));
    }

    public function renderApiEditMessage(int $sel, int $msgId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        if (empty($this->postParam("content"))) {
            header("HTTP/1.1 400 Bad Request");
            exit();
        }

        $msg = (new Messages())->get($msgId);
        if (!$msg) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        // текст к стикеру не добавить
        if (Sticker::isIn($msg->getChildren())) {
            header("HTTP/1.1 400 Bad Request");
            exit();
        }
        if ($msg->getSender()->getId() !== $this->user->id) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $msg->setContent($this->postParam("content"));
        $msg->setEdited(time());
        $msg->save();

        $recipient = $msg->getRecipient();
        $sender = $msg->getSender();
        $this->signaler->triggerEvent(new EditMessageEvent($msg), $recipient->getId());
        $this->signaler->triggerEvent(new EditMessageEvent($msg), $sender->getId());

        header("HTTP/1.1 200 OK");
        header("Content-Type: application/json");
        $simple = $msg->simplify();
        exit(json_encode($simple));
    }

    public function renderApiDeleteMessage(int $sel, int $msgId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $msg = (new Messages())->get($msgId);
        if (!$msg) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        if ($msg->getSender()->getId() !== $this->user->id) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $msg->setDeleted(1);
        $msg->setEdited(time()); // используется как метка "изменено" для поллинга (apiSync)
        $msg->save();

        $recipient = $msg->getRecipient();
        $sender = $msg->getSender();
        $this->signaler->triggerEvent(new DeleteMessageEvent($msgId), $recipient->getId());
        $this->signaler->triggerEvent(new DeleteMessageEvent($msgId), $sender->getId());

        header("HTTP/1.1 200 OK");
        header("Content-Type: application/json");
        exit(json_encode(["success" => true]));
    }

    public function renderApiDialogAction(): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction(true);

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            header("HTTP/1.1 405 Method Not Allowed");
            exit();
        }

        $dialogs = new MessengerDialogs();
        $me      = $this->user->identity;

        if ($this->postParam("act") === "reorder") {
            $position = 1;
            foreach (explode(",", (string) $this->postParam("order")) as $sel) {
                $peer = $this->getCorrespondent((int) $sel);
                if ($peer && $dialogs->get($me, $peer)["pinned"] > 0) {
                    $dialogs->set($me, $peer, ["pinned" => $position++]);
                }
            }

            header("Content-Type: application/json");
            exit(json_encode(["success" => true]));
        }

        $correspondent = $this->getCorrespondent((int) $this->postParam("sel"));
        if (!$correspondent) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        switch ($this->postParam("act")) {
            case "pin":
                $dialogs->pinToTop($me, $correspondent);
                break;
            case "unpin":
                $dialogs->set($me, $correspondent, ["pinned" => 0]);
                break;
            case "archive":
                $dialogs->set($me, $correspondent, ["archived" => 1, "pinned" => 0]);
                break;
            case "unarchive":
                $dialogs->set($me, $correspondent, ["archived" => 0]);
                break;
            case "mute":
                $dialogs->set($me, $correspondent, ["muted" => 1]);
                break;
            case "unmute":
                $dialogs->set($me, $correspondent, ["muted" => 0]);
                break;
            case "delete":
                $cor = new Correspondence($me, $correspondent);
                if ($this->postParam("for_all") === "1" && $correspondent->getId() !== $me->getId()) {
                    $cor->deleteForAll();
                } else {
                    $cor->clearForOwner();
                }
                break;
            default:
                header("HTTP/1.1 400 Bad Request");
                exit();
        }

        header("Content-Type: application/json");
        exit(json_encode(["success" => true]));
    }

    public function renderApiDeleteMessages(int $sel): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $correspondent = $this->getCorrespondent($sel);
        if (!$correspondent) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        $cor    = new Correspondence($this->user->identity, $correspondent);
        $forAll = $this->postParam("for_all") === "1";
        $repo   = new Messages();

        $deleted = [];
        $hidden  = [];
        foreach (array_unique(array_map("intval", explode(",", (string) $this->postParam("ids")))) as $id) {
            $msg = $repo->get($id);
            if (!$msg || $msg->isDeleted() || !$cor->hasMessage($msg)) {
                continue;
            }

            $sender = $msg->getSender();
            if ($forAll && $sender instanceof User && $sender->getId() === $this->user->id) {
                $msg->setDeleted(1);
                $msg->setEdited(time()); // метка для поллинга (apiSync)
                $msg->save();

                $this->signaler->triggerEvent(new DeleteMessageEvent($id), $msg->getRecipient()->getId());
                $deleted[] = $id;
            } else {
                $hidden[] = $id;
            }
        }

        $cor->hideMessagesForOwner($hidden);

        header("Content-Type: application/json");
        exit(json_encode(["deleted" => $deleted, "hidden" => $hidden]));
    }

    public function renderApiPinMessage(int $sel): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $correspondent = $this->getCorrespondent($sel);
        if (!$correspondent) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        $cor   = new Correspondence($this->user->identity, $correspondent);
        $msgId = (int) $this->postParam("msg_id");
        $msg   = null;
        if ($msgId !== 0) {
            $msg = (new Messages())->get($msgId);
            if (!$msg || $msg->isDeleted() || !$cor->hasMessage($msg)) {
                header("HTTP/1.1 404 Not Found");
                exit();
            }
        }

        $cor->setPinnedMessage($msgId);

        header("Content-Type: application/json");
        exit(json_encode(["pinned" => $msg ? $msg->simplify() : null]));
    }

    public function renderApiReactMessage(int $sel, int $msgId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $correspondent = $this->getCorrespondent($sel);
        $msg           = (new Messages())->get($msgId);
        if (!$correspondent || !$msg || $msg->isDeleted() || !(new Correspondence($this->user->identity, $correspondent))->hasMessage($msg)) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        $reaction = (string) $this->postParam("reaction");
        if (!in_array($reaction, Message::REACTIONS, true)) {
            header("HTTP/1.1 400 Bad Request");
            exit();
        }

        $msg->toggleReaction($this->user->identity, $reaction);

        header("Content-Type: application/json");
        exit(json_encode(["reactions" => $msg->getReactions()]));
    }

    // карточка собеседника для меню вложений
    public function renderApiPeerInfo(int $sel): void
    {
        $this->assertUserLoggedIn();

        $peer = $this->getCorrespondent($sel);
        if (!$peer) {
            $this->notFound();
        }

        $me     = $this->user->identity;
        $isUser = $peer instanceof User;
        $isSelf = $isUser && $peer->getId() === $me->getId();

        $birthday = null;
        if ($isUser && !$isSelf && $peer->getBirthday() && $peer->getBirthdayPrivacy() < 2 && $peer->getPrivacyPermission("page.info.read", $me)) {
            $birthday = $peer->getBirthdayPrivacy() === 0
                ? $peer->getBirthday()->format("%e %B %Y") . ", " . tr("years", $peer->getAge())
                : $peer->getBirthday()->format("%e %B");
        }

        // пустые разделы не показываем, как в телеграме
        $counts = [];
        foreach ((new Correspondence($me, $peer))->getAttachmentsCount() as $kind => $count) {
            if ($count > 0) {
                $counts[] = ["kind" => $kind, "label" => tr("messenger_info_{$kind}s", $count), "title" => tr("messenger_info_{$kind}s_title")];
            }
        }

        $this->returnJson([
            "name"      => $isSelf ? tr("saved_messages") : $peer->getCanonicalName(),
            "avatar"    => $peer->getAvatarUrl("normal"),
            "link"      => $peer->getURL(),
            "self"      => $isSelf,
            "shortcode" => $isSelf ? null : $peer->getShortCode(),
            "birthday"  => $birthday ? trim($birthday) : null,
            "counts"    => $counts,
            "blocked"   => ($isUser && !$isSelf) ? $peer->isBlacklistedBy($me) : null,
            "peer_id"   => $isUser ? $peer->getId() : null,
        ]);
    }

    public function renderApiPeerAttachments(int $sel): void
    {
        $this->assertUserLoggedIn();

        $peer = $this->getCorrespondent($sel);
        $kind = (string) $this->queryParam("type");
        if (!$peer || !in_array($kind, Correspondence::ATTACHMENT_KINDS, true)) {
            $this->notFound();
        }

        $perPage = 30;
        $offset  = max(0, (int) $this->queryParam("offset"));
        $found   = (new Correspondence($this->user->identity, $peer))->getAttachments($kind, $offset, $perPage + 1);

        $items = [];
        foreach (array_slice($found, 0, $perPage) as $item) {
            $simple = $this->simplifyAttachment($item);
            if ($simple) {
                $items[] = $simple;
            }
        }

        $this->returnJson([
            "items" => $items,
            "more"  => sizeof($found) > $perPage,
        ]);
    }

    // вложение для списков в меню вложений и в поиске по всем чатам
    private function simplifyAttachment(object $item): ?array
    {
        if ($item instanceof Photo) {
            return [
                "id"    => $item->getPrettyId(),
                "thumb" => $item->getURLBySizeId("normal"),
                "url"   => $item->getURL(),
            ];
        } elseif ($item instanceof Video) {
            return [
                "id"     => $item->getOwner()->getId() . "_" . $item->getVirtualId(),
                "name"   => $item->getName(),
                "thumb"  => $item->getThumbnailURL(),
                "length" => $item->getFormattedLength(),
            ];
        } elseif ($item instanceof Audio) {
            // плеер темы, если она переопределяет шаблоны
            $player = ($this->template->_templatePath ?? null) . "/Audio/player.latte";
            if (!is_file($player)) {
                $player = dirname(__FILE__) . "/templates/Audio/player.latte";
            }

            return [
                "html" => $this->getTemplatingEngine()->renderToString($player, [
                    "audio"       => $item,
                    "thisUser"    => $this->user->identity,
                    "hideButtons" => false,
                    "club"        => null,
                ]),
            ];
        } elseif ($item instanceof Document) {
            return [
                "link"    => "/doc" . $item->getPrettyId(),
                "url"     => preg_replace("~^https?:~", "", $item->getURL()),
                "name"    => $item->getName(),
                "ext"     => $item->getFileExtension(),
                "size"    => readable_filesize($item->getFilesize()),
                "preview" => $item->hasPreview() ? $item->getPreview()->getURLBySizeId("medium") : null,
            ];
        }

        return null;
    }

    // id собеседника как в /im?sel= (у клубов отрицательный)
    private function getSel(object $peer): int
    {
        return $peer instanceof Club ? -$peer->getId() : $peer->getId();
    }

    private function isSelf(object $peer): bool
    {
        return $peer instanceof User && $peer->getId() === $this->user->id;
    }

    // собеседник в диалоге, которому принадлежит сообщение
    private function getMessagePeer(Message $message): object
    {
        $sender = $message->getSender();

        return $this->isSelf($sender) ? $message->getRecipient() : $sender;
    }

    // текст найденного сообщения без разметки, с подсвеченным запросом; длинный обрезается вокруг первого совпадения
    private function searchPreview(Message $message, string $query): string
    {
        $text = html_entity_decode(strip_tags($message->getText()), ENT_QUOTES | ENT_HTML5, "UTF-8");
        $text = trim(preg_replace("~\s+~u", " ", $text) ?? $text);

        if (mb_strlen($text) > 200) {
            $pos   = $query === "" ? false : mb_stripos($text, $query);
            $start = $pos === false ? 0 : max(0, $pos - 60);
            $text  = ($start > 0 ? "…" : "") . mb_substr($text, $start, 200) . ($start + 200 < mb_strlen($text) ? "…" : "");
        }

        $parts = $query === "" ? false : preg_split("~(" . preg_quote($query, "~") . ")~iu", $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return htmlspecialchars($text);
        }

        $html = "";
        foreach ($parts as $i => $part) {
            $html .= $i % 2 ? "<mark class=\"im-search-hl\">" . htmlspecialchars($part) . "</mark>" : htmlspecialchars($part);
        }

        return $html;
    }

    // вкладки вложений в поиске по всем чатам, в порядке показа
    private const SEARCH_MEDIA = ["photo", "video", "audio", "gif", "doc"];

    public function renderSearch(): void
    {
        $this->assertUserLoggedIn();

        $this->prepareSearch();
    }

    // те же результаты без страницы вокруг: поиск обновляет их по мере ввода и догружает при прокрутке
    public function renderApiSearch(): void
    {
        $this->assertUserLoggedIn();

        $this->prepareSearch();
    }

    private function prepareSearch(): void
    {
        $me        = $this->user->identity;
        $query     = trim((string) $this->queryParam("q"));
        $page      = max(1, (int) ($this->queryParam("p") ?? 1));
        $requested = (string) $this->queryParam("section");
        $matchSelf = $query === "" || mb_stripos(tr("saved_messages"), $query) !== false;

        // вкладки показываются всегда, как в Telegram; чаты и сообщения ищутся по запросу, а вложения показываются все,
        // без запроса во вкладке чатов — все чаты, новые первыми
        $counts = [
            "chats"    => $this->messages->searchCorrespondenciesCount($me, $query, $matchSelf),
            "messages" => $query !== "" ? $this->messages->searchMessagesCount($me, $query) : 0,
        ];

        $attachments = $this->messages->getAttachmentsCount($me);
        foreach (self::SEARCH_MEDIA as $kind) {
            $counts[$kind] = $attachments[$kind];
        }

        $section = $requested;
        if (!isset($counts[$section])) {
            $section = ($query !== "" && $counts["chats"] === 0) ? "messages" : "chats";
        }

        $perPage = match ($section) {
            "chats"        => OPENVK_DEFAULT_PER_PAGE,
            "photo", "gif" => 30,
            default        => 20,
        };
        $offset  = ($page - 1) * $perPage;

        $results = [];
        if ($section === "chats") {
            $results = $this->messages->searchCorrespondencies($me, $query, $matchSelf, $page, $perPage);
        } elseif ($section === "messages") {
            if ($query !== "") {
                foreach ($this->messages->searchMessages($me, $query, $offset, $perPage) as $message) {
                    $results[] = $this->describeFoundMessage($message, $query);
                }
            }
        } else {
            foreach ($this->messages->getAttachments($me, $section, $offset, $perPage) as [$item, $message]) {
                $peer = $this->getMessagePeer($message);
                $att  = $this->simplifyAttachment($item);
                if (!$att) {
                    continue;
                }

                $results[] = (object) [
                    "att"  => $att,
                    "url"  => "/im?sel=" . $this->getSel($peer) . "&msg=" . $message->getId(),
                    "chat" => $this->isSelf($peer) ? tr("saved_messages") : $peer->getCanonicalName(),
                ];
            }
        }

        $count = $counts[$section];

        $this->template->query    = $query;
        $this->template->section  = $section;
        $this->template->counts   = $counts;
        $this->template->results  = $results;
        // следующая страница этого же раздела, её подгружает прокрутка
        $this->template->nextPage = $page * $perPage < $count
            ? "/im/api/search?" . http_build_query(["q" => $query !== "" ? $query : null, "section" => $section, "p" => $page + 1])
            : null;
    }

    // найденное сообщение для поиска по всем чатам: в каком оно диалоге и куда ведёт
    private function describeFoundMessage(Message $message, string $query): object
    {
        $peer = $this->getMessagePeer($message);

        return (object) [
            "message" => $message,
            "peer"    => $peer,
            "url"     => "/im?sel=" . $this->getSel($peer) . "&msg=" . $message->getId(),
            "saved"   => $this->isSelf($peer),
            "own"     => !$this->isSelf($peer) && $this->isSelf($message->getSender()),
            "text"    => $this->searchPreview($message, $query),
        ];
    }

    public function renderApiSearchMessages(int $sel): void
    {
        $this->assertUserLoggedIn();

        $peer = $this->getCorrespondent($sel);
        if (!$peer) {
            $this->notFound();
        }

        $query   = trim((string) $this->queryParam("q"));
        $perPage = 20;
        $offset  = max(0, (int) $this->queryParam("offset"));
        $cor     = new Correspondence($this->user->identity, $peer);
        $found   = $query === "" ? [] : $cor->searchMessages($query, $offset, $perPage + 1);

        $items = [];
        foreach (array_slice($found, 0, $perPage) as $message) {
            $sender  = $message->getSender();
            $items[] = [
                "uuid"   => $message->getId(),
                "sender" => [
                    "name"   => $sender->getCanonicalName(),
                    "avatar" => $sender->getAvatarUrl("miniscule"),
                ],
                "ts"     => $message->getSendTime()->timestamp(),
                "text"   => $this->searchPreview($message, $query),
            ];
        }

        $this->returnJson([
            "items" => $items,
            "count" => ($query === "" || $offset > 0) ? null : $cor->searchMessagesCount($query),
            "more"  => sizeof($found) > $perPage,
        ]);
    }

    // кусок истории для перехода к сообщению (msg) или дате (date) и догрузка более новых после такого перехода (after)
    public function renderApiHistory(int $sel): void
    {
        $this->assertUserLoggedIn();

        $peer = $this->getCorrespondent($sel);
        if (!$peer) {
            $this->notFound();
        }

        $perPage  = OPENVK_DEFAULT_PER_PAGE * 2;
        $cor      = new Correspondence($this->user->identity, $peer);
        $target   = null;
        $olderEnd = null;

        if (!is_null($this->queryParam("after"))) {
            $messages = $cor->getMessages(Correspondence::CAP_BEHAVIOUR_START_MESSAGE_ID, (int) $this->queryParam("after"), $perPage + 1, 0, true);
            $newerEnd = sizeof($messages) <= $perPage;
            $messages = array_slice($messages, 0, $perPage);
        } else {
            if (!is_null($this->queryParam("date"))) {
                $target = $cor->getNearestMessageId((int) $this->queryParam("date"));
            } else {
                $msg    = $this->messages->get((int) $this->queryParam("msg"));
                $target = ($msg && !$msg->isDeleted() && $cor->hasMessage($msg)) ? $msg->getId() : null;
            }

            [$messages, $hasOlder, $hasNewer] = $target ? $cor->getMessagesAround($target, $perPage, $perPage) : [[], false, false];
            $olderEnd = !$hasOlder;
            $newerEnd = !$hasNewer;
        }

        $result = [];
        foreach ($messages as $message) {
            $simple = $message->simplify();
            $this->enrichAttachmentsWithHTML($message, $simple);
            $result[] = $simple;
        }

        $this->returnJson([
            "target"    => $target,
            "messages"  => $result,
            "older_end" => $olderEnd,
            "newer_end" => $newerEnd,
        ]);
    }

    // дни с сообщениями для календаря; границы месяца и часовой пояс (в минутах) присылает браузер
    public function renderApiCalendar(int $sel): void
    {
        $this->assertUserLoggedIn();

        $peer = $this->getCorrespondent($sel);
        if (!$peer) {
            $this->notFound();
        }

        $from = (int) $this->queryParam("from");
        $to   = min((int) $this->queryParam("to"), $from + 32 * 86400);
        $tz   = max(-14 * 60, min(14 * 60, (int) $this->queryParam("tz")));

        $cor = new Correspondence($this->user->identity, $peer);
        [$first, $last] = $cor->getTimeBounds();

        $this->returnJson([
            "days"  => $to > $from ? $cor->getMessageDays($from, $to, $tz * 60) : [],
            "first" => $first,
            "last"  => $last,
        ]);
    }

    public function renderApiGetDialogs(): void
    {
        $this->assertUserLoggedIn();

        $perPage  = 30;
        $page     = max(1, (int) ($this->queryParam("p") ?? 1));
        $archived = (bool) $this->queryParam("archived");

        $result = [];
        foreach ($this->messages->getCorrespondencies($this->user->identity, $page, $perPage, null, $archived) as $cor) {
            $recipient = $cor->getCorrespondents()[1];
            $result[] = [
                "id"     => $recipient instanceof User ? $recipient->getId() : -$recipient->getId(),
                "name"   => $recipient->getCanonicalName(),
                "avatar" => $recipient->getAvatarUrl('miniscule'),
            ];
        }

        header("Content-Type: application/json");
        exit(json_encode([
            "items" => $result,
            "more"  => $page * $perPage < $this->messages->getCorrespondenciesCount($this->user->identity, $archived),
        ]));
    }
}
