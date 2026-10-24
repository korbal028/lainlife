<?php

declare(strict_types=1);

namespace openvk\Web\Util;

use Chandler\Session\Session;
use Chandler\Database\DatabaseConnection;
use openvk\Web\Models\Entities\User;
use openvk\Web\Models\Repositories\Users;

final class Accounts
{
    public const LIMIT = 10;

    private const KEY = "_accounts";

    public static function getTokens(): array
    {
        $tokens = Session::i()->get(self::KEY);

        return is_array($tokens) ? array_values(array_filter($tokens, "is_string")) : [];
    }

    private static function setTokens(array $tokens): void
    {
        Session::i()->set(self::KEY, array_slice(array_values(array_unique($tokens)), -self::LIMIT));
    }

    public static function current(): ?string
    {
        return Session::i()->get("tok");
    }

    public static function remember(?string $token): void
    {
        if ($token) {
            self::setTokens([...self::getTokens(), $token]);
        }
    }

    public static function forget(?string $token): void
    {
        self::setTokens(array_filter(self::getTokens(), fn($saved) => $saved !== $token));
    }

    public static function getAccounts(): array
    {
        $db       = DatabaseConnection::i()->getContext();
        $users    = new Users();
        $tokens   = self::getTokens();
        $accounts = [];
        $valid    = [];
        foreach ($tokens as $token) {
            $row = $db->table("ChandlerTokens")->where("token", $token)->fetch();
            if (!$row || (CHANDLER_ROOT_CONF["security"]["extendedValidation"] && ($row->ip !== CONNECTING_IP || $row->ua !== $_SERVER["HTTP_USER_AGENT"]))) {
                continue;
            }

            $profileId = $db->table("profiles")->where("user", (string) $row->user)->fetchField("id");
            $user      = $profileId ? $users->get((int) $profileId) : null;
            if (!$user || ($user->isDeleted() && !$user->isDeactivated())) {
                continue;
            }

            $valid[] = $token;
            $accounts[$user->getId()] = ["token" => $token, "user" => $user];
        }

        if (sizeof($valid) !== sizeof($tokens)) {
            self::setTokens($valid);
        }

        return $accounts;
    }

    public static function getOthers(int $currentUserId): array
    {
        return array_values(array_map(fn($account) => $account["user"], array_filter(self::getAccounts(), fn($id) => $id !== $currentUserId, ARRAY_FILTER_USE_KEY)));
    }

    public static function switchTo(int $userId): bool
    {
        $account = self::getAccounts()[$userId] ?? null;
        if (!$account) {
            return false;
        }

        Session::i()->set("tok", $account["token"]);
        Session::i()->set("_su", null);

        return true;
    }

    public static function maskContact(User $user): string
    {
        $email = (string) $user->getEmail();
        if (!str_contains($email, "@")) {
            return "";
        }

        [$local, $domain] = explode("@", $email, 2);

        return mb_substr($local, 0, 2) . "···@" . $domain;
    }
}
