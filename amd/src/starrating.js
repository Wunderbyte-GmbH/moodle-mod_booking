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
 * Star rating widget without dependencies.
 *
 * Turns a <select> with numeric options into a row of clickable stars. It replaces the jQuery Bar
 * Rating plugin and deliberately produces the same markup and class names (br-wrapper,
 * br-theme-css-stars, br-widget, br-selected, br-active, br-current, br-readonly), so the star styles
 * in styles.css apply unchanged. The select stays in the page as the source of truth.
 *
 * @module     mod_booking/starrating
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const CLASSES = {
    wrapper: 'br-wrapper',
    theme: 'br-theme-css-stars',
    widget: 'br-widget',
    selected: 'br-selected',
    active: 'br-active',
    current: 'br-current',
    readonly: 'br-readonly',
    currentrating: 'br-current-rating',
};

/**
 * @typedef {Object} StarRating
 * @property {function(*): void} set Show the given rating (no callback is fired).
 * @property {function(boolean): void} setReadonly Lock or unlock the widget.
 * @property {function(): string} get The current rating, '' if none.
 */

/**
 * Build the star widget for a select element.
 *
 * @param {HTMLSelectElement} select Select whose options carry the possible ratings as values.
 * @param {Object} [options]
 * @param {*} [options.initialRating] Rating shown at the start; empty for none.
 * @param {boolean} [options.readonly] Start locked.
 * @param {function(string, string, Event): void} [options.onSelect] Called with value, label and the
 *        triggering event when a user picks a rating.
 * @return {StarRating|null} Controller, or null if the element is missing or already initialised.
 */
export const init = (select, options = {}) => {
    if (!select || select.dataset.starratingInitialised) {
        return null;
    }
    select.dataset.starratingInitialised = '1';

    const ratings = Array.from(select.options)
        .filter((option) => option.value !== '')
        .map((option) => ({value: option.value, text: option.textContent.trim()}));

    const wrapper = document.createElement('div');
    wrapper.classList.add(CLASSES.wrapper, CLASSES.theme);
    select.parentNode.insertBefore(wrapper, select);
    wrapper.appendChild(select);
    select.style.display = 'none';

    const widget = document.createElement('div');
    widget.classList.add(CLASSES.widget);
    widget.setAttribute('role', 'group');
    if (select.getAttribute('aria-label')) {
        widget.setAttribute('aria-label', select.getAttribute('aria-label'));
    }

    const stars = ratings.map((rating) => {
        const star = document.createElement('a');
        star.href = '#';
        star.setAttribute('role', 'button');
        star.dataset.ratingValue = rating.value;
        star.dataset.ratingText = rating.text;
        star.setAttribute('aria-label', rating.text + ' / ' + ratings.length);
        widget.appendChild(star);
        return star;
    });

    const currentrating = document.createElement('div');
    currentrating.classList.add(CLASSES.currentrating);
    widget.appendChild(currentrating);
    wrapper.appendChild(widget);

    const state = {value: '', readonly: false};

    /**
     * Highlight all stars up to the given one with a class.
     *
     * @param {string} classname
     * @param {number} upto Index of the last highlighted star, -1 for none.
     */
    const highlight = (classname, upto) => {
        stars.forEach((star, index) => star.classList.toggle(classname, index <= upto));
    };

    const render = () => {
        const index = stars.findIndex((star) => star.dataset.ratingValue === state.value);
        highlight(CLASSES.selected, index);
        stars.forEach((star, i) => {
            star.classList.toggle(CLASSES.current, i === index);
            star.setAttribute('aria-pressed', i === index ? 'true' : 'false');
            star.setAttribute('aria-disabled', state.readonly ? 'true' : 'false');
            star.tabIndex = state.readonly ? -1 : 0;
        });
        currentrating.textContent = index === -1 ? '' : stars[index].dataset.ratingText;
        widget.classList.toggle(CLASSES.readonly, state.readonly);
    };

    const set = (value) => {
        const wanted = (value === null || value === undefined) ? '' : String(value);
        state.value = ratings.some((rating) => rating.value === wanted) ? wanted : '';
        select.value = state.value;
        render();
    };

    const setReadonly = (readonly) => {
        state.readonly = !!readonly;
        highlight(CLASSES.active, -1);
        render();
    };

    stars.forEach((star, index) => {
        star.addEventListener('click', (e) => {
            e.preventDefault();
            if (state.readonly) {
                return;
            }
            set(star.dataset.ratingValue);
            if (typeof options.onSelect === 'function') {
                options.onSelect(state.value, star.dataset.ratingText, e);
            }
        });
        // A link reacts to Enter natively; a button is expected to react to Space as well.
        star.addEventListener('keydown', (e) => {
            if (e.key === ' ' || e.key === 'Spacebar') {
                e.preventDefault();
                star.click();
            }
        });
        star.addEventListener('mouseenter', () => {
            if (!state.readonly) {
                highlight(CLASSES.active, index);
            }
        });
    });
    widget.addEventListener('mouseleave', () => highlight(CLASSES.active, -1));

    state.readonly = !!options.readonly;
    set(options.initialRating);

    return {set, setReadonly, get: () => state.value};
};
