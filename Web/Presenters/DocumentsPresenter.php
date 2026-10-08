<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use openvk\Web\Models\Repositories\{Documents, Clubs};
use openvk\Web\Models\Entities\Document;
use openvk\Web\Util\GifSearch;
use Nette\InvalidStateException as ISE;

final class DocumentsPresenter extends OpenVKPresenter
{
    protected $presenterName = "documents";
    protected $silent = true;

    private const GIFS_PER_PAGE = 30;

    public function renderList(?int $owner_id = null): void
    {
        $this->assertUserLoggedIn();

        $this->template->_template = "Documents/List.latte";
        if ($owner_id > 0) {
            $this->notFound();
        }

        if ($owner_id < 0) {
            $owner = (new Clubs())->get(abs($owner_id));
            if (!$owner || $owner->isBanned()) {
                $this->notFound();
            } else {
                $this->template->group = $owner;
            }
        }

        if (!$owner_id) {
            $owner_id = $this->user->id;
        }

        $current_tab   = (int) ($this->queryParam("tab") ?? 0);
        $current_order = (int) ($this->queryParam("order") ?? 0);
        $page  = (int) ($this->queryParam("p") ?? 1);
        $order = in_array($current_order, [0,1,2]) ? $current_order : 0;
        $tab   = in_array($current_tab, [0,1,2,3,4,5,6,7,8]) ? $current_tab : 0;

        $api_request = $this->queryParam("picker") == "1";
        if ($api_request && $_SERVER["REQUEST_METHOD"] === "POST") {
            $ctx_type = $this->postParam("context");
            $docs = null;

            switch ($ctx_type) {
                default:
                case "list":
                    $docs = (new Documents())->getDocumentsByOwner($owner_id, (int) $order, (int) $tab);
                    break;
                case "search":
                    $ctx_query = $this->postParam("ctx_query");
                    $docs = (new Documents())->find($ctx_query);
                    break;
            }

            $this->template->docs  = $docs->page($page, OPENVK_DEFAULT_PER_PAGE);
            $this->template->page  = $page;
            $this->template->count = $docs->size();
            $this->template->pagesCount = ceil($this->template->count / OPENVK_DEFAULT_PER_PAGE);
            $this->template->_template = "Documents/ApiGetContext.latte";
            return;
        }

        $docs = (new Documents())->getDocumentsByOwner($owner_id, (int) $order, (int) $tab);
        $this->template->tabs  = (new Documents())->getTypes($owner_id);
        $this->template->tags  = (new Documents())->getTags($owner_id, (int) $tab);
        $this->template->current_tab = $tab;
        $this->template->order = $order;
        $this->template->count = $docs->size();
        $this->template->docs  = iterator_to_array($docs->page($page, OPENVK_DEFAULT_PER_PAGE));
        $this->template->locale_string = "you_have_x_documents";
        if ($current_tab != 0) {
            $this->template->locale_string = "x_documents_in_tab";
        } elseif ($owner_id < 0) {
            $this->template->locale_string = "group_has_x_documents";
        }

        $this->template->canUpload = $owner_id == $this->user->id || $this->template->group->canBeModifiedBy($this->user->identity);
        $this->template->paginatorConf = (object) [
            "count"   => $this->template->count,
            "page"    => $page,
            "amount"  => sizeof($this->template->docs),
            "perPage" => OPENVK_DEFAULT_PER_PAGE,
            "tidy"    => false,
            "atTop"   => false,
        ];
    }

    public function renderListGroup(?int $gid)
    {
        $this->renderList($gid);
    }

    public function renderUpload()
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $group  = null;
        $isAjax = $this->postParam("ajax", false) == 1;
        $ref    = $this->postParam("referrer", false) ?? "user";

