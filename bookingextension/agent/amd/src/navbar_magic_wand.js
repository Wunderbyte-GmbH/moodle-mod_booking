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
 * Global navbar magic-wand entry point for the AI agent.
 *
 * Injected on every page by bookingextension_agent\local\hooks\page_injection
 * when the inject_in_navbar admin setting is enabled, so it MUST stay minimal:
 * no static imports (nothing beyond this tiny module is fetched on page load),
 * no AJAX, no string requests — the button label arrives as a parameter from
 * PHP. Everything heavy (modal, templates, fragment with the aiready panel)
 * is dynamically imported and loaded only on the first click.
 *
 * @module     bookingextension_agent/navbar_magic_wand
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const BUTTON_ID = 'bookingextension-agent-navbar-wand';

// The inline panel rendered by mod/booking's view.php carries this id. When it
// is present we must drive THAT panel instead of loading a second copy into a
// modal — the whole aiinstructions module looks elements up by fixed global id,
// so two live copies would collide on the first one in document order.
const INLINE_WRAPPER_ID = 'booking-ai-wrapper';

let modalPromise = null;

// Resolves once the panel fragment has been put into the modal body, so the first click can put
// the cursor into the input as soon as it exists (and not before).
let panelPromise = null;

/**
 * Reuse an already-rendered inline agent panel instead of opening the modal.
 *
 * On mod/booking's view.php the panel is rendered (and init()'d) inline inside a
 * Bootstrap earmark tab. Loading the navbar fragment there would duplicate every
 * fixed `booking-ai-*` id, and aiinstructions.js would then operate on whichever
 * copy is first in the DOM (the inline one) regardless of which entry point was
 * clicked. So when the inline panel exists we just bring its tab forward.
 *
 * @returns {Boolean} true if an inline panel was found and focused, false otherwise
 */
const focusInlinePanel = () => {
    const wrapper = document.getElementById(INLINE_WRAPPER_ID);
    // Only a GENUINE inline panel (mod/booking view.php tab) counts here. Ignore a wrapper that
    // belongs to our own navbar modal: core/modal keeps the (hidden) modal in the DOM after close,
    // so its panel's #booking-ai-wrapper would otherwise make every later click "focus" the
    // invisible modal instead of re-opening it.
    if (!wrapper || wrapper.closest('.bookingextension-agent-wand-modal')) {
        return false;
    }

    // Activate the enclosing earmark tab, if any. The nav-link toggles the pane
    // via Bootstrap's delegated handler; a plain click works for both the BS4
    // (data-toggle) and BS5 (data-bs-toggle) markup the earmark emits, so we
    // avoid importing the Tab plugin and stay theme-agnostic.
    const pane = wrapper.closest('.tab-pane');
    if (pane && pane.id) {
        const navlink = document.querySelector(
            'a[data-toggle="tab"][aria-controls="' + pane.id + '"],'
            + 'a[data-bs-toggle="tab"][aria-controls="' + pane.id + '"],'
            + 'a[data-toggle="tab"][href="#' + pane.id + '"],'
            + 'a[data-bs-toggle="tab"][href="#' + pane.id + '"]'
        );
        if (navlink && !navlink.classList.contains('active')) {
            navlink.click();
        }
    }

    wrapper.scrollIntoView({behavior: 'smooth', block: 'start'});

    const input = document.getElementById('booking-ai-input');
    if (input) {
        // Defer focus until the tab/scroll settles so it isn't stolen back.
        window.setTimeout(() => input.focus(), 300);
    }

    return true;
};

/**
 * Load the agent panel fragment into the modal body.
 *
 * The fragment returns the rendered aiinstructions template plus its
 * collected {{#js}} footer markup; replaceNodeContents executes that JS,
 * which boots aiinstructions.js inside the modal.
 *
 * @param {Object} modal core/modal instance
 * @param {Number} contextid current page context id
 * @returns {Promise} resolving once the panel markup sits in the modal body
 */
