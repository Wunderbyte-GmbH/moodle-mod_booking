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
 * Optimistic UI for the booking-favorite toggle button.
 *
 * Attaches a single delegated click listener to document.body that handles
 * all [data-type="wb_action_button"][data-methodname="toggle_favorite"] clicks:
 *   - prevents the default href="#" scroll-to-top behaviour
 *   - immediately toggles the star icon and tooltip text (optimistic UI)
 *
 * The actual AJAX toggle and table reload are handled by local_wunderbyte_table/actionbutton.
 *
 * @module     mod_booking/bookingfavorite
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTOR = '[data-type="wb_action_button"][data-methodname="toggle_favorite"]';

/**
 * Initialise the delegated click handler (safe to call multiple times).
 */
export const init = () => {
    if (document.body.dataset.bookingFavoriteDelegated) {
        return;
    }
    document.body.dataset.bookingFavoriteDelegated = 'true';

    document.body.addEventListener('click', (e) => {
        const btn = e.target.closest(SELECTOR);
        if (!btn) {
            return;
        }

        // Prevent href="#" from scrolling the page to the top.
        e.preventDefault();

        // Optimistic UI: toggle icon and tooltip immediately.
        const icon = btn.querySelector('i');
        if (!icon) {
            return;
        }

        const isFavorite = btn.dataset.favorited === '1';

        if (isFavorite) {
            icon.classList.remove('fa-star');
            icon.classList.add('fa-star-o');
            btn.dataset.favorited = '0';
            btn.setAttribute('title', btn.dataset.offtitle || '');
        } else {
            icon.classList.remove('fa-star-o');
            icon.classList.add('fa-star');
            btn.dataset.favorited = '1';
            btn.setAttribute('title', btn.dataset.ontitle || '');
        }
    });
};
