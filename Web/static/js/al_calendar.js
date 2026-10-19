// Календарь выбора дня — порт компонента openvk (messages/components/calendar.js) без preact.
// Названия месяцев и дней недели берутся из Intl, а loadMonth может погасить дни без событий
// и ограничить листание месяцами, в которых что-то было.
class CalendarComponent {
    // minDate/maxDate ограничивают выбор и листание; без maxDate будущие дни погашены
    constructor({ initialDate = null, locale = undefined, loadMonth = null, onSelectDate = null, minDate = null, maxDate = null } = {}) {
        const init = CalendarComponent.parseDate(initialDate) ?? new Date();
        const min  = CalendarComponent.parseDate(minDate);
        const max  = CalendarComponent.parseDate(maxDate);

        this.locale       = locale;
        this.loadMonth    = loadMonth;
        this.onSelectDate = onSelectDate ?? Function.noop;
        this.currentYear  = init.getFullYear();
        this.currentMonth = init.getMonth();
        this.selected     = new Date(init.getFullYear(), init.getMonth(), init.getDate());
        this.today        = new Date();
        this.minDate      = min ? new Date(min.getFullYear(), min.getMonth(), min.getDate()) : null;
        this.maxDate      = max ? new Date(max.getFullYear(), max.getMonth(), max.getDate()) : this.today;
        this.minKey       = min ? min.getFullYear() * 12 + min.getMonth() : null; // year * 12 + month, раньше которого листать некуда
        this.maxKey       = max ? max.getFullYear() * 12 + max.getMonth() : null;
        this.days         = null; // Set дней текущего месяца с событиями, null — неизвестно, доступны все
        this.loading      = false;
        this.container    = null;
    }

    static parseDate(value) {
        if(typeof value === "number") {
            return new Date(value > 1e11 ? value : value * 1000);
        } else if(typeof value === "string") {
            const ddmmyyyy = value.trim().match(/^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$/);
            const parsed   = ddmmyyyy ? new Date(Number(ddmmyyyy[3]), Number(ddmmyyyy[2]) - 1, Number(ddmmyyyy[1])) : new Date(value);

            return isNaN(parsed.getTime()) ? null : parsed;
        } else if(value instanceof Date && !isNaN(value.getTime())) {
            return value;
        }

        return null;
    }