        if (!is_null($this->queryParam("gid"))) {
            $gid   = (int) $this->queryParam("gid");
            $group = (new Clubs())->get($gid);
            if (!$group || $group->isBanned()) {
                $this->flashFail("err", tr("forbidden"), tr("not_enough_permissions_comment"), null, $isAjax);
            }

            if (!$group->canUploadDocs($this->user->identity)) {
                $this->flashFail("err", tr("forbidden"), tr("not_enough_permissions_comment"), null, $isAjax);
            }
        }

        $this->template->group = $group;
        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            return;
        }

        $owner = $this->user->id;
        if ($group) {
            $owner = $group->getRealId();
        }

        $upload = $_FILES["blob"];
        $name = $this->postParam("name");
        $tags = $this->postParam("tags");
        $folder = $this->postParam("folder");
        $owner_hidden = ($this->postParam("owner_hidden") ?? "off") === "on";

        try {
            $document = new Document();
            $document->setOwner($owner);
            $document->setName(ovk_proc_strtr($name, 255));
            $document->setFolder_id($folder);
            $document->setTags(empty($tags) ? null : $tags);
            $document->setOwner_hidden($owner_hidden);
            $document->setFile([
                "tmp_name" => $upload["tmp_name"],
                "error"    => $upload["error"],
                "name"     => $upload["name"],
                "size"     => $upload["size"],
                "preview_owner" => $this->user->id,
            ]);

            $document->save();
        } catch (\TypeError $e) {
            $this->flashFail("err", tr("forbidden"), $e->getMessage(), null, $isAjax);
        } catch (ISE $e) {
            $this->flashFail("err", tr("forbidden"), "corrupted file", null, $isAjax);
        } catch (\ValueError $e) {
            $this->flashFail("err", tr("forbidden"), $e->getMessage(), null, $isAjax);
        } catch (\ImagickException $e) {
            $this->flashFail("err", tr("forbidden"), tr("error_file_preview"), null, $isAjax);
        }

        if (!$isAjax) {
            $this->redirect("/docs" . (isset($group) ? $group->getRealId() : ""));
        } else {
            $this->returnJson([
                "success"  => true,
                "redirect" => "/docs" . (isset($group) ? $group->getRealId() : ""),
            ]);
        }
    }

    public function renderPage(int $virtual_id, int $real_id): void
    {
        $this->assertUserLoggedIn();

        $access_key = $this->queryParam("key");
        $doc = (new Documents())->getDocumentById((int) $virtual_id, (int) $real_id, $access_key);
        if (!$doc || $doc->isDeleted()) {
            $this->notFound();
        }

        if (!$doc->checkAccessKey($access_key)) {
            $this->notFound();
        }

        $this->template->doc        = $doc;
        $this->template->type       = $doc->getVKAPIType();
        $this->template->is_image   = $doc->isImage();
        $this->template->tags       = $doc->getTags();
        $this->template->copied     = $doc->isCopiedBy($this->user->identity);
        $this->template->copyImportance = true;
        $this->template->modifiable = $doc->canBeModifiedBy($this->user->identity);
    }

    # Вкладка GIF в панели смайликов: гифки из документов пользователя
    public function renderGifs(): void
    {
        $this->assertUserLoggedIn();

        $offset = max(0, (int) ($this->queryParam("offset") ?? 0));
        $docs   = (new Documents())->getDocumentsByOwner($this->user->id, 0, Document::VKAPI_TYPE_GIF);

        $items = [];
        foreach ($docs->offsetLimit($offset, self::GIFS_PER_PAGE) as $doc) {
            [$width, $height] = [0, 0];
            try {
                $preview = $doc->getPreview();
                if ($preview) {
                    [$width, $height] = $preview->getDimensions();
                }
            } catch (\Throwable $e) {
                # без размеров сетка покажет гифку квадратом
            }

            $items[] = [
                "attachment" => $doc->getPrettiestId(),
                "name"       => $doc->getName(),
                "url"        => preg_replace("%^https?:%", "", $doc->getURL()),
                "width"      => (int) $width,
                "height"     => (int) $height,
            ];
        }

        $next = $offset + self::GIFS_PER_PAGE;
        $this->returnJson([
            "items" => $items,
            "next"  => $next < $docs->size() ? $next : null,
        ]);
    }

    # Поиск гифок в интернете (пустой запрос - популярные)
    public function renderGifSearch(): void
    {
        $this->assertUserLoggedIn();

        if (!GifSearch::isEnabled()) {
            $this->returnJson(["items" => [], "next" => null]);
        }

        $query = trim(mb_substr((string) ($this->queryParam("q") ?? ""), 0, 100));
        $pos   = preg_replace("%[^A-Za-z0-9._:=+/-]%", "", mb_substr((string) ($this->queryParam("pos") ?? ""), 0, 200));
        $lang  = explode("_", getLanguage())[0];
        $locale = ["ru" => "ru_RU", "uk" => "uk_UA", "by" => "be_BY", "kk" => "kk_KZ"][$lang] ?? "en_US";

        $result = (new GifSearch())->search($query, $pos, $locale, $this->maxDocSize());
        if (is_null($result)) {
            header("HTTP/1.1 502 Bad Gateway");
            $this->returnJson(["items" => [], "next" => null, "error" => tr("gif_search_error")]);
        }

        $this->returnJson($result);
    }

    # Гифка из поиска: скачиваем к себе скрытым документом и отдаём как вложение
    public function renderGifImport(): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction(true);

        if ($_SERVER["REQUEST_METHOD"] !== "POST" || !GifSearch::isEnabled()) {
            header("HTTP/1.1 400 Bad Request");
            $this->returnJson(["error" => tr("gif_import_error")]);
        }

        $id    = preg_replace("%[^A-Za-z0-9_-]%", "", mb_substr((string) ($this->postParam("id") ?? ""), 0, 64));
        $url   = (string) ($this->postParam("url") ?? "");
        $title = trim(mb_substr((string) ($this->postParam("title") ?? ""), 0, 255)) ?: "GIF";
        if ($id === "") {
            header("HTTP/1.1 400 Bad Request");
            $this->returnJson(["error" => tr("gif_import_error")]);
        }

        // эту гифку уже отправляли - не качаем второй раз
        $original_name = "gif_$id.gif";
        $doc = (new Documents())->getImportedGif($this->user->id, $original_name);
        if (!$doc) {
            $maxSize = $this->maxDocSize();
            $tmp = (new GifSearch())->download($url, $maxSize);
            if (is_null($tmp)) {
                header("HTTP/1.1 400 Bad Request");
                $this->returnJson(["error" => tr("gif_import_error")]);
            }

            try {
                $doc = new Document();
                $doc->setOwner($this->user->id);
                $doc->setName(ovk_proc_strtr($title, 255));
                $doc->setFolder_id(Document::VKAPI_FOLDER_PRIVATE);
                $doc->setTags(null);
                $doc->setOwner_hidden(true);
                // в списке документов такие гифки не показываем
                $doc->setUnlisted(1);
                $doc->setFile([
                    "tmp_name" => $tmp,
                    "error"    => UPLOAD_ERR_OK,
                    "name"     => $original_name,
                    "size"     => filesize($tmp),
                    "preview_owner" => $this->user->id,
                    "imported" => true,
                ]);
                $doc->save();
            } catch (\Throwable $e) {
                @unlink($tmp);
                header("HTTP/1.1 400 Bad Request");
                $this->returnJson(["error" => tr("gif_import_error")]);
            }
        }

        $this->returnJson([
            "attachment" => $doc->getPrettiestId(),
            "name"       => $doc->getName(),
        ]);
    }

    private function maxDocSize(): int
    {
        return (int) OPENVK_ROOT_CONF["openvk"]["preferences"]["docs"]["maxSize"] * 1024 * 1024;
    }
}
