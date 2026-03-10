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
 * @module     mod_booking/slotCalendarPicker
 * @copyright  Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const WEEK_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const toDateKey = (timestamp) => {
    const date = new Date(timestamp * 1000);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

const toMonthLabel = (date) => {
    return date.toLocaleDateString(undefined, {month: 'long', year: 'numeric'});
};

const toMonthKey = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    return `${year}-${month}`;
};

const cloneDate = (date) => {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
};

const getWeekStartDate = (date) => {
    const start = cloneDate(date);
    const weekday = (start.getDay() + 6) % 7;
    start.setDate(start.getDate() - weekday);
    return start;
};

const toWeekKey = (date) => {
    return toDateKey(Math.floor(getWeekStartDate(date).getTime() / 1000));
};

const noop = () => {
    return null;
};

export class SlotCalendarPicker {
    /**
     * @param {HTMLElement} root
     * @param {Object} options
     */
    constructor(root, options = {}) {
        this.root = root;
        this.slots = Array.isArray(options.slots) ? options.slots : [];
        this.maxSelection = Math.max(1, Number(options.maxSelection || 1));
        this.replaceWhenFull = Boolean(options.replaceWhenFull);
        this.onChange = typeof options.onChange === 'function' ? options.onChange : noop;
        this.onDayChange = typeof options.onDayChange === 'function' ? options.onDayChange : noop;
        this.dayCountFormatter = typeof options.dayCountFormatter === 'function' ? options.dayCountFormatter : null;
        this.slotFilter = typeof options.slotFilter === 'function' ? options.slotFilter : null;
        this.emptySlotListText = options.emptySlotListText === undefined
            ? 'No open slots on this day.'
            : String(options.emptySlotListText);
        this.showSlotList = options.showSlotList !== false;
        this.showPriceLegend = Boolean(options.showPriceLegend);
        this.dayStateResolver = typeof options.dayStateResolver === 'function' ? options.dayStateResolver : null;
        this.resetSelectionOnDayChange = Boolean(options.resetSelectionOnDayChange);

        this.viewMode = 'month';
        this.selected = new Set(Array.isArray(options.initialSelection) ? options.initialSelection : []);

        this.slotsByDay = new Map();
        this.allDayKeys = [];
        this.availableMonthKeys = [];
        this.availableWeekKeys = [];
        this.activeDay = null;
        this.currentDate = new Date();
        this.priceLevels = [];
        this.priceScaleMin = 0;
        this.priceScaleMax = 0;

        this.prepareData();
        this.buildLayout();
        this.render();
        this.emitChange();

        if (this.activeDay) {
            const activeDaySlots = this.slotsByDay.get(this.activeDay) || [];
            this.onDayChange(this.activeDay, activeDaySlots);
        }

        this.handleResize = () => {
            this.applyResponsiveStyles();
        };
        window.addEventListener('resize', this.handleResize);
    }

