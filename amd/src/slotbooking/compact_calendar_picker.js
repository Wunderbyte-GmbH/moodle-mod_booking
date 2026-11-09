// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The compact slot calendar: shows the slots of a slot booking in the calendar of local_entities
 * (day, week and month view) instead of the month grid and the day timeline of slotCalendarPicker.
 *
 * It answers the same calls condition/slotBooking.js makes on slotCalendarPicker (setSlotFilter,
 * setSelectedKeys, findDayKeyForSlotKey, goToDay), so the summary, the sidebar and the live
 * validation work unchanged. It owns no selection: fixed slots are toggled through the selection
 * adapter it is given, a free time range (user-defined slots) is handed to onCustomRange.
 *
 * Only used with [bookingoptionview ... calendar="compact"], see templates/condition/slotbooking.mustache.
 *
 * @module     mod_booking/slotbooking/compact_calendar_picker
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_strings as getStrings} from 'core/str';
import Config from 'core/config';

// The stylesheet of the calendar library. The unavailability page loads it with $PAGE->requires->css,
// which is too late inside a shortcode (the page head is already printed), so it is added here.
const STYLESHEET = '/local/entities/js/main.css';

// Below this width per shown day the week view gets too narrow to read, so the day view is opened.
const MIN_DAY_COLUMN_PX = 110;
// Width of the time axis on the left of the day and week view.
const TIME_AXIS_PX = 60;
// Entries shorter than these minutes get a smaller text, so the time stays inside them (see styles.css).
const SHORT_ENTRY_MINUTES = {xs: 15, sm: 30};

/**
 * The size class of an entry by its length, for the text size in styles.css.
 *
 * @param {number} start timestamp in seconds
 * @param {number} end timestamp in seconds
 * @returns {string} css class, '' for a normal entry
 */
const sizeClass = (start, end) => {
    const minutes = (end - start) / 60;
    if (minutes < SHORT_ENTRY_MINUTES.xs) {
        return 'booking-slot-compact-xs';
    }
    return minutes < SHORT_ENTRY_MINUTES.sm ? 'booking-slot-compact-sm' : '';
};

/**
 * Adds the stylesheet of the calendar library once per page.
 */
const loadStylesheet = () => {
    if (document.querySelector('link[data-booking-compact-calendar-css]')) {
        return;
    }
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = Config.wwwroot + STYLESHEET;
    link.dataset.bookingCompactCalendarCss = '1';
    document.head.appendChild(link);
};

/**
 * The texts the calendar of local_entities needs, from the same strings its own page uses.
 *
 * @returns {Promise<Object>}
 */
const loadLabels = async() => {
    const [today, week, month, allday, day, showweek, showmonth, more] = await getStrings([
        {key: 'today', component: 'core'},
        {key: 'week', component: 'core'},
        {key: 'month', component: 'core'},
        {key: 'unavailability_allday', component: 'local_entities'},
        {key: 'unavailability_day', component: 'local_entities'},
        {key: 'unavailability_showweek', component: 'local_entities'},
        {key: 'unavailability_showmonth', component: 'local_entities'},
        {key: 'unavailability_more', component: 'local_entities'},
    ]);
    return {today, week, month, allday, day, showweek, showmonth, more};
};

/**
 * A timestamp in seconds of a date.
 *
 * @param {Date} date
 * @returns {number}
 */
const toSeconds = (date) => Math.floor(date.getTime() / 1000);

/**
 * A day key ("YYYY-MM-DD", local time) of a timestamp in seconds.
 *
 * @param {number} seconds
 * @returns {string}
 */
