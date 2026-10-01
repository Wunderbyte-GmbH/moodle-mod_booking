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
 * Fills the booking instance form with the values of the chosen instance template.
 *
 * @module     mod_booking/bookinginstancetemplateselect
 * @copyright  2019 Andraž Prinčič
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      4.5
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

/**
 * Template properties copied into the form field with the id "id_<property>".
 * Still missing (as before): attachment, bookingmanager, the field lists of "Customize columns and
 * fields", sign-in sheet fields, custom report templates, common module settings, restrict access,
 * activity completion and competencies.
 */
const VALUE_FIELDS = [
    'name', 'eventtype', 'duration', 'points',
    'organizatorname', 'pollurl', 'pollurlteachers', 'whichview',
    'defaultoptionsort', 'defaultsortorder', 'templateid', 'showlistoncoursepage',
    'coursepageshortinfo', 'sendmail', 'copymail', 'sendmailtobooker',
    'daystonotify', 'daystonotify2', 'daystonotifyteachers', 'mailtemplatessource',
    'btncacname', 'lblteachname', 'lblsputtname', 'btnbooknowname',
    'btncancelname', 'lblbooking', 'lbllocation', 'lblinstitution',
    'lblname', 'lblsurname', 'booktootherbooking', 'lblacceptingfrom',
    'lblnumofusers', 'cancancelbook', 'allowupdate', 'allowupdatedays',
    'autoenrol', 'addtogroup', 'maxperuser', 'showinapi',
    'numgenerator', 'paginationnum', 'banusernames', 'completionmodule',
    'comments', 'ratings', 'removeuseronunenrol', 'conectedbooking',
    'teacherroleid', 'assessed',
];

/** Template properties shown in an editor: [id of the editable area, property]. */
const EDITOR_FIELDS = [
    ['id_introeditoreditable', 'intro'],
    ['id_bookedtexteditable', 'bookedtext'],
    ['id_waitingtexteditable', 'waitingtext'],
    ['id_notifyemaileditable', 'notifyemail'],
    ['id_notifyemailteacherseditable', 'notifyemailteachers'],
    ['id_statuschangetexteditable', 'statuschangetext'],
    ['id_userleaveeditable', 'userleave'],
    ['id_deletedtexteditable', 'deletedtext'],
    ['id_bookingchangedtexteditable', 'bookingchangedtext'],
    ['id_pollurltexteditable', 'pollurltext'],
    ['id_pollurlteacherstexteditable', 'pollurlteacherstext'],
    ['id_activitycompletiontexteditable', 'activitycompletiontext'],
    ['id_bookingpolicyeditable', 'bookingpolicy'],
    ['id_beforecompletedtexteditable', 'beforecompletedtext'],
    ['id_aftercompletedtexteditable', 'aftercompletedtext'],
    ['id_beforebookedtexteditable', 'beforebookedtext'],
];

/**
 * Set the value of a form field, if the field exists and the template carries the property.
 *
 * @param {String} id
 * @param {*} value
 */
const setValue = (id, value) => {
    const field = document.getElementById(id);
    if (field && value !== undefined && value !== null) {
        field.value = value;
    }
};

/**
 * Set the content of an editor's editable area.
 *
 * @param {String} id
 * @param {String} html
 */
const setHtml = (id, html) => {
    const area = document.getElementById(id);
    if (area && html !== undefined && html !== null) {
        area.innerHTML = html;
    }
};

/**
 * Select the given values in a (multi) select.
 *
 * @param {String} id
 * @param {Array} values
 */
const setSelected = (id, values) => {
    const select = document.getElementById(id);
    if (!select || !select.options) {
        return;
    }
    const wanted = values.map((value) => String(value));
    Array.from(select.options).forEach((option) => {
        option.selected = wanted.includes(option.value);
    });
};

/**
 * Copy the template into the form.
 *
 * @param {Object} template
 */
const applyTemplate = (template) => {
    VALUE_FIELDS.forEach((key) => setValue('id_' + key, template[key]));
    EDITOR_FIELDS.forEach(([id, key]) => setHtml(id, template[key]));
    // Categories are stored as a comma separated list of ids.
    if (template.categoryid !== undefined && template.categoryid !== null) {
        setSelected('id_categoryid', String(template.categoryid).split(',').filter((id) => id !== ''));
    }
};

/**
 * Load the chosen template whenever the template selector changes.
 */
export const init = () => {
    const selector = document.getElementById('id_instancetemplateid');
    if (!selector) {
        return;
    }
    selector.addEventListener('change', () => {
        if (selector.value === '') {
            return;
        }
        Promise.resolve(Ajax.call([{
            methodname: 'mod_booking_instancetemplate',
            args: {id: selector.value},
        }])[0])
            .then((data) => applyTemplate(JSON.parse(data.template)))
            .catch(Notification.exception);
    });
};