    prepareData() {
        this.slots.forEach(slot => {
            const key = slot.key || `${slot.start}:${slot.end}`;
            const dayKey = toDateKey(Number(slot.start));
            const entry = {
                ...slot,
                key,
                start: Number(slot.start),
                end: Number(slot.end),
                daylabel: slot.daylabel || dayKey,
                timelabel: slot.timelabel || `${slot.start} - ${slot.end}`,
                teachers: Array.isArray(slot.teachers) ? slot.teachers : [],
                bookings: Number(slot.bookings || 0),
                capacity: Number(slot.capacity || 0),
                bookable: Boolean(slot.bookable),
                price: Number(slot.price || 0),
                currency: String(slot.currency || ''),
                priceformatted: String(slot.priceformatted || '').trim(),
                openfrom: Number(slot.openfrom || 0),
                openuntil: Number(slot.openuntil || 0),
                startintervalminutes: Number(slot.startintervalminutes || 0),
                bookedranges: Array.isArray(slot.bookedranges) ? slot.bookedranges : [],
            };

            if (!this.slotsByDay.has(dayKey)) {
                this.slotsByDay.set(dayKey, []);
            }
            this.slotsByDay.get(dayKey).push(entry);
        });

        this.allDayKeys = Array.from(this.slotsByDay.keys()).sort();
        this.availableMonthKeys = [];
        this.availableWeekKeys = [];

        const monthSet = new Set();
        const weekSet = new Set();

        this.allDayKeys.forEach(dayKey => {
            const date = new Date(`${dayKey}T00:00:00`);
            monthSet.add(toMonthKey(date));
            weekSet.add(toWeekKey(date));
        });

        this.availableMonthKeys = Array.from(monthSet).sort();
        this.availableWeekKeys = Array.from(weekSet).sort();

        const priceSet = new Set();
        this.slotsByDay.forEach(daySlots => {
            daySlots.forEach(slot => {
                if (Number.isFinite(slot.price)) {
                    priceSet.add(Number(slot.price));
                }
            });
        });
        this.priceLevels = Array.from(priceSet).sort((a, b) => a - b);

        const positivePriceLevels = this.priceLevels.filter(price => price > 0);
        this.priceScaleMin = positivePriceLevels.length ? Math.min(...positivePriceLevels) : 0;
        this.priceScaleMax = positivePriceLevels.length ? Math.max(...positivePriceLevels) : 0;

        if (this.allDayKeys.length > 0) {
            this.activeDay = this.allDayKeys[0];
            const firstDate = new Date(`${this.activeDay}T00:00:00`);
            this.currentDate = cloneDate(firstDate);
        }
    }

    buildLayout() {
        this.root.innerHTML = '';
        Object.assign(this.root.style, {
            width: '100%',
            maxWidth: '100%',
            minWidth: '0',
        });

        const modalDialog = this.root.closest('.modal-dialog');
        if (modalDialog) {
            Object.assign(modalDialog.style, {
                width: 'min(900px, calc(100vw - 1rem))',
                maxWidth: 'min(900px, calc(100vw - 1rem))',
            });
        }

        this.container = document.createElement('div');
        this.container.className = 'booking-slot-calendar-ui border rounded p-2';
        Object.assign(this.container.style, {
            width: '100%',
            maxWidth: '100%',
            minWidth: '0',
            boxSizing: 'border-box',
        });

        this.toolbar = document.createElement('div');
        this.toolbar.className = 'd-flex justify-content-between align-items-center mb-2';
        Object.assign(this.toolbar.style, {
            gap: '0.5rem',
        });

        this.leftControls = document.createElement('div');
        this.leftControls.className = 'btn-group btn-group-sm';

        this.prevBtn = document.createElement('button');
        this.prevBtn.type = 'button';
        this.prevBtn.className = 'btn btn-outline-secondary';
        this.prevBtn.textContent = '‹';
        this.prevBtn.addEventListener('click', () => this.shift(-1));

        this.nextBtn = document.createElement('button');
        this.nextBtn.type = 'button';
        this.nextBtn.className = 'btn btn-outline-secondary';
        this.nextBtn.textContent = '›';
        this.nextBtn.addEventListener('click', () => this.shift(1));

        this.leftControls.appendChild(this.prevBtn);
        this.leftControls.appendChild(this.nextBtn);

        this.title = document.createElement('div');
        this.title.className = 'fw-bold';

        this.modeControls = document.createElement('div');
        this.modeControls.className = 'btn-group btn-group-sm';

        this.monthBtn = document.createElement('button');
        this.monthBtn.type = 'button';
        this.monthBtn.className = 'btn btn-outline-secondary';
        this.monthBtn.textContent = 'Month';
        this.monthBtn.addEventListener('click', () => {
            this.viewMode = 'month';
            this.alignCurrentDateToAvailableView();
            this.render();
        });

        this.weekBtn = document.createElement('button');
        this.weekBtn.type = 'button';
        this.weekBtn.className = 'btn btn-outline-secondary';
        this.weekBtn.textContent = 'Week';
        this.weekBtn.addEventListener('click', () => {
            this.viewMode = 'week';
            this.alignCurrentDateToAvailableView();
            this.render();
        });

        this.modeControls.appendChild(this.monthBtn);
        this.modeControls.appendChild(this.weekBtn);

        this.toolbar.appendChild(this.leftControls);
        this.toolbar.appendChild(this.title);
        this.toolbar.appendChild(this.modeControls);

        this.calendarGrid = document.createElement('div');
        this.calendarGrid.className = 'booking-slot-calendar-grid mb-3';
        Object.assign(this.calendarGrid.style, {
            display: 'grid',
            gridTemplateColumns: 'repeat(7, minmax(0, 1fr))',
            gap: '0.25rem',
            alignItems: 'stretch',
            width: '100%',
            minWidth: '0',
        });

        this.slotList = document.createElement('div');
        this.slotList.className = 'booking-slot-calendar-slots';
        Object.assign(this.slotList.style, {
            width: '100%',
            minWidth: '0',
        });

        this.selectionInfo = document.createElement('div');
        this.selectionInfo.className = 'small text-muted mt-2';

        this.priceLegend = document.createElement('div');
        this.priceLegend.className = 'small text-muted mt-2';

        this.container.appendChild(this.toolbar);
        this.container.appendChild(this.calendarGrid);
        if (this.showPriceLegend) {
            this.container.appendChild(this.priceLegend);
        }
        if (this.showSlotList) {
            this.container.appendChild(this.slotList);
        }
        this.container.appendChild(this.selectionInfo);
        this.root.appendChild(this.container);

        this.applyResponsiveStyles();
    }

