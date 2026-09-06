<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use Chandler\Signaling\SignalManager;
use openvk\Web\Events\NewMessageEvent;
use openvk\Web\Events\DeleteMessageEvent;
use openvk\Web\Events\EditMessageEvent;
use openvk\Web\Events\ForwardMessageEvent;
use openvk\Web\Models\Repositories\{Users, Clubs, Messages};
use openvk\Web\Models\Entities\{Message, Correspondence};

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

    public function renderIndex(): void
    {
        $this->assertUserLoggedIn();

        if (isset($_GET["sel"])) {
            $this->pass("openvk!Messenger->app", $_GET["sel"]);
        }

        $page = (int) ($_GET["p"] ?? 1);
        $correspondences = iterator_to_array($this->messages->getCorrespondencies($this->user->identity, $page));

        // бля

        $this->template->corresps = $correspondences;
        $this->template->paginatorConf = (object) [
            "count"   => $this->messages->getCorrespondenciesCount($this->user->identity),
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
    }

private const MAX_LONGPOLL_PER_USER = 3;   // сколько одновременных long-poll разрешено одному юзеру
private const LONGPOLL_TIMEOUT_SEC  = 30;  // максимальное время ожидания события

public function renderEvents(int $randNum): void
{
    $this->assertUserLoggedIn();

    $userId  = $this->user->id;
    $lockKey = "longpoll:count:{$userId}";

    // --- лимит на юзера ---
    $current = (int) apcu_fetch($lockKey);
    if ($current >= self::MAX_LONGPOLL_PER_USER) {
        http_response_code(429);
        header("Content-Type: application/json");
        header("Retry-After: 5");
        echo json_encode(["error" => "Too many concurrent long-poll connections"]);
        return;
    }
    apcu_inc($lockKey, 1) ?: apcu_store($lockKey, 1);

    // гарантированно уменьшаем счётчик, даже если скрипт упадёт/оборвётся по exit()
    register_shutdown_function(static function () use ($lockKey) {
        apcu_dec($lockKey);
    });

    header("Content-Type: application/json");
    set_time_limit(0); // таймаут контролируем сами, PHP-лимит не должен нас прерывать раньше времени

    // третий аргумент listen() — это ДЛИТЕЛЬНОСТЬ ожидания в секундах (см. renderVKEvents),
    // а не абсолютный дедлайн; ранее сюда передавался time()+N, из-за чего long-poll
    // висел до принудительного обрыва инфраструктурой вместо контролируемых 30 секунд
    $this->signaler->listen(function ($event, $id) {
        echo json_encode([[
            "UUID"  => $id,
            "event" => $event->getLongPoolSummary(),
        ]]);
        exit;
    }, $userId, self::LONGPOLL_TIMEOUT_SEC);

    // если signaler сам умеет по таймауту вернуть управление без события —
    // отдаём пустой ответ 204, клиент сам переоткроет соединение
    http_response_code(204);
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

    public function renderApiWriteMessage(int $sel): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        if (empty($this->postParam("content")) && empty($this->postParam("attachments"))) {
            header("HTTP/1.1 400 Bad Request");
            exit("<b>Argument error</b>: param 'content' expected to be string, undefined given.");
        }

        $sel = $this->getCorrespondent($sel);
        if ($sel->getId() !== $this->user->id && !$sel->getPrivacyPermission('messages.write', $this->user->identity)) {
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

        $cor = new Correspondence($this->user->identity, $sel);
        $msg = new Message();
        $msg->setContent($this->postParam("content"));
        if (!empty($this->postParam("reply_to"))) {
            $msg->setForwarded_from((int) $this->postParam("reply_to"));
        }
        $cor->sendMessage($msg);

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
        if ($msg->getSender()->getId() !== $this->user->id) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $msg->setContent($this->postParam("content"));
        if (!empty($this->postParam("reply_to"))) {
            $msg->setForwarded_from((int) $this->postParam("reply_to"));
        }
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

    public function renderApiForwardMessage(int $sel, int $msgId): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $origMsg = (new Messages())->get($msgId);
        if (!$origMsg) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        $recipient = $this->getCorrespondent($sel);
        if (!$recipient) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        if ($recipient->getId() !== $this->user->id && !$recipient->getPrivacyPermission('messages.write', $this->user->identity)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $cor = new Correspondence($this->user->identity, $recipient);
        $msg = new Message();
        $msg->setContent($this->postParam("content") ?? "");
        $msg->setForwarded_from($msgId);
        $cor->sendMessage($msg);

        $recipient = $msg->getRecipient();
        $this->signaler->triggerEvent(new ForwardMessageEvent($msg), $recipient->getId());

        header("HTTP/1.1 202 Accepted");
        header("Content-Type: application/json");
        $simple = $msg->simplify();
        $this->enrichAttachmentsWithHTML($msg, $simple);
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
        $msg->save();

        $recipient = $msg->getRecipient();
        $sender = $msg->getSender();
        $this->signaler->triggerEvent(new DeleteMessageEvent($msgId), $recipient->getId());
        $this->signaler->triggerEvent(new DeleteMessageEvent($msgId), $sender->getId());

        header("HTTP/1.1 200 OK");
        header("Content-Type: application/json");
        exit(json_encode(["success" => true]));
    }

    public function renderApiGetDialogs(): void
    {
        $this->assertUserLoggedIn();

        $correspondences = iterator_to_array($this->messages->getCorrespondencies($this->user->identity, 1));
        $result = [];
        foreach ($correspondences as $cor) {
            $recipient = $cor->getCorrespondents()[1];
            $result[] = [
                "id"     => $recipient->getId(),
                "name"   => $recipient->getCanonicalName(),
                "avatar" => $recipient->getAvatarUrl('miniscule'),
            ];
        }

        header("Content-Type: application/json");
        exit(json_encode($result));
    }
}
