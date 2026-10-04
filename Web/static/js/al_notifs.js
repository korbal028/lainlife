const NOTIF_SOUNDS = {
    bell:      "/assets/packages/static/openvk/audio/notify.mp3",
    skype:     "/assets/packages/static/openvk/audio/skype.mp3",
    icq:       "/assets/packages/static/openvk/audio/icq.mp3",
    lain_msg:  "/assets/packages/static/openvk/audio/lain_new_message.mp3",
    konata:    "/assets/packages/static/openvk/audio/pupue.mp3",
    mambo:     "/assets/packages/static/openvk/audio/mambo.mp3",
    lain_mail: "/assets/packages/static/openvk/audio/lain-mail.mp3",
};

Object.entries(NOTIF_SOUNDS).forEach(([id, url]) => {
    createjs.Sound.registerSound(url, "notification_" + id);
});

function __currentNotifSoundId() {
    const choice = window.openvk?.notification_sound;
    return NOTIF_SOUNDS[choice] ? choice : "bell";
}

function __resumeAudioContext() {
    try {
        const ctx = (createjs.WebAudioPlugin && createjs.WebAudioPlugin.context)
                 || (createjs.Sound.activePlugin && createjs.Sound.activePlugin.context);
        if (ctx && ctx.state === "suspended") {
            ctx.resume();
        }
    } catch (e) {}
}

function __actualPlayNotifSound() {
    __resumeAudioContext();
    createjs.Sound.play("notification_" + __currentNotifSoundId());
}

window.previewNotifSound = function(id) {
    const soundId = NOTIF_SOUNDS[id] ? id : "bell";
    createjs.Sound.play("notification_" + soundId);
};

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

function isViewingChatWith(senderId) {
    if (senderId === null || typeof senderId === "undefined") return false;
    if (location.pathname !== "/im") return false;

    const sel = new URLSearchParams(location.search).get("sel");
    if (sel === null) return false;

    return parseInt(sel, 10) === parseInt(senderId, 10);
}

function updateConversationListPreview(notif) {
    if (!notif || !notif.url) return;

    const tmp = document.createElement('div');
    tmp.innerHTML = notif.body || '';
    const timeDiv = tmp.querySelector('.nobold');
    let timeStr = '';
    if (timeDiv) { timeStr = timeDiv.textContent; timeDiv.remove(); }
    const previewHtml = tmp.innerHTML;

    const findByUrl = (container, selector) => {
        for (const e of container.querySelectorAll(selector)) {
            if (e.getAttribute('data-im-url') === notif.url) return e;
        }
        return null;
    };

    const baseList = document.querySelector('.crp-list');
    if (baseList) {
        const entry = findByUrl(baseList, '.crp-entry');
        if (!entry) return;

        const textEl = entry.querySelector('.crp-entry--message---text');
        if (textEl) textEl.innerHTML = previewHtml;
        if (timeStr) {
            const timeEl = entry.querySelector('.crp-entry--info span');
            if (timeEl) timeEl.textContent = timeStr;
        }
        const av = entry.querySelector('.crp-entry--message---av');
        if (av) av.remove();
        const msgBlock = entry.querySelector('.crp-entry--message');
        if (msgBlock) msgBlock.classList.add('unread');

        baseList.prepend(entry);
        return;
    }

    const vkList = document.querySelector('.im-page--dcontent');
    if (vkList) {
        const entry = findByUrl(vkList, '.nim-dialog');
        if (!entry) return;

        const textEl = entry.querySelector('.nim-dialog--inner-text');
        if (textEl) textEl.innerHTML = previewHtml;
        if (timeStr) {
            const timeEl = entry.querySelector('.nim-dialog--date');
            if (timeEl) timeEl.textContent = timeStr;
        }
        if (notif.ava) {
            const avImg = entry.querySelector('.nim-dialog--who img');
            if (avImg) avImg.src = notif.ava;
        }
        entry.classList.add('nim-dialog--unread');

        vkList.prepend(entry);
        return;
    }
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

(function() {
    function unlockNotifSound() {
        window.playNotifSound = window.__actualPlayNotifSound;
        __resumeAudioContext();
    }
    ["pointerdown", "mousedown", "keydown", "touchstart", "click"].forEach(function(evt) {
        document.addEventListener(evt, unlockNotifSound, { capture: true, passive: true });
    });
})();
