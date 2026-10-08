// Панель смайликов как в телеграме: по кнопке .js-emoji-picker открывается окошко
// с вкладками. В "Эмодзи" одной лентой идут наборы - недавние, обычные эмодзи
// (по категориям) и колобки, а снизу полоска наборов для быстрого перехода.
// В "GIF" - гифки из документов пользователя и поиск по GIF-сервису.
// Какие вкладки показать, задаёт data-tabs у кнопки (в чатах - все три),
// без него открываются только эмодзи - так в формах постов и комментариев.
(() => {
    // Список соответствует реальным файлам в Web/static/img/kolobki - сервер
    // (TRichText::formatKolobki) превращает в картинку только :код: с существующим файлом.
    const KOLOBKI = [
        "SHABLON_padonak_01", "SHABLON_padonak_02", "SHABLON_padonak_03", "acute", "aggressive", "agree", "air_kiss", "angel", "bad", "bb",
        "beach", "beee", "big_boss", "biggrin", "black_eye", "blum", "blum2", "blum3", "blush", "blush2", "boast", "bomb", "boredom", "buba",
        "buba_phone", "bye", "clapping", "cray", "cray2", "crazy", "curtsey", "dance", "dance2", "dance3", "dance4", "dash1", "dash2", "dash3",
        "declare", "derisive", "diablo", "dirol", "dntknw", "don-t_mention", "download",
        "drinks", "english_en", "feminist", "feminist_en", "first_move", "flirt", "focus", "fool", "friends", "gamer1", "gamer2", "gamer3",
        "gamer4", "girl_blum", "girl_cray", "girl_cray2", "girl_cray3", "girl_crazy", "girl_dance", "girl_devil", "girl_drink1", "girl_drink3",
        "girl_drink4", "girl_haha", "girl_hide", "girl_hospital", "girl_impossible", "girl_in_love", "girl_mad", "girl_pinkglassesf",
        "girl_prepare_fish", "girl_sad", "girl_sigh", "girl_smile", "girl_to_take_umbrage", "girl_to_take_umbrage2", "girl_wacko", "girl_wink",
        "girl_witch", "give_heart", "give_heart2", "give_rose", "grin", "good", "good2", "good3", "hang1", "hang2", "hang3", "heart", "heat", "help",
        "hi", "hunter", "hysteric", "i-m_so_happy", "ireful1", "ireful2", "ireful3", "king", "kiss", "kiss2", "kiss3", "laugh1", "laugh2",
        "laugh3", "laugh4", "lazy", "lol", "lol2", "mail1", "mamba", "man_in_love", "mda", "mega_shok", "moil", "mosking", "music", "music2",
        "nea", "negative", "new_russian", "ok", "on_the_quiet", "on_the_quiet2", "padonak", "paint2", "paint3", "paratrooper",
        "paratrooper_girl", "pardon", "parting", "party", "party2", "pilot", "pioneer", "pioneer_smoke", "pleasantry", "popcorm1", "popcorm2",
        "prankster2", "preved", "punish", "rofl", "rtfm", "russian_ru", "sad", "sarcastic", "sarcastic_blum", "sarcastic_hand", "scare", "scaut",
        "scaut_en", "scratch_one-s_head", "search", "secret", "sensored", "shok", "shout", "slow", "smile", "smoke", "soldier", "soldier_girl",
        "sorry", "sorry2", "spiteful", "spruce_up", "stinker", "suicide2", "sun_bespectacled", "superstition", "swoon", "tease", "tender",
        "thank_you2", "this", "to_babruysk", "to_become_senile", "to_pick_ones_nose", "to_pick_ones_nose2", "to_take_umbrage", "training1",
        "treaten", "umnik2", "unknw", "vampire", "vava", "victory", "wacko", "wacko2", "whistle3", "wink", "wink2", "wink3", "wizard", "yahoo",
        "yes", "yes3", "yess", "yu",
    ];

    const CATEGORY_ICONS = {
        smileys:  "😀",
        animals:  "🐻",
        food:     "🍔",
        activity: "⚽",
        travel:   "🚗",
        objects:  "💡",
        symbols:  "❤️",
        flags:    "🏁",
    };

    const RECENT_KEY = "ux.emoji_recent";
    const RECENT_MAX = 24;
    const TAB_KEY    = "ux.emoji_tab";
    const TABS       = ["emoji", "stickers", "gifs"];

    const KOLOBOK_RE = /^:([A-Za-z0-9_-]+):$/;

    // то же имя файла, что строит TRichText::formatEmojis
    const twemojiUrl = emoji => {
        let points = [...emoji].map(c => c.codePointAt(0));
        if(!points.includes(0x200D)) points = points.filter(p => p !== 0xFE0F);

        return `https://abs.twimg.com/emoji/v2/72x72/${points.map(p => p.toString(16)).join("-")}.png`;
    };

    const kolobokUrl = code => `/assets/packages/static/openvk/img/kolobki/${code}.gif`;

    const emojiSets = () => Object.keys(CATEGORY_ICONS).map(cat => ({
        id: cat,
        items: (window.EMOJI_GROUPS?.[cat] ?? "").split(" ").filter(Boolean),
    }));

    let known = null;
    const isKnown = value => {
        if(!known) known = new Set([...emojiSets().flatMap(s => s.items), ...KOLOBKI.map(k => `:${k}:`)]);

        return known.has(value);
    };

    const getRecent = () => {
        try {
            const list = JSON.parse(localStorage.getItem(RECENT_KEY) ?? "[]");
            return Array.isArray(list) ? list.filter(isKnown).slice(0, RECENT_MAX) : [];
        } catch(e) {
            return [];
        }
    };

    const pushRecent = value => {
        const list = [value, ...getRecent().filter(v => v !== value)].slice(0, RECENT_MAX);
        try {
            localStorage.setItem(RECENT_KEY, JSON.stringify(list));
        } catch(e) {}
    };

    // картинки грузятся, только когда до них доскроллили (эмодзи почти две тысячи)
    const itemHTML = value => {
        const kolobok = value.match(KOLOBOK_RE);
        if(kolobok)
            return `<a class="emoji-picker--item kolobok" data-value="${value}" title="${value}"><img data-src="${kolobokUrl(kolobok[1])}" alt="${value}" /></a>`;

        return `<a class="emoji-picker--item" data-value="${value}"><img data-src="${twemojiUrl(value)}" alt="${value}" /></a>`;
    };

    const sectionHTML = (id, title, items) => `
        <div class="emoji-picker--section" data-section="${id}">
            <div class="emoji-picker--title">${escapeHtml(title)}</div>
            <div class="emoji-picker--grid">${items.map(itemHTML).join("")}</div>
        </div>
    `;

    let panel = null, body = null, observer = null;
    let target = null, current = null;

    const lazyObserver = root => {
        const io = new IntersectionObserver(entries => entries.forEach(entry => {
            if(!entry.isIntersecting) return;

            const img = entry.target;
            img.src = img.dataset.src;
            img.removeAttribute("data-src");
            io.unobserve(img);
        }), { root, rootMargin: "150px 0px" });

        return io;
    };

    const observeImages = root => root.querySelectorAll("img[data-src]").forEach(img => observer.observe(img));

    const updateActiveSet = () => {
        if(body.closest(".emoji-picker--pane").hidden) return;

        const top = body.scrollTop + 10;
        let current = null;
        body.querySelectorAll(".emoji-picker--section").forEach(section => {
            if(section.hidden) return;
            if(!current || section.offsetTop <= top) current = section;
        });
        if(!current) return;

        const id = current.dataset.section;
        panel.querySelectorAll(".emoji-picker--sets a").forEach(a => a.classList.toggle("active", a.dataset.section === id));
        panel.querySelector(".emoji-picker--set").classList.toggle("active", id in CATEGORY_ICONS);
    };

    const renderRecent = () => {
        const recent  = getRecent();
        const section = panel.querySelector('.emoji-picker--section[data-section="recent"]');
        const grid    = section.querySelector(".emoji-picker--grid");

        grid.innerHTML = recent.map(itemHTML).join("");
        section.hidden = recent.length === 0;
        panel.querySelector('.emoji-picker--sets a[data-section="recent"]').hidden = recent.length === 0;
        observeImages(grid);
    };

    // Лента GIF: без запроса - свои гифки из документов, за ними популярные с сервиса;
    // с запросом - результаты поиска. Страницы догружаются при прокрутке.
    const gifs = {
        pane: null, body: null, observer: null,
        feed: [], query: null, token: 0, loading: false,
        data: new WeakMap(),

        fetchPage: async url => {
            const res = await fetch(url);
            if(!res.ok) throw new Error(res.status);

            return res.json();
        },

        source(id, title, url) {
            const section = document.createElement("div");
            section.className = "emoji-picker--section";
            section.innerHTML = `
                ${title ? `<div class="emoji-picker--title">${escapeHtml(title)}</div>` : ""}
                <div class="emoji-picker--gifs"><div></div><div></div></div>
            `;
            this.body.append(section);

            return { id, section, url, cols: [...section.querySelectorAll(".emoji-picker--gifs > div")], heights: [0, 0], next: null, done: false, count: 0 };
        },

        reset(query) {
            this.token++;
            this.query   = query;
            this.loading = false;
            this.body.innerHTML = "";
            this.body.scrollTop = 0;

            const search = next => `/docs/gifs/search.json?q=${encodeURIComponent(query)}&pos=${encodeURIComponent(next ?? "")}`;
            this.feed = query !== "" ? [this.source("search", "", search)] : [
                this.source("mine", tr("gifs_mine"), next => `/docs/gifs.json?offset=${next ?? 0}`),
                ...(window.openvk.gif_search ? [this.source("trending", tr("gifs_trending"), search)] : []),
            ];

            this.fill();
        },

        search(query) {
            query = query.trim();
            if(query !== this.query) this.reset(query);
        },

        // догружаем, пока лента не дотянулась до низа окошка - иначе прокрутки не будет
        fill() {
            if(this.pane.hidden || !this.pane.isConnected || this.loading) return;
            if(this.body.scrollHeight - this.body.scrollTop - this.body.clientHeight > 300) return;

            this.loadMore();
        },

        async loadMore() {
            const src = this.feed.find(s => !s.done);
            if(!src) return;

            this.loading = true;
            const token = this.token;
            let page = null;
            try {
                page = await this.fetchPage(src.url(src.next));
            } catch(e) {}

            // пока ждали ответ, запрос поменяли
            if(token !== this.token) return;
            this.loading = false;

            if(!page) {
                src.done = true;
                this.message(src, escapeHtml(tr("gif_search_error")));
            } else {
                page.items.forEach(item => this.add(src, item));
                src.count += page.items.length;
                src.next   = page.next ?? null;
                src.done   = src.next === null;

                if(src.done && src.count === 0) {
                    this.message(src, src.id === "mine"
                        ? `${escapeHtml(tr("gifs_mine_empty"))} <a class="emoji-picker--upload">${escapeHtml(tr("gifs_upload"))}</a>`
                        : escapeHtml(tr("gifs_not_found")));
                }
            }

            this.fill();
        },

        add(src, item) {
            const w = item.width || 1, h = item.height || 1;
            const col = src.heights[0] <= src.heights[1] ? 0 : 1;
            src.heights[col] += h / w;

            const el = document.createElement("a");
            el.className = "emoji-picker--gif";
            el.style.aspectRatio = `${w} / ${h}`;
            el.title = item.title ?? item.name ?? "";

            const img = document.createElement("img");
            img.dataset.src = item.preview ?? item.url;
            img.alt = "";

            el.append(img);
            src.cols[col].append(el);
            this.data.set(el, item);
            this.observer.observe(img);
        },

        message(src, html) {
            const msg = document.createElement("div");
            msg.className = "emoji-picker--empty";
            msg.innerHTML = html;
            src.section.append(msg);
        },

        // своя гифка уже документ, гифку из поиска сервер сначала сохранит себе
        async pick(el) {
            const item = this.data.get(el);
            const form = target?.closest("form");
            if(!item || !form || el.classList.contains("loading")) return;

            const formU = u(form);
            if(formU.find(".upload-item").length >= window.openvk.max_attachments) {
                fastError(tr("too_many_attachments"));
                return;
            }

            let { attachment, name } = item;
            if(!attachment) {
                el.classList.add("loading");
                try {
                    const fd = new FormData();
                    fd.append("hash", window.router.csrf);
                    fd.append("id", item.id);
                    fd.append("url", item.url);
                    fd.append("title", item.title);

                    const res  = await fetch("/docs/gifs/import.json", { method: "POST", body: fd });
                    const json = await res.json().catch(() => ({}));
                    if(!res.ok || !json.attachment) throw new Error(json.error ?? json.flash?.message ?? tr("gif_import_error"));

                    ({ attachment, name } = json);
                } catch(err) {
                    fastError(escapeHtml(err.message));
                    return;
                } finally {
                    el.classList.remove("loading");
                }
            }

            if(formU.find(`.upload-item[data-type='doc'][data-id='${attachment}']`).length === 0)
                appendDocAttachment(formU, attachment, name);

            current?.hide();
            // на стене форма с вложениями раскрывается только по фокусу
            target.focus({ preventScroll: true });
        },

        init(pane) {
            this.pane = pane;
            this.body = pane.querySelector(".emoji-picker--gif-body");
            this.observer = lazyObserver(this.body);
            this.body.addEventListener("scroll", () => requestAnimationFrame(() => this.fill()), { passive: true });

            const input = pane.querySelector(".emoji-picker--gif-search input");
            if(input) {
                let timer = null;
                // с паузой, чтобы не тратить лимит запросов к сервису на каждую букву
                input.addEventListener("input", () => {
                    clearTimeout(timer);
                    timer = setTimeout(() => this.search(input.value), 600);
                });
                input.addEventListener("keydown", e => {
                    if(e.key !== "Enter") return;

                    e.preventDefault();
                    clearTimeout(timer);
                    this.search(input.value);
                });
            }

            pane.addEventListener("click", e => {
                const gif = e.target.closest(".emoji-picker--gif");
                if(gif) return this.pick(gif);

                if(e.target.closest(".emoji-picker--upload")) {
                    current?.hide();
                    showDocumentUploadDialog("search", NaN, () => this.query = null);
                }
            });
        },

        // ленту строим при первом открытии вкладки, после загрузки GIF в документы - заново
        open() {
            if(this.query === null) this.reset("");
            else this.fill();
        },
    };

    const setTab = tab => {
        if(!TABS.includes(tab)) tab = "emoji";

        panel.querySelectorAll(".emoji-picker--tabs a").forEach(a => a.classList.toggle("active", a.dataset.tab === tab));
        panel.querySelectorAll(".emoji-picker--pane").forEach(pane => pane.hidden = pane.dataset.pane !== tab);

        if(tab === "emoji") updateActiveSet();
        if(tab === "gifs" && panel.isConnected) gifs.open();
    };

    const activeTab = () => panel.querySelector(".emoji-picker--tabs a.active")?.dataset.tab;

    const buildPanel = () => {
        const sets = emojiSets();

        panel = document.createElement("div");
        panel.className = "emoji-picker";
        panel.innerHTML = `
            <div class="emoji-picker--tabs">
                ${TABS.map(tab => `<a data-tab="${tab}">${escapeHtml(tr(`emoji_picker_tab_${tab}`))}</a>`).join("")}
            </div>
            <div class="emoji-picker--pane" data-pane="emoji">
                <div class="emoji-picker--body">
                    ${sectionHTML("recent", tr("emoji_picker_recent"), [])}
                    ${sets.map(s => sectionHTML(s.id, tr(`emoji_category_${s.id}`), s.items)).join("")}
                    ${sectionHTML("kolobki", tr("emoji_picker_kolobki"), KOLOBKI.map(k => `:${k}:`))}
                </div>
                <div class="emoji-picker--sets">
                    <a data-section="recent" title="${escapeHtml(tr("emoji_picker_recent"))}"><img src="${twemojiUrl("🕓")}" alt="" /></a>
                    <div class="emoji-picker--set">
                        ${sets.map(s => `<a data-section="${s.id}" title="${escapeHtml(tr(`emoji_category_${s.id}`))}"><img src="${twemojiUrl(CATEGORY_ICONS[s.id])}" alt="" /></a>`).join("")}
                    </div>
                    <a data-section="kolobki" title="${escapeHtml(tr("emoji_picker_kolobki"))}"><img src="${kolobokUrl("smile")}" alt="" /></a>
                </div>
            </div>
            <div class="emoji-picker--pane" data-pane="stickers">
                <div class="emoji-picker--soon">${escapeHtml(tr("emoji_picker_stickers_soon"))}</div>
            </div>
            <div class="emoji-picker--pane" data-pane="gifs">
                ${window.openvk.gif_search ? `
                    <div class="emoji-picker--gif-search">
                        <input type="search" maxlength="100" placeholder="${escapeHtml(tr("gifs_search_placeholder", window.openvk.gif_search))}" />
                    </div>
                ` : ""}
                <div class="emoji-picker--gif-body"></div>
            </div>
        `;

        body = panel.querySelector(".emoji-picker--body");
        observer = lazyObserver(body);
        observeImages(body);
        gifs.init(panel.querySelector('.emoji-picker--pane[data-pane="gifs"]'));

        body.addEventListener("scroll", () => requestAnimationFrame(updateActiveSet), { passive: true });

        // не отбираем фокус у поля ввода, чтобы курсор оставался на месте
        panel.addEventListener("mousedown", e => {
            if(e.target.closest(".emoji-picker--item, .emoji-picker--sets a, .emoji-picker--tabs a, .emoji-picker--gif")) e.preventDefault();
        });

        panel.addEventListener("click", e => {
            const tabLink = e.target.closest(".emoji-picker--tabs a");
            if(tabLink) {
                setTab(tabLink.dataset.tab);
                try {
                    localStorage.setItem(TAB_KEY, tabLink.dataset.tab);
                } catch(e) {}

                return;
            }

            const tab = e.target.closest(".emoji-picker--sets a");
            if(tab) {
                const section = panel.querySelector(`.emoji-picker--section[data-section="${tab.dataset.section}"]`);
                body.scrollTop = section.offsetTop;
                updateActiveSet();
                return;
            }

            const item = e.target.closest(".emoji-picker--item");
            if(item && target) {
                insertSmile(target, item.dataset.value);
                pushRecent(item.dataset.value);
            }
        });
    };

    const insertSmile = (textArea, value) => {
        const start = textArea.selectionStart ?? textArea.value.length;
        const end   = textArea.selectionEnd ?? start;

        // :код: вплотную к слову может склеиться с соседним двоеточием
        if(KOLOBOK_RE.test(value) && start > 0 && !/\s/.test(textArea.value[start - 1]))
            value = " " + value;

        textArea.setRangeText(value, start, end, "end");
        if(document.activeElement !== textArea)
            textArea.focus({ preventScroll: true });

        // В мессенджере/беседах поле привязано через knockout - без input/change
        // value-биндинг не подхватит новое содержимое.
        textArea.dispatchEvent(new Event("input", { bubbles: true }));
        textArea.dispatchEvent(new Event("change", { bubbles: true }));
    };

    const findTextArea = button => {
        const form = button.closest("form");
        if(!form) return null;

        return form.querySelector("textarea[name='text'], textarea[name='message']") ?? form.querySelector("textarea");
    };

    const onKeyDown = e => {
        if(e.key === "Escape") document.querySelectorAll(".js-emoji-picker").forEach(b => b._tippy?.hide());
    };

    u(document).on("mousedown", ".js-emoji-picker", e => e.preventDefault());

    tippy.delegate(document.body, {
        target: ".js-emoji-picker",
        theme: "vk emoji",
        placement: "top-end",
        content: "",
        allowHTML: true,
        interactive: true,
        trigger: "click",
        arrow: true,
        // окошко чуть заходит за кнопку справа, чтобы стрелка не упиралась в угол,
        // и висит повыше, чтобы стрелка целиком помещалась над полем ввода
        offset: [4, 16],
        maxWidth: "none",
        appendTo: () => document.body,

        onShow(tip) {
            target = findTextArea(tip.reference);
            if(!target) return false;

            current = tip;
            if(!panel) buildPanel();
            renderRecent();

            const tabs = (tip.reference.dataset.tabs ?? "").split(" ").filter(tab => TABS.includes(tab));
            if(!tabs.includes("emoji")) tabs.unshift("emoji");

            panel.querySelectorAll(".emoji-picker--tabs a").forEach(a => a.hidden = !tabs.includes(a.dataset.tab));
            panel.querySelector(".emoji-picker--tabs").hidden = tabs.length < 2;

            // открываем вкладку, выбранную в прошлый раз, если она есть у этой кнопки
            let tab = null;
            try {
                tab = localStorage.getItem(TAB_KEY);
            } catch(e) {}
            setTab(tabs.includes(tab) ? tab : "emoji");

            // панель одна на все кнопки: перекладываем её в окошко нажатой. Сам узел
            // в setContent не отдаём - повторный тот же узел tippy считает "без изменений"
            // и не вставляет, если панель тем временем побывала у другой кнопки
            const wrap = document.createElement("div");
            wrap.append(panel);
            tip.setContent(wrap);
            document.addEventListener("keydown", onKeyDown);
        },

        onMount() {
            updateActiveSet();
            if(activeTab() === "gifs") gifs.open();
        },

        onHide() {
            document.removeEventListener("keydown", onKeyDown);
        },
    });
})();
