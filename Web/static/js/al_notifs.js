createjs.Sound.registerSound("/assets/packages/static/openvk/audio/notify.mp3", "notification");

function __actualPlayNotifSound() {
    createjs.Sound.play("notification");
}

window.playNotifSound = Function.noop;

function incrementNotificationsCounter() {
    document.querySelectorAll('a[href="/notifications"]').forEach(link => {

        let counterObject = link.querySelector('object');

        if (!counterObject) {
            counterObject = document.createElement('object');
            counterObject.type = 'internal/link';
            counterObject.innerHTML = ' (<b>1</b>)';
            link.appendChild(counterObject);
        } else {
            counterObject.classList.remove('zero_counter');
            const bTag = counterObject.querySelector('b');
            if (bTag) {
                let currentCount = parseInt(bTag.textContent) || 0;
                bTag.textContent = currentCount + 1;
            } else {
                counterObject.innerHTML = ' (<b>1</b>)';
            }
        }
    });
}

// Если юзер прямо сейчас открыл переписку с тем же отправителем - сообщение он
// и так видит вживую (через лонгпул чата), всплывающий тост тут только дублирует
// то, что уже на экране, и зря дёргает счётчик непрочитанных.
function isViewingChatWith(senderId) {
    if (senderId === null || typeof senderId === "undefined") return false;
    if (location.pathname !== "/im") return false;

    const sel = new URLSearchParams(location.search).get("sel");
    if (sel === null) return false;

    return parseInt(sel, 10) === parseInt(senderId, 10);
}

// На странице списка диалогов (/im без sel, Messenger/Index.latte) список
// рендерится один раз на сервере и сам не обновляется. При входящем сообщении
// находим его чат по data-im-url (совпадает с notif.url = /im?sel=<id>) и
// обновляем вживую: превью, время, пометку "непрочитано", и поднимаем наверх.
// Если такого чата в текущем (возможно, страничном) списке нет - не трогаем,
// счётчик всё равно инкрементится отдельно.
function updateConversationListPreview(notif) {
    const list = document.querySelector('.crp-list');
    if (!list || !notif || !notif.url) return;

    let entry = null;
    for (const e of list.querySelectorAll('.crp-entry')) {
        if (e.getAttribute('data-im-url') === notif.url) { entry = e; break; }
    }
    if (!entry) return;

    // notif.body = "<текст превью><div class='nobold'><время></div>" - разбираем
    const tmp = document.createElement('div');
    tmp.innerHTML = notif.body || '';
    const timeDiv = tmp.querySelector('.nobold');
    let timeStr = '';
    if (timeDiv) { timeStr = timeDiv.textContent; timeDiv.remove(); }
    const previewHtml = tmp.innerHTML;

    const textEl = entry.querySelector('.crp-entry--message---text');
    if (textEl) textEl.innerHTML = previewHtml;

    if (timeStr) {
        const timeEl = entry.querySelector('.crp-entry--info span');
        if (timeEl) timeEl.textContent = timeStr;
    }

    // Входящее сообщение - автор НЕ мы, значит аватарка автора (она показывается
    // только для своих сообщений) должна исчезнуть, а блок стать "непрочитанным".
    const av = entry.querySelector('.crp-entry--message---av');
    if (av) av.remove();
    const msgBlock = entry.querySelector('.crp-entry--message');
    if (msgBlock) msgBlock.classList.add('unread');

    list.prepend(entry);
}

function incrementMessagesCounter() {
    document.querySelectorAll('a[href="/im"]').forEach(link => {

        let counterObject = link.querySelector('object');

        if (!counterObject) {
            counterObject = document.createElement('object');
            counterObject.type = 'internal/link';
            counterObject.innerHTML = ' (<b>1</b>)';
            link.appendChild(counterObject);
        } else {
            counterObject.classList.remove('zero_counter');
            const bTag = counterObject.querySelector('b');
            if (bTag) {
                let currentCount = parseInt(bTag.textContent) || 0;
                bTag.textContent = currentCount + 1;
            } else {
                counterObject.innerHTML = ' (<b>1</b>)';
            }
        }
    });
}

async function setupNotificationListener() {
    console.info("Setting up notifications listener...");
    
    const POLL_INTERVAL = 10000;
    const CHECK_MORE_INTERVAL = 250;
    const ERROR_RETRY_INTERVAL = 60000;
    let isFirstRequest = true;

    while(true) {
        try {
            const notif = await API.Notifications.fetch();
            
            if (notif) {
                if (!isFirstRequest) {
                    const isOwnOpenChat = notif.kind === "message" && isViewingChatWith(notif.senderId);

                    // Список диалогов обновляем всегда (если мы на /im), независимо
                    // от того, подавляется ли тост - он сам no-op, если списка нет.
                    if (notif.kind === "message") {
                        updateConversationListPreview(notif);
                    }

                    if (!isOwnOpenChat) {
                        playNotifSound();
                        console.info("New notification", notif);

                        if (notif.kind === "message") {
                            NewNotification(notif.title, notif.body, notif.ava, function() {
                                window.location.href = notif.url;
                            }, (notif.priority || 1) * 6000);
                            incrementMessagesCounter();
                        } else {
                            NewNotification(notif.title, notif.body, notif.ava, Function.noop, (notif.priority || 1) * 6000);
                            incrementNotificationsCounter();
                        }
                    } else {
                        console.info("New message in currently open chat: skipping toast/sound", notif);
                    }
                } else {
                    console.info("First request: skipping alert (syncing cursor)");
                }
            }
            
            await new Promise(resolve => setTimeout(resolve, CHECK_MORE_INTERVAL));
        } catch(rejection) {
            if (rejection.message === "Nothing to report" || rejection.code === 1983) {
                if (isFirstRequest) {
                    console.info("Cursor synced. Real-time notifications enabled.");
                    isFirstRequest = false; 
                } else {
                    console.info("No new notifications found, sleeping for " + POLL_INTERVAL/1000 + "s...")
                }
                await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL));
            } else if (rejection.message === "Disabled" || rejection.code === 1999) {
                console.error("Real-time notifications are disabled. Aborting RPC polling until next page load", rejection);
                break;
            } else {
                console.error("Poll error, we'll try again in a minute...", rejection);
                await new Promise(resolve => setTimeout(resolve, ERROR_RETRY_INTERVAL));
            }
        }
    }
};

(async function() {
    await setupNotificationListener();
})();

u(document.body).on("click", () => window.playNotifSound = window.__actualPlayNotifSound);