    static isSameDay(a, b) {
        return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    format(date, options) {
        let text;
        try {
            text = date.toLocaleDateString(this.locale, options);
        } catch(e) {
            text = date.toLocaleDateString(undefined, options);
        }

        return text.charAt(0).toUpperCase() + text.slice(1);
    }

    setMonth(year, month) {
        let key = year * 12 + month;
        if(this.minKey !== null) key = Math.max(key, this.minKey);
        if(this.maxKey !== null) key = Math.min(key, this.maxKey);

        this.currentYear  = Math.floor(key / 12);
        this.currentMonth = key % 12;
        this.refresh();
    }

    async refresh() {
        this.days    = null;
        this.loading = !!this.loadMonth;
        this.update();
        if(!this.loadMonth) return;

        const request = this.request = {};
        let result = null;
        try {
            result = await this.loadMonth(this.currentYear, this.currentMonth);
        } catch(e) {
            console.error("Calendar month loading failed", e);
        }

        if(request !== this.request) return;

        this.loading = false;
        if(result) {
            this.days   = new Set(result.days ?? []);
            this.minKey = result.min ? result.min.getFullYear() * 12 + result.min.getMonth() : null;
            this.maxKey = result.max ? result.max.getFullYear() * 12 + result.max.getMonth() : null;

            // открыли на месяце, где событий быть не может, — переходим к ближайшему
            const key = this.currentYear * 12 + this.currentMonth;
            if((this.minKey !== null && key < this.minKey) || (this.maxKey !== null && key > this.maxKey)) {
                return this.setMonth(this.currentYear, this.currentMonth);
            }
        }

        this.update();
    }

    selectDay(day) {
        const date = new Date(this.currentYear, this.currentMonth, day, 0, 0, 0);
        this.onSelectDate(date, {
            year:      this.currentYear,
            month:     this.currentMonth,
            day:       day,
            timestamp: Math.floor(date.getTime() / 1000),
        });
    }

    render(container) {
        this.container = container;

        container.addEventListener("click", e => {
            const nav = e.target.closest(".ovk-cal-nav-btn");
            if(nav) {
                if(!nav.disabled) this.setMonth(this.currentYear, this.currentMonth + Number(nav.dataset.nav));
                return;
            }

            const day = e.target.closest(".ovk-cal-day[data-day]");
            if(day) this.selectDay(Number(day.dataset.day));
        });

        container.addEventListener("change", e => {
            if(e.target.name === "month") this.setMonth(this.currentYear, Number(e.target.value));
            if(e.target.name === "year") this.setMonth(Number(e.target.value), this.currentMonth);
        });

        this.refresh();
    }

    update() {
        if(!this.container) return;

        const key      = this.currentYear * 12 + this.currentMonth;
        const minKey   = this.minKey ?? -Infinity;
        const maxKey   = this.maxKey ?? Infinity;
        const months   = Array.from({ length: 12 }, (_, i) => this.format(new Date(2000, i, 1), { month: "long" }));
        const weekdays = Array.from({ length: 7 }, (_, i) => this.format(new Date(2024, 0, 1 + i), { weekday: "short" })); // 1 января 2024 — понедельник

        // 11.11.2019 овк запустили
        const fromYear = this.minKey !== null ? Math.floor(this.minKey / 12) : Math.min(2019, this.currentYear);
        const toYear   = this.maxKey !== null ? Math.floor(this.maxKey / 12) : Math.max(this.today.getFullYear(), this.currentYear);
        const years    = [];
        for(let y = fromYear; y <= toYear; y++) years.push(y);

        const offset      = (new Date(this.currentYear, this.currentMonth, 1).getDay() + 6) % 7;
        const daysInMonth = new Date(this.currentYear, this.currentMonth + 1, 0).getDate();
        const daysInPrev  = new Date(this.currentYear, this.currentMonth, 0).getDate();
        const tail        = (7 - (offset + daysInMonth) % 7) % 7;

        let cells = "";
        for(let i = offset; i > 0; i--) {
            cells += `<div class="ovk-cal-day ovk-cal-day-other">${ daysInPrev - i + 1 }</div>`;
        }

        for(let d = 1; d <= daysInMonth; d++) {
            const date    = new Date(this.currentYear, this.currentMonth, d);
            const enabled = !this.loading && date <= this.maxDate && (!this.minDate || date >= this.minDate) && (this.days === null || this.days.has(d));
            const classes = [
                "ovk-cal-day",
                enabled ? (this.days ? "ovk-cal-day-active" : "") : "ovk-cal-day-empty",
                CalendarComponent.isSameDay(date, this.today) ? "ovk-cal-day-today" : "",
                CalendarComponent.isSameDay(date, this.selected) ? "ovk-cal-day-selected" : "",
            ].filter(Boolean).join(" ");

            cells += `<div class="${ classes }" ${ enabled ? `data-day="${ d }"` : "" }>${ d }</div>`;
        }

        for(let d = 1; d <= tail; d++) {
            cells += `<div class="ovk-cal-day ovk-cal-day-other">${ d }</div>`;
        }

        this.container.innerHTML = `
            <div class="ovk-calendar-widget">
                <div class="ovk-cal-header">
                    <button type="button" class="ovk-cal-nav-btn" data-nav="-1" ${ key <= minKey ? "disabled" : "" }>‹</button>
                    <div class="ovk-cal-selects">
                        <select name="month">
                            ${ months.map((name, i) => {
                                const k = this.currentYear * 12 + i;
                                return `<option value="${ i }" ${ i === this.currentMonth ? "selected" : "" } ${ k < minKey || k > maxKey ? "disabled" : "" }>${ escapeHtml(name) }</option>`;
                            }).join("") }
                        </select>
                        <select name="year">
                            ${ years.map(y => `<option value="${ y }" ${ y === this.currentYear ? "selected" : "" }>${ y }</option>`).join("") }
                        </select>
                    </div>
                    <button type="button" class="ovk-cal-nav-btn" data-nav="1" ${ key >= maxKey ? "disabled" : "" }>›</button>
                </div>
                <div class="ovk-cal-grid ${ this.loading ? "loading" : "" }">
                    ${ weekdays.map(name => `<div class="ovk-cal-weekday">${ escapeHtml(name) }</div>`).join("") }
                    ${ cells }
                </div>
            </div>
        `;
    }
}

// календарь в модальном окне; выбор дня закрывает окно
function openCalendarModal({ title = tr("messenger_go_to_date"), initialDate = null, locale = undefined, loadMonth = null, onSelectDate = null, minDate = null, maxDate = null } = {}) {
    const modal = new CMessageBox({
        title:     escapeHtml(title),
        body:      `<div class="ovk-calendar-modal-body"></div>`,
        buttons:   [tr("cancel")],
        callbacks: [Function.noop],
    });
    modal.getNode().addClass("ovk-calendar-dialog");

    const calendar = new CalendarComponent({
        initialDate,
        locale,
        loadMonth,
        minDate,
        maxDate,
        onSelectDate: (date, meta) => {
            modal.close();
            if(onSelectDate) onSelectDate(date, meta);
        },
    });
    calendar.render(modal.getNode().find(".ovk-calendar-modal-body").first());

    return modal;
}

window.CalendarComponent = CalendarComponent;
window.openCalendarModal = openCalendarModal;
