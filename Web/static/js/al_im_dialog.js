// окно "Отправить сообщение" со страницы пользователя
u(document).on("click", ".js-send-message", (e) => {
    e.preventDefault();

    const data = e.target.closest(".js-send-message").dataset;
    const name = escapeHtml(data.name);

    const msg = new CMessageBox({
        title: `${tr("send_message")}<a class="udlg-close">${tr("close")}</a>`,
        body: `
            <div class="udlg">
                <div class="udlg-left">
                    <a href="${data.url}"><img class="udlg-avatar" src="${data.avatar}" alt="${name}" /></a>
                    <a class="udlg-goto" href="/im?sel=${data.id}">${tr("go_to_dialog")}</a>
                </div>
                <form class="udlg-right" onsubmit="return false;">
                    <a class="udlg-name" href="${data.url}">${name}</a>
                    <div class="udlg-online">${escapeHtml(data.online ?? "")}</div>
                    <textarea class="udlg-textarea" name="message" placeholder="${tr("enter_message")}"></textarea>
                    <div class="post-horizontal"></div>
                    <div class="post-vertical"></div>
                    <div class="udlg-attachments">
                        <a id="__photoAttachment" title="${tr("photo")}"><img src="/assets/packages/static/openvk/img/oxygen-icons/16x16/mimetypes/application-x-egon.png" /></a>
                        <a id="__videoAttachment" title="${tr("video")}"><img src="/assets/packages/static/openvk/img/oxygen-icons/16x16/mimetypes/application-vnd.rn-realmedia.png" /></a>
                        <a id="__audioAttachment" title="${tr("audio")}"><img src="/assets/packages/static/openvk/img/oxygen-icons/16x16/mimetypes/audio-ac3.png" /></a>
                        <a id="__documentAttachment" title="${tr("document")}"><img src="/assets/packages/static/openvk/img/oxygen-icons/16x16/mimetypes/application-octet-stream.png" /></a>
                        <a class="js-emoji-picker" title="${tr("smiles")}"><img src="/assets/packages/static/openvk/img/oxygen-icons/16x16/mimetypes/smiles_icon.png" /></a>
                    </div>
                </form>
            </div>
        `,
        buttons: [tr("close"), tr("send")],
        close_on_buttons: false,
        callbacks: [() => msg.close(), async () => {
            const form = msg.getNode().find(".udlg-right");
            const text = form.find(".udlg-textarea").first().value;
            const attachments = collect_attachments(form);
            if(text.trim() === "" && attachments.length === 0) return;

            const btn = msg.getNode().find(".ovk-diag-action .button").last();
            btn.disabled = true;

            const fd = new FormData();
            fd.set("hash", u("meta[name=csrf]").attr("value"));
            fd.set("content", text);
            if(attachments.length > 0) fd.set("attachments", attachments.join(","));

            try {
                const res = await fetch(`/im/api/messages${data.id}/create.json`, { method: "POST", body: fd });
                if(res.status !== 202) throw new Error(res.status);

                msg.close();
                NewNotification(tr("message_sent"), "", null, Function.noop, 3000);
            } catch(err) {
                btn.disabled = false;
                fastError(tr("messages_error_1"));
            }
        }],
    });

    msg.getNode().addClass("udlg-cont");
    msg.getNode().find(".udlg-close").on("click", () => msg.close());
    msg.getNode().find(".udlg-textarea").first().focus();
});