const loadPanel = async(modal, contextid) => {
    const Fragment = await import('core/fragment');
    const Templates = await import('core/templates');

    try {
        const rendered = await new Promise((resolve, reject) => {
            Fragment.loadFragment('bookingextension_agent', 'aipanel', contextid, {contextid: contextid})
                .done((html, js) => resolve({html: html, js: js}))
                .fail(reject);
        });
        await Templates.replaceNodeContents(modal.getBody(), rendered.html, rendered.js);
    } catch (ex) {
        const Notification = await import('core/notification');
        Notification.exception(ex);
    }
};

/**
 * Put the cursor into the agent's input, so the user can type right away.
 *
 * Called after the modal reports itself shown - core/modal focuses the dialog itself while
 * showing, so focusing earlier would be taken back.
 */
const focusPanelInput = () => {
    const input = document.getElementById('booking-ai-input');
    if (input) {
        input.focus();
    }
};

/**
 * Lazily create the (cached) agent modal. First call pulls in core/modal
 * and the panel fragment; later clicks just re-show the same instance.
 *
 * @param {Number} contextid current page context id
 * @param {String} title modal title (localised, from PHP)
 * @returns {Promise<Object>} resolving to the core/modal instance
 */
const getModal = (contextid, title) => {
    if (!modalPromise) {
        modalPromise = (async() => {
            const Modal = await import('core/modal');
            const modal = await Modal.create({
                title: title,
                body: '<div class="d-flex justify-content-center p-5">'
                    + '<div class="spinner-border" role="status"></div></div>',
                large: true,
            });
            // The preview needs more room than Bootstrap's modal-lg offers.
            // getModal() returns the .modal-dialog itself: modal-xl is the
            // Bootstrap-native baseline (1140px), the hook class widens it
            // further via --bs-modal-width in styles.css.
            modal.getModal().addClass('modal-xl bookingextension-agent-wand-modal');
            panelPromise = loadPanel(modal, contextid);
            return modal;
        })();
    }
    return modalPromise;
};

/**
 * Build the navbar wand element.
 *
 * @param {String} label localised button label
 * @returns {HTMLElement}
 */
const buildButton = (label) => {
    const wrapper = document.createElement('div');
    wrapper.id = BUTTON_ID;
    wrapper.className = 'd-flex align-items-center';

    const link = document.createElement('a');
    link.className = 'nav-link px-2';
    link.href = '#';
    link.setAttribute('role', 'button');
    link.setAttribute('aria-label', label);
    link.setAttribute('title', label);
    link.innerHTML = '<i class="fa fa-magic" aria-hidden="true"></i>';

    wrapper.appendChild(link);
    return wrapper;
};

/**
 * Entry point: inject the wand into the navbar. Pure DOM work, no requests.
 *
 * Targets the Boost user-navigation region and falls back to the generic
 * navbar nav list; if neither exists (exotic theme) it does nothing.
 *
 * @param {Number} contextid current page context id (from the PHP hook)
 * @param {String} label localised button label (from the PHP hook)
 * @param {Object} pagecontext free $PAGE snapshot (pagetype, url, course, activity) from the PHP hook
 */
export const init = (contextid, label, pagecontext = {}) => {
    // Stash the current-page snapshot (per tab, via sessionStorage) so aiinstructions can send it with
    // each message. Written on every page load before any early return, so it always reflects the page
    // the user is actually on. Best-effort: failures here never affect the wand.
    try {
        window.sessionStorage.setItem('wizard_pagecontext', JSON.stringify(pagecontext || {}));
    } catch (e) {
        window.console.log('wizard: page context not stored', e);
    }

    if (document.getElementById(BUTTON_ID)) {
        return;
    }

    const host = document.querySelector('#usernavigation')
        || document.querySelector('.navbar .navbar-nav');
    if (!host) {
        return;
    }

    const button = buildButton(label);
    const usermenu = host.querySelector('.usermenu-container, .usermenu');
    if (usermenu && usermenu.parentElement === host) {
        host.insertBefore(button, usermenu);
    } else {
        host.appendChild(button);
    }

    button.addEventListener('click', async(e) => {
        e.preventDefault();
        // Prefer an inline panel (view.php) over a second modal copy.
        if (focusInlinePanel()) {
            return;
        }
        const modal = await getModal(contextid, label);
        await modal.show();
        // Wait for the panel itself on the first click; on later clicks it is long there.
        await panelPromise;
        focusPanelInput();
    });
};