const toDayKey = (seconds) => {
    const date = new Date(Number(seconds) * 1000);
    const pad = (value) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

/**
 * Minutes since local midnight of a timestamp in seconds.
 *
 * @param {number} seconds
 * @returns {number}
 */
const minuteOfDay = (seconds) => {
    const date = new Date(Number(seconds) * 1000);
    return date.getHours() * 60 + date.getMinutes();
};

/**
 * A duration in minutes as "HH:MM:00", the time format of the calendar library.
 *
 * @param {number} minutes
 * @returns {string}
 */
const toTimeOption = (minutes) => {
    const pad = (value) => String(value).padStart(2, '0');
    return `${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}:00`;
};

/**
 * The start of a slot key: "start:end" (wire key) or "optionid:start:end" (uid).
 *
 * @param {string} key
 * @returns {number}
 */
const startOfKey = (key) => {
    const parts = String(key || '').split(':');
    return Number(parts.length >= 3 ? parts[1] : parts[0]) || 0;
};

/**
 * Whether a time range overlaps another one (timestamps in seconds).
 *
 * @param {number} start
 * @param {number} end
 * @param {Object} range {start, end}
 * @returns {boolean}
 */
const overlaps = (start, end, range) => start < Number(range.end) && end > Number(range.start);

export class CompactCalendarPicker {
    /**
     * Constructor. Use init() instead, it loads the calendar library first.
     *
     * @param {HTMLElement} root where the calendar is drawn
     * @param {Function} CalendarView the calendar of local_entities
     * @param {Object} labels texts of the calendar
     * @param {Object} options see init()
     */
    constructor(root, CalendarView, labels, options) {
        this.root = root;
        this.options = options;
        this.slots = Array.isArray(options.slots) ? options.slots : [];
        this.slotFilter = typeof options.slotFilter === 'function' ? options.slotFilter : null;
        this.custom = options.mode === 'custom';
        this.tileMinutes = this.custom ? new Map() : this.rollingTileMinutes();
        this.startFormatter = new Intl.DateTimeFormat(options.display.locale || undefined, options.display.hour12
            ? {hour: 'numeric', minute: '2-digit', hour12: true}
            : {hour: '2-digit', minute: '2-digit', hour12: false});

        this.view = new CalendarView(root, {
            provider: (from, until) => Promise.resolve(this.buildEntries(from, until)),
            onSelect: (start, end) => this.chooseRange(start, end),
            onDayClick: (start, end) => this.chooseRange(start, end),
            onEntryClick: (props) => this.clickEntry(props),
            labels,
            locale: options.display.locale,
            firstDay: options.display.firstDay,
            hour12: options.display.hour12,
            // Fixed slots are picked by clicking them; only a free time range is dragged.
            readonly: !this.custom,
            period: this.period(),
        });
        this.view.render();
        this.adjustCalendar();
    }

    /**
     * Fit the calendar of local_entities to the slots: only the hours and week days that have
     * slots, own content of the entries, a moveable choice for user-defined slots, and the view
     * that fits the space.
     */
    adjustCalendar() {
        const calendar = this.view.calendar;
        if (!calendar) {
            return;
        }
        const hours = this.visibleHours();
        calendar.setOption('slotMinTime', toTimeOption(hours.from));
        calendar.setOption('slotMaxTime', toTimeOption(hours.until));
        calendar.setOption('scrollTime', toTimeOption(hours.from));
        calendar.setOption('height', 'auto');
        calendar.setOption('hiddenDays', this.hiddenDays());
        calendar.setOption('eventContent', (arg) => this.renderContent(arg));
        calendar.setOption('eventDidMount', (arg) => {
            arg.el.title = arg.event.extendedProps.tooltip || arg.timeText || '';
            // The colour of the option in the sidebar: stripe and tint of the entry (see styles.css).
            if (arg.event.extendedProps.color) {
                arg.el.style.setProperty('--booking-option-color', arg.event.extendedProps.color);
            }
        });

        if (this.custom) {
            // The choice is moved and resized on the grid of the start interval.
            const interval = Math.max(1, Math.min(...this.slots.map(day => Number(day.startintervalminutes || 30))));
            calendar.setOption('snapDuration', toTimeOption(interval));
            calendar.setOption('eventResizableFromStart', true);
            calendar.on('eventDrop', (info) => this.changeChoice(info));
            calendar.on('eventResize', (info) => this.changeChoice(info));
        }

        const first = this.firstFreeStart();
        if (first) {
            const shownDays = 7 - this.hiddenDays().length;
            const narrow = this.root.clientWidth > 0
                && this.root.clientWidth < TIME_AXIS_PX + shownDays * MIN_DAY_COLUMN_PX;
            calendar.changeView(narrow ? 'timeGridDay' : 'timeGridWeek', new Date(first * 1000));
        }
    }

    /**
     * The days that have slots at all, so the calendar cannot be paged into empty months.
     *
     * @returns {Object|null} {start, end} in seconds
     */
    period() {
        if (this.slots.length === 0) {
            return null;
        }
        const first = new Date(Math.min(...this.slots.map(slot => Number(slot.start))) * 1000);
        first.setHours(0, 0, 0, 0);
        const last = new Date(Math.max(...this.slots.map(slot => Number(slot.end))) * 1000);
        if (last.getHours() || last.getMinutes() || last.getSeconds()) {
            last.setHours(24, 0, 0, 0);
        }
        return {start: toSeconds(first), end: toSeconds(last)};
    }

    /**
     * The time ranges shown: the slots, or the opening hours of user-defined slots.
     *
     * @returns {Array} [{start, end}]
     */
    timeRanges() {
        return this.slots.map(slot => (this.custom
            ? {start: Number(slot.openfrom), end: Number(slot.openuntil)}
            : {start: Number(slot.start), end: Number(slot.end)}));
    }

    /**
     * From the full hour before the earliest slot to the full hour after the latest one, in
     * minutes of the day. A slot over midnight shows the whole day.
     *
     * @returns {Object} {from, until}
     */
    visibleHours() {
        const ranges = this.timeRanges().filter(range => range.end > range.start);
        if (ranges.length === 0 || ranges.some(range => toDayKey(range.start) !== toDayKey(range.end - 1))) {
            return {from: 0, until: 24 * 60};
        }
        const from = Math.min(...ranges.map(range => minuteOfDay(range.start)));
        const until = Math.max(...ranges.map(range => minuteOfDay(range.end - 1) + 1));
        return {from: Math.floor(from / 60) * 60, until: Math.min(24 * 60, Math.ceil(until / 60) * 60)};
    }

    /**
     * The week days (0 = Sunday) without any slot. Never all of them.
     *
     * @returns {number[]}
     */
    hiddenDays() {
        const used = new Set(this.timeRanges().map(range => new Date(range.start * 1000).getDay()));
        const hidden = [0, 1, 2, 3, 4, 5, 6].filter(day => !used.has(day));
        return hidden.length < 7 ? hidden : [];
    }

    /**
     * The start of the first slot that can still be booked, to open the calendar there.
     *
     * @returns {number} timestamp in seconds, 0 if there is none
     */
    firstFreeStart() {
        const now = toSeconds(new Date());
        const starts = this.visibleSlots()
            .filter(slot => (this.custom ? Number(slot.openuntil) : Number(slot.end)) > now)
            .filter(slot => this.custom || !(String(slot.status || '') === 'booked' || slot.selectable === false))
            .map(slot => Math.max(now, this.custom ? Number(slot.openfrom) : Number(slot.start)));
        return starts.length > 0 ? Math.min(...starts) : 0;
    }

    /**
     * Rolling slots overlap each other (e.g. 60 minutes every 15 minutes) and would be stacked into
     * unreadable columns. They are shown as a tile of the start interval instead, with the start
     * time. Per option: the interval between two starts, only for options whose slots overlap.
     *
     * @returns {Map} optionid => minutes of a tile
     */
    rollingTileMinutes() {
        const byOption = new Map();
        this.slots.forEach(slot => {
            const optionid = Number(slot.optionid || 0);
            if (!byOption.has(optionid)) {
                byOption.set(optionid, []);
            }
            byOption.get(optionid).push(slot);
        });
        const tiles = new Map();
        byOption.forEach((slots, optionid) => {
            const sorted = slots.slice().sort((a, b) => Number(a.start) - Number(b.start));
            let interval = 0;
            let overlapping = false;
            for (let i = 1; i < sorted.length; i++) {
                const step = Number(sorted[i].start) - Number(sorted[i - 1].start);
                if (step > 0 && (interval === 0 || step < interval)) {
                    interval = step;
                }
                if (Number(sorted[i].start) < Number(sorted[i - 1].end)) {
                    overlapping = true;
                }
            }
            if (overlapping && interval > 0) {
                tiles.set(optionid, Math.round(interval / 60));
            }
        });
        return tiles;
    }

    /**
     * The slots that are not filtered out (sidebar of a merged calendar).
     *
     * @returns {Array}
     */
    visibleSlots() {
        return this.slotFilter ? this.slots.filter(this.slotFilter) : this.slots;
    }

    /**
     * The entries of the calendar between two timestamps.
     *
     * @param {number} from
     * @param {number} until
     * @returns {Array} events of the calendar library
     */
    buildEntries(from, until) {
        const slots = this.visibleSlots().filter(slot => overlaps(from, until, slot));
        return this.custom ? this.buildCustomEntries(slots) : this.buildFixedEntries(slots);
    }

    /**
     * Fixed slots: one entry per slot, marked as selected, free or booked. An overlapping rolling
     * slot is a tile of its start interval until it is selected, then it shows its full time.
     *
     * @param {Array} slots
     * @returns {Array}
     */
    buildFixedEntries(slots) {
        const {selection, optionColors} = this.options;
        return slots.map(slot => {
            const uid = String(slot.uid || slot.key || `${slot.start}:${slot.end}`);
            const optionid = Number(slot.optionid || 0);
            const booked = String(slot.status || '') === 'booked' || slot.selectable === false;
            const selected = !booked && selection.isSelected(uid);
            let state = 'available';
            if (booked) {
                state = 'booked';
            } else if (selected) {
                state = 'selected';
            }
            const tileMinutes = this.tileMinutes.get(optionid) || 0;
            const tile = tileMinutes > 0 && !selected;
            const start = Number(slot.start);
            const end = tile ? Math.min(Number(slot.end), start + tileMinutes * 60) : Number(slot.end);
            const price = Number(slot.price || 0) > 0 && slot.priceformatted ? String(slot.priceformatted) : '';
            const color = optionColors ? (optionColors.get(optionid) || '') : '';
            const classNames = ['booking-slot-compact', `booking-slot--${state}`, sizeClass(start, end)];
            if (tile) {
                classNames.push('booking-slot-compact-tile');
            }
            return {
                id: uid,
                start: new Date(start * 1000),
                end: new Date(end * 1000),
                classNames: classNames.filter(Boolean),
                extendedProps: {
                    kind: 'slot',
                    uid,
                    optionid,
                    color,
                    booked,
                    tile,
                    price,
                    status: String(slot.statuslabel || '').trim(),
                    tooltip: [slot.timelabel, price, slot.statuslabel].filter(Boolean).join(' · '),
                },
            };
        });
    }

    /**
     * User-defined slots: the opening hours as background, the booked times as busy (a range cannot
     * be dragged over them) and the time the user chose, which can be moved and resized.
     *
     * @param {Array} days the open days of the active option
     * @returns {Array}
     */
    buildCustomEntries(days) {
        const entries = [];
        days.forEach(day => {
            entries.push({
                start: new Date(Number(day.openfrom) * 1000),
                end: new Date(Number(day.openuntil) * 1000),
                display: 'background',
                classNames: ['booking-slot-compact-open'],
                extendedProps: {kind: 'open'},
            });
            (Array.isArray(day.bookedranges) ? day.bookedranges : []).forEach(range => {
                entries.push({
                    start: new Date(Number(range.start) * 1000),
                    end: new Date(Number(range.end) * 1000),
                    display: 'background',
                    classNames: ['booking-slot-compact', 'booking-slot--booked'],
                    // The calendar of local_entities does not let a range be marked over 'occupation'.
                    extendedProps: {kind: 'occupation'},
                });
            });
        });
        const choice = typeof this.options.getCustomChoice === 'function' ? this.options.getCustomChoice() : null;
        if (choice && choice.end > choice.start) {
            const {optionColors} = this.options;
            const optionid = days.length > 0 ? Number(days[0].optionid || 0) : 0;
            const color = optionColors ? (optionColors.get(optionid) || '') : '';
            entries.push({
                id: 'choice',
                start: new Date(choice.start * 1000),
                end: new Date(choice.end * 1000),
                classNames: ['booking-slot-compact', 'booking-slot--selected', 'booking-slot-compact-choice',
                    sizeClass(choice.start, choice.end)].filter(Boolean),
                // The only entry that can be moved and resized, see changeChoice().
                editable: true,
                extendedProps: {kind: 'choice', color},
            });
        }
        return entries;
    }

    /**
     * The content of an entry: its time (only the start for a tile) and its price.
     *
     * @param {Object} arg content argument of the calendar library
     * @returns {Object|boolean}
     */
    renderContent(arg) {
        const props = arg.event.extendedProps || {};
        if (props.kind !== 'slot' && props.kind !== 'choice') {
            return true;
        }
        const wrapper = document.createElement('div');
        wrapper.className = 'booking-slot-compact-content';
        const time = document.createElement('span');
        time.className = 'booking-slot-compact-time';
        time.textContent = props.tile ? this.startFormatter.format(arg.event.start) : arg.timeText;
        wrapper.appendChild(time);
        [props.price, props.status].filter(Boolean).forEach(text => {
            const extra = document.createElement('span');
            extra.className = 'booking-slot-compact-extra';
            extra.textContent = text;
            wrapper.appendChild(extra);
        });
        return {domNodes: [wrapper]};
    }

    /**
     * A fixed slot was clicked: toggle it in the selection.
     *
     * @param {Object} props extendedProps of the entry
     */
    clickEntry(props) {
        if (this.custom || !props || props.kind !== 'slot' || props.booked) {
            return;
        }
        this.options.selection.toggle(props.uid, props.optionid || null);
        this.refresh();
    }

    /**
     * The open day a time range belongs to. A whole day (month view) means its opening hours.
     *
     * @param {number} start
     * @param {number} end
     * @returns {Object|null} {day, start, end}
     */
    resolveRange(start, end) {
        const days = this.visibleSlots();
        const inside = days.find(day => start >= Number(day.openfrom) && start < Number(day.openuntil));
        if (inside) {
            return {day: inside, start, end};
        }
        if (end - start >= 24 * 60 * 60) {
            const sameDay = days.find(day => toDayKey(day.openfrom) === toDayKey(start));
            if (sameDay) {
                // The shortest duration: the option snaps the end to what it offers.
                return {day: sameDay, start: Number(sameDay.openfrom), end: Number(sameDay.openfrom) + 1};
            }
        }
        return null;
    }

    /**
     * A time range was dragged or a time was clicked (user-defined slots only).
     *
     * @param {number} start
     * @param {number} end
     */
    chooseRange(start, end) {
        if (!this.custom || typeof this.options.onCustomRange !== 'function') {
            return;
        }
        const range = this.resolveRange(start, end);
        if (!range) {
            return;
        }
        this.options.onCustomRange(range.day, range.start, range.end);
        this.refresh();
    }

    /**
     * The choice was moved or resized. It goes back when it leaves the opening hours or lies over a
     * booked time, else it is snapped like a dragged range.
     *
     * @param {Object} info drop or resize argument of the calendar library
     */
    changeChoice(info) {
        const start = toSeconds(info.event.start);
        const end = toSeconds(info.event.end || info.event.start);
        const day = this.visibleSlots().find(entry => start >= Number(entry.openfrom) && end <= Number(entry.openuntil));
        const busy = day && (Array.isArray(day.bookedranges) ? day.bookedranges : [])
            .some(range => overlaps(start, end, range));
        if (!day || busy) {
            info.revert();
            return;
        }
        this.options.onCustomRange(day, start, end);
        this.refresh();
    }

    /**
     * Draw the entries again, e.g. after the selection changed outside of the calendar.
     */
    refresh() {
        this.view.refresh();
    }

    /**
     * Only show the slots the filter accepts (sidebar of a merged calendar).
     *
     * @param {Function|null} filter
     */
    setSlotFilter(filter) {
        this.slotFilter = typeof filter === 'function' ? filter : null;
        this.refresh();
    }

    /**
     * The selection lives in the selection adapter, the calendar only has to show it again.
     */
    setSelectedKeys() {
        this.refresh();
    }

    /**
     * The day of a slot, for the "go to day" link of the summary.
     *
     * @param {string} key wire key or uid
     * @returns {string}
     */
    findDayKeyForSlotKey(key) {
        const start = startOfKey(key);
        return start ? toDayKey(start) : '';
    }

    /**
     * Show a day in the day view.
     *
     * @param {string} dayKey "YYYY-MM-DD"
     */
    goToDay(dayKey) {
        if (!dayKey || !this.view.calendar) {
            return;
        }
        this.view.calendar.changeView('timeGridDay', dayKey);
    }
}

/**
 * Draw the compact calendar.
 *
 * @param {HTMLElement} root where the calendar is drawn
 * @param {Object} options
 * @param {Array} options.slots fixed: the slots, custom: the open days
 * @param {string} options.mode 'fixed' or 'custom'
 * @param {Object} options.selection fixed only: selection adapter (isSelected, toggle)
 * @param {Map} options.optionColors optionid => color of a merged calendar
 * @param {Function|null} options.slotFilter slot => bool
 * @param {Function} options.getCustomChoice custom only: () => {start, end} or null
 * @param {Function} options.onCustomRange custom only: (day, start, end) a range was chosen
 * @param {Object} options.display {locale, firstDay, hour12}
 * @returns {Promise<CompactCalendarPicker>}
 */
export const init = async(root, options) => {
    // The path to the calendar library is set by the config module of local_entities.
    await import('local_entities/config');
    // Moodle builds a module that only has a default export so that it returns the class itself.
    const calendarModule = await import('local_entities/unavailability/calendar_view');
    const CalendarView = calendarModule.default || calendarModule;
    loadStylesheet();
    const labels = await loadLabels();
    root.innerHTML = '';
    return new CompactCalendarPicker(root, CalendarView, labels, options);
};
