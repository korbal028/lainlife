<?php

declare(strict_types=1);

namespace openvk\Web\Util;

/**
 * Поиск GIF для вкладки GIF в панели смайликов.
 *
 * Говорит на API в формате Tenor v2 (/v2/search, /v2/featured). Сам Tenor
 * отключили 30.06.2026, но GIPHY (https://api.giphy.com) и KLIPY
 * (https://api.klipy.com) отвечают в том же формате, так что сервис
 * выбирается адресом и ключом в credentials.gifs.
 */
class GifSearch
{
    public const PER_PAGE = 24;

    private array $conf;

    public function __construct()
    {
        $this->conf = OPENVK_ROOT_CONF["openvk"]["credentials"]["gifs"] ?? [];
    }

    public static function isEnabled(): bool
    {
        $conf = OPENVK_ROOT_CONF["openvk"]["credentials"]["gifs"] ?? [];

        return ($conf["enable"] ?? false) && !empty($conf["key"]);
    }

    # Для подписи "Поиск в ..." - GIPHY и KLIPY требуют показывать, чей это поиск
    public static function getProviderName(): ?string
    {
        if (!self::isEnabled()) {
            return null;
        }

        $host = (string) parse_url(OPENVK_ROOT_CONF["openvk"]["credentials"]["gifs"]["api"] ?? "https://api.giphy.com", PHP_URL_HOST);
        foreach (["giphy" => "GIPHY", "klipy" => "KLIPY"] as $needle => $name) {
            if (str_contains($host, $needle)) {
                return $name;
            }
        }

        return $host;
    }

    /**
     * Пустой запрос - популярные гифки. Для каждой отдаётся лёгкое превью для
     * сетки и ссылка на гифку, которая влезает в лимит документов ($maxSize).
     */
    public function search(string $query, string $pos, string $locale, int $maxSize): ?array
    {
        $params = [
            "key"           => $this->conf["key"],
            "client_key"    => "openvk",
            "limit"         => self::PER_PAGE,
            "locale"        => $locale,
            "contentfilter" => $this->conf["contentFilter"] ?? "medium",
            "media_filter"  => "gif,mediumgif,tinygif",
        ];
        if ($query !== "") {
            $params["q"] = $query;
        }
        if ($pos !== "") {
            $params["pos"] = $pos;
        }

        $endpoint = $query === "" ? "featured" : "search";
        $response = $this->cachedFetch(rtrim($this->conf["api"] ?? "https://api.giphy.com", "/") . "/v2/$endpoint?" . http_build_query($params));
        if (is_null($response)) {
            return null;
        }

        $items = [];
        foreach ($response["results"] ?? [] as $result) {
            $formats = $result["media_formats"] ?? [];
            $preview = $formats["tinygif"] ?? $formats["mediumgif"] ?? $formats["gif"] ?? null;

            $full = null;
            foreach (["gif", "mediumgif", "tinygif"] as $name) {
                $format = $formats[$name] ?? null;
                // размер сервис может и не прислать - тогда его проверит download()
                if (!empty($format["url"]) && ($format["size"] ?? 0) <= $maxSize && $this->isAllowedUrl($format["url"])) {
                    $full = $format;
                    break;
                }
            }

            if (empty($preview["url"]) || is_null($full) || !str_starts_with($preview["url"], "https://")) {
                continue;
            }

            $items[] = [
                "id"      => (string) ($result["id"] ?? ""),
                "title"   => (string) (($result["title"] ?? "") ?: ($result["content_description"] ?? "")),
                "preview" => $preview["url"],
                "width"   => (int) ($preview["dims"][0] ?? 0),
                "height"  => (int) ($preview["dims"][1] ?? 0),
                "url"     => $full["url"],
            ];
        }

        $next = (string) ($response["next"] ?? "");

        return [
            "items" => $items,
            "next"  => (sizeof($items) > 0 && $next !== "" && $next !== "0") ? $next : null,
        ];
    }

    /**
     * Скачивает гифку во временный файл. Качаем только по https с хостов
     * сервиса (mediaHosts), иначе через импорт можно было бы дёргать любые
     * адреса с сервера.
     */
    public function download(string $url, int $maxSize): ?string
    {
        if (!$this->isAllowedUrl($url)) {
            return null;
        }

        $ctx = stream_context_create(["http" => [
            "timeout"         => 15,
            "follow_location" => 1,
            "max_redirects"   => 3,
            "user_agent"      => "OpenVK-GIFs/1.0",
        ]]);
        $data = @file_get_contents($url, false, $ctx, 0, $maxSize + 1);
        if ($data === false || strlen($data) > $maxSize || !str_starts_with($data, "GIF8")) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), "ovkgif");
        file_put_contents($tmp, $data);

        return $tmp;
    }

    public function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (($parts["scheme"] ?? "") !== "https" || empty($parts["host"]) || isset($parts["user"]) || isset($parts["port"])) {
            return false;
        }

        $host = strtolower($parts["host"]);
        foreach ($this->conf["mediaHosts"] ?? ["giphy.com", "klipy.com"] as $allowed) {
            if ($host === $allowed || str_ends_with($host, ".$allowed")) {
                return true;
            }
        }

        return false;
    }

    # У тестовых ключей GIPHY/KLIPY лимит ~100 запросов в час на всех, поэтому ответы кешируются
    private function cachedFetch(string $url): ?array
    {
        $ttl  = (int) ($this->conf["cacheTime"] ?? 3600);
        $file = sys_get_temp_dir() . "/openvk-gifs-" . md5($url) . ".json";
        if ($ttl > 0 && is_file($file) && filemtime($file) + $ttl > time()) {
            $cached = json_decode((string) @file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $ctx = stream_context_create(["http" => ["timeout" => 8, "user_agent" => "OpenVK-GIFs/1.0"]]);
        $raw = @file_get_contents($url, false, $ctx);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !isset($data["results"])) {
            return null;
        }

        if ($ttl > 0) {
            @file_put_contents($file, $raw, LOCK_EX);
        }

        return $data;
    }
}
