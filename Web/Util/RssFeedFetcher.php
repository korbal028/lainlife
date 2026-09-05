<?php

declare(strict_types=1);

namespace openvk\Web\Util;

class RssFeedFetcher
{
    public const MAX_FEEDS_PER_USER = 50;
    public const ITEMS_PER_FEED     = 30;
    public const MAX_MERGED_ITEMS   = 200;

    private static function loadXmlFromUrl(string $feedUrl): ?\SimpleXMLElement
    {
        $opts = [
            "http"  => ["timeout" => 14, "user_agent" => "OpenVK-RSS/1.0"],
            "https" => ["timeout" => 14, "user_agent" => "OpenVK-RSS/1.0"],
        ];
        $ctx  = stream_context_create($opts);
        $data = @file_get_contents($feedUrl, false, $ctx);
        if ($data === false || $data === "") {
            return null;
        }
        \libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($data);
        \libxml_clear_errors();

        return $xml ?: null;
    }

    private static function extractLinkFromRss2Item(\SimpleXMLElement $item): string
    {
        $lnk = \trim((string) $item->link);
        if ($lnk !== "") {
            return $lnk;
        }
        foreach ($item->xpath('.//*[local-name()="link"]') ?: [] as $node) {
            $h = \trim((string) ($node->attributes()->href ?? ""));
            if ($h !== "") {
                return $h;
            }
            $t = \trim((string) $node);
            if ($t !== "") {
                return $t;
            }
        }
        if (isset($item->guid)) {
            $g = \trim((string) $item->guid);
            if ($g !== "" && \filter_var($g, FILTER_VALIDATE_URL)) {
                return $g;
            }
        }
        $desc = (string) ($item->description ?? "");
        if ($desc !== "" && \preg_match('#\bhref\s*=\s*"(https?://[^"]+)"#i', $desc, $m)) {
            return \html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, "UTF-8");
        }

        return "";
    }

    private static function extractLinkFromAtomEntry(\SimpleXMLElement $entry): string
    {
        $nonSelf = "";
        $first   = "";
        foreach ($entry->xpath('.//*[local-name()="link"]') ?: [] as $linkEl) {
            $attrs = $linkEl->attributes();
            $href  = \trim((string) ($attrs["href"] ?? ""));
            if ($href === "") {
                continue;
            }
            $rel = \strtolower((string) ($attrs["rel"] ?? ""));
            if ($rel === "alternate" || $rel === "") {
                return $href;
            }
            if ($rel !== "self" && $nonSelf === "") {
                $nonSelf = $href;
            }
            if ($first === "") {
                $first = $href;
            }
        }
        if ($nonSelf !== "") {
            return $nonSelf;
        }
        if (isset($entry->id)) {
            $id = \trim((string) $entry->id);
            if ($id !== "" && \filter_var($id, FILTER_VALIDATE_URL)) {
                return $id;
            }
        }

        return $first;
    }

    /**
     * @return array{0: string, 1: list<object>}
     */
    private static function extractItemsFromXml(\SimpleXMLElement $xml): array
    {
        $feedTitle = "";
        $out       = [];

        if (isset($xml->channel)) {
            $feedTitle = (string) $xml->channel->title;
            foreach ($xml->channel->item as $item) {
                $lnk = self::extractLinkFromRss2Item($item);
                $out[] = (object) [
                    "title" => (string) $item->title,
                    "link"  => $lnk,
                    "desc"  => (string) ($item->description ?? ""),
                    "date"  => (string) ($item->pubDate ?? ""),
                ];
            }

            return [$feedTitle, $out];
        }

        if (isset($xml->entry)) {
            $feedTitle = (string) ($xml->title ?? "");
            foreach ($xml->entry as $entry) {
                $lnk = self::extractLinkFromAtomEntry($entry);
                $d   = (string) ($entry->summary ?? $entry->content ?? "");
                $out[] = (object) [
                    "title" => (string) $entry->title,
                    "link"  => $lnk,
                    "desc"  => $d,
                    "date"  => (string) ($entry->updated ?? $entry->published ?? ""),
                ];
            }

            return [$feedTitle, $out];
        }

        return ["", []];
    }

    /**
     * @return array{feedTitle: string, items: list<object>}
     */
    public static function fetchFeed(string $url): array
    {
        $xml = self::loadXmlFromUrl($url);
        if (!$xml) {
            return ["feedTitle" => "", "items" => []];
        }
        [$feedTitle, $items] = self::extractItemsFromXml($xml);
        if ($feedTitle === "" && sizeof($items) === 0) {
            return ["feedTitle" => "", "items" => []];
        }

        return [
            "feedTitle" => $feedTitle !== "" ? $feedTitle : $url,
            "items"     => $items,
        ];
    }

    public static function itemSortKey(object $item): int
    {
        $raw = (string) ($item->date ?? "");
        $t   = @\strtotime($raw);

        return $t !== false ? $t : 0;
    }
}
