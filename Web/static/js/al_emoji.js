// Панель смайликов как в телеграме: по кнопке .js-emoji-picker открывается окошко,
// где одной лентой идут наборы - недавние, обычные эмодзи (по категориям) и колобки,
// а снизу полоска наборов для быстрого перехода.
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
    let target = null;

    const observeImages = root => root.querySelectorAll("img[data-src]").forEach(img => observer.observe(img));

    const updateActiveSet = () => {
        const top = body.scrollTop + 10;
        let current = null;
        panel.querySelectorAll(".emoji-picker--section").forEach(section => {
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

    const buildPanel = () => {
        const sets = emojiSets();

        panel = document.createElement("div");
        panel.className = "emoji-picker";
        panel.innerHTML = `
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
        `;

        body = panel.querySelector(".emoji-picker--body");
        observer = new IntersectionObserver(entries => entries.forEach(entry => {
            if(!entry.isIntersecting) return;

            const img = entry.target;
            img.src = img.dataset.src;
            img.removeAttribute("data-src");
            observer.unobserve(img);
        }), { root: body, rootMargin: "150px 0px" });
        observeImages(body);

        body.addEventListener("scroll", () => requestAnimationFrame(updateActiveSet), { passive: true });

        // не отбираем фокус у поля ввода, чтобы курсор оставался на месте
        panel.addEventListener("mousedown", e => {
            if(e.target.closest(".emoji-picker--item, .emoji-picker--sets a")) e.preventDefault();
        });

        panel.addEventListener("click", e => {
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

            if(!panel) buildPanel();
            renderRecent();

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
        },

        onHide() {
            document.removeEventListener("keydown", onKeyDown);
        },
    });
})();
