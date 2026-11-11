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
 * The calendar of the compact slot booking (FullCalendar, shipped with mod_booking).
 *
 * It only shows the entries it is given and reports what the user does: a selected time range, a
 * clicked day and a clicked entry. What that means is decided by the module that uses it.
 * Call load() of mod_booking/slotbooking/fullcalendar before render().
 *
 * @module     mod_booking/slotbooking/calendar_view
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getLibrary} from 'mod_booking/slotbooking/fullcalendar';

/**
 * A date as a unix timestamp.
 *
 * @param {Date} date
 * @returns {number}
 */
const toSeconds = (date) => Math.floor(date.getTime() / 1000);

// Length of a time slot in the day and week view. A click on a free slot marks one slot.
const SLOT_MINUTES = 30;

export default class CalendarView {
    /**
     * Constructor.
     *
     * @param {HTMLElement} element where the calendar is drawn
     * @param {Object} options
     * @param {Function} options.provider (fromSeconds, untilSeconds) => Promise of entries
     * @param {Function} options.onSelect (startSeconds, endSeconds) a time range was dragged
     * @param {Function} options.onDayClick (startSeconds, endSeconds) a whole day was clicked
     * @param {Function} options.onEntryClick (extendedProps, element) an entry was clicked
     * @param {Object} options.labels texts: today, day, week, month, allday, more, showweek, showmonth
     * @param {string} options.locale language code
     * @param {number} options.firstDay first day of the week, 0 = Sunday
     * @param {boolean} options.hour12 12 hour clock instead of 24 hour
     * @param {boolean} options.readonly nothing can be marked, the calendar only shows entries
     * @param {Object|null} options.period start and end of the bookable period (unix timestamps), null if there is no limit
     */
    constructor(element, options) {
        this.element = element;
        this.options = options;
        this.calendar = null;
    }

    /**
     * Draws the calendar.
     */
    render() {
        const {provider, onSelect, onDayClick, onEntryClick, labels, locale, firstDay, hour12, readonly, period} = this.options;
        const {Calendar} = getLibrary();
        const timeFormat = hour12
            ? {hour: 'numeric', minute: '2-digit', meridiem: 'short', hour12: true}
            : {hour: '2-digit', minute: '2-digit', hour12: false};

        this.calendar = new Calendar(this.element, {
            timeZone: 'local',
            locale,
            firstDay,
            initialView: 'timeGridWeek',
            // Only the days of the bookable period are shown, starting with today or the first day of the period.
            ...(period ? {
                validRange: {start: new Date(period.start * 1000), end: new Date(period.end * 1000)},
                initialDate: new Date(Math.min(Math.max(Date.now(), period.start * 1000), period.end * 1000 - 1)),
            } : {}),
            headerToolbar: {left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth'},
            buttonText: {today: labels.today, day: labels.day, week: labels.week, month: labels.month},
            allDayText: labels.allday,
            moreLinkText: labels.more,
            eventTimeFormat: timeFormat,
            slotLabelFormat: timeFormat,
            slotMinTime: '00:00:00',
            slotDuration: `00:${SLOT_MINUTES}:00`,
            scrollTime: '07:00:00',
            height: 700,
            nowIndicator: true,
            // The number of a day opens that day, the number of a week opens that week, so one gets from the month
            // to a day or to a week.
            weekNumbers: true,
            weekNumberCalculation: 'ISO',
            weekText: labels.week,
            navLinks: true,
            navLinkDayClick: 'timeGridDay',
            navLinkWeekClick: 'timeGridWeek',
            selectable: !readonly,
            selectMirror: true,
            selectMinDistance: 5,
            unselectAuto: false,
            editable: false,
            eventOverlap: true,
            // A time range cannot be marked over an entry of the kind 'occupation': it is busy then.
            selectOverlap: (event) => event.extendedProps.kind !== 'occupation',
            selectAllow: (info) => {
                // Time that is over cannot be marked.
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                return info.end > today;
            },
            dayMaxEvents: true,
            datesSet: (info) => {
                // The title zooms out: from the day to its week, from the week to its month.
                const zoomText = {timeGridDay: labels.showweek, timeGridWeek: labels.showmonth}[info.view.type];
                const title = this.element.querySelector('.fc-toolbar-title');
                if (title) {
                    title.title = zoomText || '';
                    title.style.cursor = zoomText ? 'pointer' : '';
                }
                // A button that was clicked keeps its focus frame, although another view is shown now.
                const focused = document.activeElement;
                if (focused && focused.classList.contains('fc-button') && this.element.contains(focused)) {
                    focused.blur();
                }
            },
            events: (info, success, failure) => {
                provider(toSeconds(info.start), toSeconds(info.end)).then(success).catch(failure);
            },
            select: (info) => {
                onSelect(toSeconds(info.start), toSeconds(info.end));
                this.calendar.unselect();
            },
            dateClick: (info) => {
                if (readonly) {
                    return;
                }
                // A click on a day, in the month or in the all-day row, marks the whole day. A click on a
                // time slot marks that slot, a longer time range is dragged.
                const start = new Date(info.date);
                const end = new Date(info.date);
                if (info.allDay) {
                    end.setDate(end.getDate() + 1);
                } else {
                    end.setTime(end.getTime() + SLOT_MINUTES * 60 * 1000);
                }
                onDayClick(toSeconds(start), toSeconds(end));
            },
            eventClick: (info) => {
                onEntryClick(info.event.extendedProps, info.el);
            },
            // A short entry cuts its text off, so the whole text shows when the mouse rests on it.
            eventDidMount: (info) => {
                info.el.title = [info.timeText, info.event.title].filter(Boolean).join(' · ');
            },
        });
        this.calendar.render();

        this.element.addEventListener('click', (event) => {
            if (!event.target.closest('.fc-toolbar-title')) {
                return;
            }
            const zoomOut = {timeGridDay: 'timeGridWeek', timeGridWeek: 'dayGridMonth'}[this.calendar.view.type];
            if (zoomOut) {
                this.calendar.changeView(zoomOut);
            }
        });
    }

    /**
     * Asks the provider for the entries again and redraws them.
     */
    refresh() {
        if (this.calendar) {
            this.calendar.refetchEvents();
        }
    }

    /**
     * Removes the calendar.
     */
    destroy() {
        if (this.calendar) {
            this.calendar.destroy();
            this.calendar = null;
        }
    }
}
