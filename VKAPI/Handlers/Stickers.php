<?php

declare(strict_types=1);

namespace openvk\VKAPI\Handlers;

use openvk\Web\Models\Repositories\Stickers as StickersRepo;

final class Stickers extends VKAPIRequestHandler
{
    # установленные паки текущего пользователя, формат как в upstream openvk
    public function get(int $count = 50, int $offset = 0): object
    {
        $this->requireUser();

        $user  = $this->getUser();
        $repo  = new StickersRepo();
        $packs = array_slice(iterator_to_array($repo->getMyPacks($user, 1, 500), false), max(0, $offset), max(0, $count));

        $items = [];
        foreach ($packs as $pack) {
            $data = $pack->toVkApiStruct($user);

            $stickers = [];
            foreach ($pack->getStickers(-1) as $sticker) {
                $stickers[] = $sticker->toVkApiStruct($user, $pack->getId());
            }

            $data["stickers"] = $stickers;
            $items[] = $data;
        }

        return $this->generateItems($repo->getMyPacksCount($user), $items);
    }
}
