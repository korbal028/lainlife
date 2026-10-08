// Окно набора стикеров как в телеграме: сетка стикеров и большая кнопка
// "Добавить N стикеров". Открывается из магазина и по клику на стикер в чате.
// После добавления или удаления набора шлёт stickers:changed - панель в чатах перечитает наборы.
(() => {
    const changed = () => document.dispatchEvent(new Event("stickers:changed"));

    const actionLabel = info => {
        if(info.isPurchased) return tr("stickers_uninstall_pack");
        if(info.isBought || info.price <= 0) return tr("stickers_add_n", tr("stickers_count", info.count));

        return tr("stickers_buy_pack", tr("coins", info.price));
    };

    // ставит или убирает набор, отдаёт true при успехе
    const toggle = async info => {
        try {
            if(info.isPurchased) await API.Stickers.uninstallPack(info.id);
            else await API.Stickers.buyPack(info.id);
        } catch(err) {
            fastError(escapeHtml(err?.message ?? tr("error")));
            return false;
        }

        changed();
        return true;
    };

    window.openStickerPack = async slugOrId => {
        let info;
        try {
            info = await API.Stickers.getPackInfo(String(slugOrId));
        } catch(err) {
            fastError(escapeHtml(err?.message ?? tr("error")));
            return;
        }

        const author = info.author_url
            ? `<a href="${escapeHtml(info.author_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(info.author || info.author_url)}</a>`
            : escapeHtml(info.author);

        const msg = new CMessageBox({
            title: escapeHtml(info.name),
            body: `
                <div class="stickers-modal">
                    <div class="stickers-modal--meta">
                        ${escapeHtml(tr("stickers_count", info.count))}${author ? ` · ${author}` : ""}
                        ${info.canEdit ? `<a class="stickers-modal--edit" href="/stickers/edit/${info.id}">${escapeHtml(tr("edit"))}</a>` : ""}
                    </div>
                    ${info.description ? `<div class="stickers-modal--desc">${escapeHtml(info.description)}</div>` : ""}
                    <div class="stickers-modal--grid">
                        ${info.stickers.map(s => `<img src="${escapeHtml(s.url)}" alt="${escapeHtml(s.emoji)}" title="${escapeHtml(s.emoji)}" loading="lazy" />`).join("")}
                    </div>
                </div>
            `,
            buttons: info.isAuthorized ? [escapeHtml(actionLabel(info)), tr("close")] : [tr("close")],
            close_on_buttons: false,
            callbacks: info.isAuthorized ? [async () => {
                const btn = msg.getNode().find(".ovk-diag-action .button").first();
                btn.disabled = true;
                if(await toggle(info)) {
                    msg.close();
                    syncShopRow(info.id, !info.isPurchased);
                } else {
                    btn.disabled = false;
                }
            }, () => msg.close()] : [() => msg.close()],
        });

        msg.getNode().addClass("stickers-modal-box");
        // кнопка действия на всю ширину, как в телеграме
        if(info.isAuthorized)
            msg.getNode().find(".ovk-diag-action .button").first().classList.add(info.isPurchased ? "button_gray" : "stickers-modal--main");
    };

    // строка набора в магазине после добавления/удаления
    const syncShopRow = (packId, installed) => {
        const row = document.querySelector(`.stickers-pack-row[data-id="${packId}"]`);
        const action = row?.querySelector(".stickers-pack-row--action");
        if(!action) return;

        if(row.closest('.stickers-shop[data-act="settings"]') && !installed) {
            row.remove();
            return;
        }

        if(installed) action.innerHTML = `<span class="stickers-pack-row--added">${escapeHtml(tr("stickers_added"))}</span>`;
    };

    u(document).on("click", ".js-sticker-pack", e => {
        e.preventDefault();
        openStickerPack(e.target.closest(".stickers-pack-row").dataset.slug);
    });

    u(document).on("click", ".js-sticker-install, .js-sticker-uninstall", async e => {
        e.preventDefault();
        const btn = e.target.closest(".button");
        const row = btn.closest(".stickers-pack-row");
        if(btn.classList.contains("lagged")) return;

        const install = btn.classList.contains("js-sticker-install");
        btn.classList.add("lagged");
        if(await toggle({ id: Number(row.dataset.id), isPurchased: !install }))
            syncShopRow(row.dataset.id, install);

        btn.classList.remove("lagged");
    });

    // стикер в сообщении открывает свой набор
    u(document).on("click", ".msg-sticker", e => {
        e.preventDefault();
        const pack = e.target.closest(".msg-sticker").dataset.pack;
        if(pack) openStickerPack(pack);
    });
})();
