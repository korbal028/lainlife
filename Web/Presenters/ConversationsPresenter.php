<?php
declare(strict_types=1);
namespace openvk\Web\Presenters;

use Chandler\Signaling\SignalManager;
use openvk\Web\Events\NewMessageEvent;
use openvk\Web\Models\Entities\Conversation;
use openvk\Web\Models\Repositories\{Conversations, Users};

final class ConversationsPresenter extends OpenVKPresenter
{
    private $conversations;
    private $signaler;
    protected $presenterName = "conversations";

    public function __construct(Conversations $conversations)
    {
        $this->conversations = $conversations;
        $this->signaler = SignalManager::i();
        parent::__construct();
    }

    public function renderIndex(): void
    {
        $this->assertUserLoggedIn();
        $this->template->convs = $this->conversations->getByUser($this->user->identity);
    }

    public function renderApp(int $id): void
    {
        $this->assertUserLoggedIn();
        $conv = $this->conversations->get($id);
        if(!$conv || !$conv->isMember($this->user->identity)) {
            $this->notFound();
        }
        $this->template->disable_ajax = 1;
        $this->template->conv = $conv;
    }

    public function renderEvents(int $randNum): void
    {
        $this->assertUserLoggedIn();
        header("Content-Type: application/json");
        $this->signaler->listen(function ($event, $id) {
            exit(json_encode([[
                "UUID"  => $id,
                "event" => $event->getLongPoolSummary(),
            ]]));
        }, $this->user->id);
    }

    public function renderCreate(): void
    {
        $this->assertUserLoggedIn();
        if($_SERVER["REQUEST_METHOD"] !== "POST") {
            return;
        }
        $this->willExecuteWriteAction();
        $name = $this->postParam("name");
        if(empty($name)) {
            $this->flashFail("err", "Ошибка", "Введите название беседы.");
        }
        $conv = $this->conversations->create($name, $this->user->identity);
        $this->redirect("/convs/" . $conv->getId());
    }

    public function renderApiGetMessages(int $id, int $lastMsg): void
    {
        $this->assertUserLoggedIn();
        $conv = $this->conversations->get($id);
        if(!$conv || !$conv->isMember($this->user->identity)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        header("Content-Type: application/json");
        exit(json_encode($conv->getMessages(1, $lastMsg === 0 ? null : $lastMsg)));
    }

    public function renderApiSendMessage(int $id): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $conv = $this->conversations->get($id);
        if(!$conv || !$conv->isMember($this->user->identity)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $content = $this->postParam("content");
        if(empty($content)) {
            header("HTTP/1.1 400 Bad Request");
            exit();
        }

        $attachments = [];
        if (!empty($this->postParam("attachments"))) {
            $attachments_array = array_slice(explode(",", $this->postParam("attachments")), 0, OPENVK_ROOT_CONF["openvk"]["preferences"]["wall"]["postSizes"]["maxAttachments"]);
            if (sizeof($attachments_array) > 0) {
                $attachments = parseAttachments($attachments_array, ['photo', 'video', 'audio', 'note', 'doc']);
            }
        }

        $msg = $conv->sendMessage($this->user->identity, $content, $attachments);

        header("HTTP/1.1 202 Accepted");
        header("Content-Type: application/json");
        exit(json_encode($msg));
    }

    public function renderApiAddMember(int $id): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $conv = $this->conversations->get($id);
        if(!$conv || $conv->getOwner()->getId() !== $this->user->id) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $userId = (int) $this->postParam("user_id");
        $user = (new Users())->get($userId);
        if(!$user) {
            header("HTTP/1.1 404 Not Found");
            exit();
        }

        $conv->addMember($user);

        header("HTTP/1.1 200 OK");
        header("Content-Type: application/json");
        exit(json_encode(["success" => true]));
    }

    public function renderSettings(int $id): void
    {
        $this->assertUserLoggedIn();
        $conv = $this->conversations->get($id);
        if(!$conv || $conv->getOwner()->getId() !== $this->user->id) {
            $this->notFound();
        }

        if($_SERVER["REQUEST_METHOD"] === "POST") {
            $this->willExecuteWriteAction();
            $name = $this->postParam("name");
            if(!empty($name)) {
                $conv->setName($name);
                $conv->save();
            }
            $this->flash("succ", "Сохранено", "Настройки беседы обновлены.");
        }

        $this->template->conv = $conv;
    }
}