    shift(direction) {
        const keys = this.viewMode === 'week' ? this.availableWeekKeys : this.availableMonthKeys;
        if (!keys.length) {
            return;
        }

        const currentKey = this.viewMode === 'week'
            ? toWeekKey(this.currentDate)
            : toMonthKey(this.currentDate);
        const index = keys.indexOf(currentKey);
        const targetIndex = index + direction;

        if (index === -1 || targetIndex < 0 || targetIndex >= keys.length) {
            return;
        }

        const targetKey = keys[targetIndex];
        if (this.viewMode === 'week') {
            this.currentDate = new Date(`${targetKey}T00:00:00`);
        } else {
            this.currentDate = new Date(`${targetKey}-01T00:00:00`);
        }

        this.render();
    }

    alignCurrentDateToAvailableView() {
        const keys = this.viewMode === 'week' ? this.availableWeekKeys : this.availableMonthKeys;
        if (!keys.length) {
            return;
        }

        const currentKey = this.viewMode === 'week'
            ? toWeekKey(this.currentDate)
            : toMonthKey(this.currentDate);

        if (keys.includes(currentKey)) {
            return;
        }

        const targetKey = keys[0];
        if (this.viewMode === 'week') {
            this.currentDate = new Date(`${targetKey}T00:00:00`);
        } else {
            this.currentDate = new Date(`${targetKey}-01T00:00:00`);
        }
    }

    updateNavigationState() {
        const keys = this.viewMode === 'week' ? this.availableWeekKeys : this.availableMonthKeys;
        if (!keys.length) {
            this.prevBtn.disabled = true;
            this.nextBtn.disabled = true;
            return;
        }

        const currentKey = this.viewMode === 'week'
            ? toWeekKey(this.currentDate)
            : toMonthKey(this.currentDate);
        const index = keys.indexOf(currentKey);

        this.prevBtn.disabled = index <= 0;
        this.nextBtn.disabled = index === -1 || index >= keys.length - 1;
    }

    getVisibleDays() {
        const days = [];
        const base = cloneDate(this.currentDate);

        if (this.viewMode === 'week') {
            const weekStart = getWeekStartDate(base);
            for (let i = 0; i < 7; i++) {
                const date = cloneDate(weekStart);
                date.setDate(weekStart.getDate() + i);
                days.push(date);
            }
            return days;
        }

        const first = new Date(base.getFullYear(), base.getMonth(), 1);
        const offset = (first.getDay() + 6) % 7;
        first.setDate(first.getDate() - offset);

        for (let i = 0; i < 42; i++) {
            const date = cloneDate(first);
            date.setDate(first.getDate() + i);
            days.push(date);
        }

        return days;
    }

