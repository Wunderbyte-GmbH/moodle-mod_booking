<?php
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
 * Form support for the sqlfilter checkboxes of availability conditions.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability;

use context_system;
use moodle_url;
use MoodleQuickForm;

/**
 * Form support for the sqlfilter checkboxes of availability conditions.
 */
class sqlfilter_form_support {
    /**
     * Freeze a sqlfilter checkbox when the site-wide SQL filter feature is off.
     *
     * When booking/usesqlfilteravailability is disabled, bo_info skips the whole
     * SQL filter machinery, so a ticked checkbox silently does nothing. This
     * renders the checkbox disabled and appends a static note explaining why;
     * users who can change the setting get a direct link to it. A soft freeze
     * (not hardFreeze) keeps the stored value: the frozen advcheckbox exports
     * its default and still posts it via a hidden input, so nothing is wiped
     * while the feature is off.
     *
     * @param MoodleQuickForm $mform The booking option form.
     * @param string $checkboxname Name of the sqlfilter advcheckbox element.
     * @return string|null Name of the added note element (so callers can mirror
     *                     hideIf rules), or null when the feature is enabled.
     */
    public static function freeze_when_disabled(MoodleQuickForm $mform, string $checkboxname): ?string {
        if (get_config('booking', 'usesqlfilteravailability')) {
            return null;
        }

        $mform->freeze($checkboxname);

        $note = get_string(
            'sqlfilterdisablednote',
            'mod_booking',
            get_string('usesqlfilteravailability', 'mod_booking')
        );
        if (has_capability('moodle/site:config', context_system::instance())) {
            $url = new moodle_url(
                '/admin/settings.php',
                ['section' => 'modsettingbooking'],
                'admin-usesqlfilteravailability'
            );
            $note .= ' ' . get_string('sqlfilterdisablednoteadmin', 'mod_booking', $url->out());
        }

        $notename = $checkboxname . '_disablednote';
        $mform->addElement('static', $notename, '', $note);
        return $notename;
    }
}
