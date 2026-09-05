<?php

declare(strict_types=1);

namespace openvk\VKAPI\Handlers;

use Chandler\Database\DatabaseConnection;
use openvk\Web\Util\RssFeedFetcher;

final class Rss extends VKAPIRequestHandler
{
    private function subscriptionsTable()
    {
        return DatabaseConnection::i()->getContext()->table("rss_subscriptions");
    }

    private function toApiItem(object $item): object
    {
        return (object) [
            "title"       => $item->title,
            "link"        => $item->link,
            "desc"        => $item->desc,
            "date"        => $item->date,
            "timestamp"   => RssFeedFetcher::itemSortKey($item),
            "source_feed" => $item->sourceFeed ?? "",
            "source_url"  => $item->sourceUrl ?? "",
        ];
    }

    private function toApiSubscription($sub): object
    {
        return (object) [
            "id"      => (int) $sub->id,
            "url"     => (string) $sub->url,
            "title"   => (string) $sub->title,
            "created" => (int) $sub->created,
        ];
    }

    public function getSubscriptions(): object
    {
        $this->requireUser();

        $subs  = $this->subscriptionsTable()->where("user_id", $this->getUser()->getId())->order("id DESC");
        $items = [];
        foreach ($subs as $sub) {
            $items[] = $this->toApiSubscription($sub);
        }

        return (object) [
            "count" => sizeof($items),
            "items" => $items,
        ];
    }

    public function getFeed(string $url = ""): object
    {
        $this->requireUser();

        $url = trim($url);

        if ($url !== "") {
            $parsed = RssFeedFetcher::fetchFeed($url);
            if ($parsed["feedTitle"] === "" && sizeof($parsed["items"]) === 0) {
                $this->fail(1, "Не удалось загрузить RSS");
            }

            $items = [];
            foreach ($parsed["items"] as $it) {
                $it->sourceFeed = $parsed["feedTitle"];
                $it->sourceUrl  = $url;
                $items[]        = $this->toApiItem($it);
            }

            return (object) [
                "merged"     => false,
                "feed_title" => $parsed["feedTitle"],
                "count"      => sizeof($items),
                "items"      => $items,
            ];
        }

        $subscriptions = $this->subscriptionsTable()->where("user_id", $this->getUser()->getId())->order("id DESC");

        $merged = [];
        foreach ($subscriptions as $sub) {
            $parsed = RssFeedFetcher::fetchFeed((string) $sub->url);
            $slice  = array_slice($parsed["items"], 0, RssFeedFetcher::ITEMS_PER_FEED);
            $label  = (string) ($sub->title ?: $parsed["feedTitle"] ?: $sub->url);
            foreach ($slice as $it) {
                $it->sourceFeed = $label;
                $it->sourceUrl  = (string) $sub->url;
                $merged[]       = $it;
            }
        }

        usort($merged, function ($a, $b) {
            return RssFeedFetcher::itemSortKey($b) <=> RssFeedFetcher::itemSortKey($a);
        });
        $merged = array_slice($merged, 0, RssFeedFetcher::MAX_MERGED_ITEMS);

        return (object) [
            "merged"     => true,
            "feed_title" => "",
            "count"      => sizeof($merged),
            "items"      => array_map([$this, "toApiItem"], $merged),
        ];
    }

    public function addFeeds(string $urls): object
    {
        $this->requireUser();
        $this->willExecuteWriteAction();

        $lines   = preg_split('/\R+/u', $urls) ?: [];
        $added   = 0;
        $skipped = 0;

        $count = $this->subscriptionsTable()->where("user_id", $this->getUser()->getId())->count("*");

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }
            if (!filter_var($line, FILTER_VALIDATE_URL)) {
                $skipped++;
                continue;
            }
            if ($count >= RssFeedFetcher::MAX_FEEDS_PER_USER) {
                break;
            }

            $exists = $this->subscriptionsTable()
                ->where("user_id", $this->getUser()->getId())
                ->where("url", $line)
                ->fetch();

            if ($exists) {
                $skipped++;
                continue;
            }

            $parsed = RssFeedFetcher::fetchFeed($line);
            $title  = $parsed["feedTitle"] !== "" ? $parsed["feedTitle"] : $line;
            if ($parsed["feedTitle"] === "" && sizeof($parsed["items"]) === 0) {
                $skipped++;
                continue;
            }

            $this->subscriptionsTable()->insert([
                "user_id" => $this->getUser()->getId(),
                "url"     => $line,
                "title"   => mb_substr($title, 0, 500),
                "created" => time(),
            ]);
            $count++;
            $added++;
        }

        return (object) [
            "added"         => $added,
            "skipped"       => $skipped,
            "subscriptions" => $this->getSubscriptions(),
        ];
    }

    public function removeFeed(int $id): int
    {
        $this->requireUser();
        $this->willExecuteWriteAction();

        $row = $this->subscriptionsTable()
            ->where("user_id", $this->getUser()->getId())
            ->where("id", $id)
            ->fetch();

        if (!$row) {
            return 0;
        }

        $this->subscriptionsTable()->where("id", $id)->delete();

        return 1;
    }
}