    render() {
        this.alignCurrentDateToAvailableView();

        this.title.textContent = this.viewMode === 'week'
            ? `Week of ${this.currentDate.toLocaleDateString()}`
            : toMonthLabel(this.currentDate);

        this.monthBtn.classList.toggle('btn-primary', this.viewMode === 'month');
        this.monthBtn.classList.toggle('btn-outline-secondary', this.viewMode !== 'month');
        this.weekBtn.classList.toggle('btn-primary', this.viewMode === 'week');
        this.weekBtn.classList.toggle('btn-outline-secondary', this.viewMode !== 'week');

        if (this.showPriceLegend) {
            this.renderPriceLegend();
        }

        this.renderCalendarGrid();
        if (this.showSlotList) {
            this.renderSlotList();
        }
        this.renderSelectionInfo();
        this.updateNavigationState();
    }

    renderCalendarGrid() {
        this.calendarGrid.innerHTML = '';

        WEEK_DAYS.forEach(label => {
            const head = document.createElement('div');
            head.className = 'booking-slot-calendar-head text-center small fw-bold';
            Object.assign(head.style, {
                padding: '0.25rem 0',
                minWidth: '0',
            });
            head.textContent = label;
            this.calendarGrid.appendChild(head);
        });

        const days = this.getVisibleDays();
        const month = this.currentDate.getMonth();

        days.forEach(date => {
            const dayKey = toDateKey(Math.floor(date.getTime() / 1000));
            const daySlots = this.slotsByDay.get(dayKey) || [];

            const cell = document.createElement('div');
            cell.className = 'booking-slot-calendar-cell';
            cell.style.minWidth = '0';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm w-100 text-start booking-slot-calendar-day-btn';
            Object.assign(btn.style, {
                minHeight: '3.75rem',
                whiteSpace: 'normal',
                overflowWrap: 'anywhere',
            });

            const inCurrentMonth = date.getMonth() === month || this.viewMode === 'week';
            btn.classList.add(inCurrentMonth ? 'btn-light' : 'btn-outline-light');

            if (daySlots.length > 0) {
                btn.classList.add('border', 'border-success');
            }
            if (this.activeDay === dayKey) {
                btn.classList.remove('btn-light');
                btn.classList.add('btn-primary', 'text-white');
            }

            const daystate = this.dayStateResolver ? String(this.dayStateResolver(daySlots) || '') : '';
            if (daystate === 'full' && this.activeDay !== dayKey) {
                btn.classList.remove('btn-light');
                btn.style.backgroundColor = '#f8d7da';
                btn.style.borderColor = '#f1aeb5';
                btn.style.color = '#58151c';
            }

            const dayNumber = document.createElement('div');
            dayNumber.className = 'small fw-bold';
            dayNumber.textContent = String(date.getDate());
            btn.appendChild(dayNumber);

            if (daySlots.length > 0) {
                const count = document.createElement('div');
                count.className = 'small';
                if (this.dayCountFormatter) {
                    count.textContent = String(this.dayCountFormatter(daySlots));
                } else {
                    count.textContent = `${daySlots.length} slots`;
                }
                btn.appendChild(count);

                if (this.showPriceLegend && this.priceLevels.length > 0) {
                    const dayPriceDots = document.createElement('div');
                    dayPriceDots.className = 'mt-1 d-flex flex-wrap align-items-center';
                    dayPriceDots.style.gap = '0.2rem';

                    const daySelected = new Set(daySlots
                        .filter(slot => this.selected.has(slot.key))
                        .map(slot => Number(slot.price || 0)));
                    const dayPrices = Array.from(new Set(daySlots.map(slot => Number(slot.price || 0))))
                        .sort((a, b) => a - b);

                    dayPrices.forEach(price => {
                        const dot = document.createElement('span');
                        dot.style.width = '0.5rem';
                        dot.style.height = '0.5rem';
                        dot.style.borderRadius = '999px';
                        dot.style.display = 'inline-block';
                        dot.style.backgroundColor = this.getPriceColor(price);
                        dot.style.border = daySelected.has(price)
                            ? '2px solid #0d6efd'
                            : '1px solid rgba(0,0,0,0.15)';
                        dot.title = this.getPriceLabel(price);
                        dayPriceDots.appendChild(dot);
                    });

                    btn.appendChild(dayPriceDots);
                }
            }

            btn.addEventListener('click', () => {
                const changed = this.activeDay !== dayKey;
                this.activeDay = dayKey;

                if (changed && this.resetSelectionOnDayChange && this.selected.size > 0) {
                    this.selected.clear();
                    this.emitChange();
                }

                this.render();

                if (changed) {
                    this.onDayChange(dayKey, daySlots);
                }
            });

            cell.appendChild(btn);
            this.calendarGrid.appendChild(cell);
        });
    }

    renderSlotList() {
        this.slotList.innerHTML = '';

        if (!this.activeDay) {
            return;
        }

        const slots = this.slotsByDay.get(this.activeDay) || [];
        const visibleSlots = this.slotFilter ? slots.filter(this.slotFilter) : slots;

        if (visibleSlots.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'small text-muted';
            empty.textContent = this.emptySlotListText;
            this.slotList.appendChild(empty);
            return;
        }

        const heading = document.createElement('div');
        heading.className = 'small fw-bold mb-2';
        heading.textContent = visibleSlots[0].daylabel;
        this.slotList.appendChild(heading);

        const list = document.createElement('div');
        list.className = 'booking-slot-calendar-slot-list';
        Object.assign(list.style, {
            display: 'grid',
            gridTemplateColumns: 'repeat(2, minmax(0, 1fr))',
            gap: '0.25rem',
            width: '100%',
            minWidth: '0',
        });

        visibleSlots.forEach(slot => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm';
            btn.textContent = slot.timelabel;
            Object.assign(btn.style, {
                width: '100%',
                textAlign: 'left',
                whiteSpace: 'normal',
                overflowWrap: 'anywhere',
            });

            if (Array.isArray(slot.teachers) && slot.teachers.length > 0) {
                btn.textContent = '';

                const timeLine = document.createElement('div');
                timeLine.className = 'fw-bold small';
                timeLine.textContent = slot.timelabel;
                btn.appendChild(timeLine);

                const teacherLine = document.createElement('div');
                teacherLine.className = 'small text-muted mt-1';

                slot.teachers.forEach((teacher, index) => {
                    const chip = document.createElement('span');
                    chip.className = 'me-1';
                    chip.textContent = String(teacher.initials || '').trim();
                    chip.title = String(teacher.fullname || '').trim();
                    chip.style.cursor = 'help';
                    teacherLine.appendChild(chip);

                    if (index < slot.teachers.length - 1) {
                        const separator = document.createElement('span');
                        separator.textContent = ' ';
                        teacherLine.appendChild(separator);
                    }
                });

                btn.appendChild(teacherLine);
            }

            if (slot.priceformatted) {
                if (btn.textContent !== '') {
                    btn.textContent = '';

                    const timeLine = document.createElement('div');
                    timeLine.className = 'fw-bold small';
                    timeLine.textContent = slot.timelabel;
                    btn.appendChild(timeLine);
                }

                const priceLine = document.createElement('div');
                priceLine.className = 'small text-muted mt-1';
                priceLine.textContent = slot.priceformatted;
                btn.appendChild(priceLine);
            }

            const selected = this.selected.has(slot.key);
            // Farbwahl je nach Modus: Unavailability = rot, Availability = grün.
            let markmode = 'unavailability';
            if (typeof this.root.closest === 'function') {
                const form = this.root.closest('form');
                if (form) {
                    const markmodeInput = form.querySelector('[name="markmode"]');
                    if (markmodeInput) {
                        markmode = markmodeInput.value;
                    }
                }
            }
            // Vorher alle btn-* Farbklassen entfernen, damit keine doppelt bleiben.
            btn.classList.remove('btn-success', 'btn-danger', 'btn-outline-secondary');
            if (selected) {
                if (markmode === 'unavailability') {
                    btn.classList.add('btn-danger');
                } else {
                    btn.classList.add('btn-success');
                }
            } else {
                btn.classList.add('btn-outline-secondary');
            }

            btn.addEventListener('click', () => {
                if (this.selected.has(slot.key)) {
                    this.selected.delete(slot.key);
                    this.render();
                    this.emitChange();
                    return;
                }

                if (this.selected.size >= this.maxSelection) {
                    if (this.replaceWhenFull) {
                        this.selected.clear();
                    } else {
                        return;
                    }
                }

                if (this.selected.size >= this.maxSelection) {
                    return;
                }

                this.selected.add(slot.key);
                this.render();
                this.emitChange();
            });

            list.appendChild(btn);
        });

        this.currentSlotList = list;
        this.applyResponsiveStyles();
        this.slotList.appendChild(list);
    }

    renderSelectionInfo() {
        this.selectionInfo.textContent = `${this.selected.size}/${this.maxSelection} selected`;
    }

    getPriceColor(price) {
        const numericPrice = Number(price || 0);
        if (numericPrice <= 0) {
            return '#198754';
        }

        if (this.priceScaleMax <= this.priceScaleMin) {
            return '#f59f00';
        }

        const ratio = Math.max(0, Math.min(1, (numericPrice - this.priceScaleMin) / (this.priceScaleMax - this.priceScaleMin)));
        const hue = Math.round(120 - (120 * ratio));
        return `hsl(${hue}, 75%, 45%)`;
    }

    getPriceLabel(price) {
        const numericPrice = Number(price || 0);
        if (numericPrice <= 0) {
            return 'Free';
        }

        const slotWithPrice = this.slots.find(slot => Number(slot.price || 0) === numericPrice);
        if (slotWithPrice && String(slotWithPrice.priceformatted || '').trim() !== '') {
            return String(slotWithPrice.priceformatted).trim();
        }

        return String(numericPrice);
    }

    renderPriceLegend() {
        this.priceLegend.innerHTML = '';

        if (!this.showPriceLegend || this.priceLevels.length === 0) {
            return;
        }

        const row = document.createElement('div');
        row.className = 'd-flex flex-wrap align-items-center';
        row.style.gap = '0.5rem';

        const title = document.createElement('span');
        title.className = 'fw-bold';
        title.textContent = 'Preis-Legende:';
        row.appendChild(title);

        const addLegendItem = (color, label, selected = false) => {
            const item = document.createElement('span');
            item.className = 'd-inline-flex align-items-center';
            item.style.gap = '0.25rem';

            const dot = document.createElement('span');
            dot.style.width = '0.6rem';
            dot.style.height = '0.6rem';
            dot.style.borderRadius = '999px';
            dot.style.display = 'inline-block';
            dot.style.backgroundColor = color;
            dot.style.border = selected ? '2px solid #0d6efd' : '1px solid rgba(0,0,0,0.2)';

            const text = document.createElement('span');
            text.textContent = label;

            item.appendChild(dot);
            item.appendChild(text);
            row.appendChild(item);
        };

        addLegendItem('#198754', 'Kostenlos');
        this.priceLevels.filter(price => price > 0).forEach(price => {
            addLegendItem(this.getPriceColor(price), this.getPriceLabel(price));
        });
        addLegendItem('#ffffff', 'Ausgewaehlt', true);

        this.priceLegend.appendChild(row);
    }

    emitChange() {
        this.onChange(Array.from(this.selected));
    }

    applyResponsiveStyles() {
        const width = window.innerWidth || 1024;

        if (this.toolbar) {
            this.toolbar.style.flexWrap = width < 768 ? 'wrap' : 'nowrap';
        }

        if (this.currentSlotList) {
            let columns = 3;
            if (width < 992) {
                columns = 2;
            }
            if (width < 576) {
                columns = 1;
            }
            this.currentSlotList.style.gridTemplateColumns = `repeat(${columns}, minmax(0, 1fr))`;
        }

        const dayButtons = this.calendarGrid
            ? this.calendarGrid.querySelectorAll('.booking-slot-calendar-day-btn')
            : [];
        dayButtons.forEach(button => {
            button.style.minHeight = width < 576 ? '3.1rem' : '3.75rem';
        });
    }
}

export const init = (root, options = {}) => {
    return new SlotCalendarPicker(root, options);
};
