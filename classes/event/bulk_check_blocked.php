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
 * A rule mail was parked by the bulk send checker.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

use coding_exception;
use moodle_url;

/**
 * The bulk_check_blocked event class.
 *
 * Fired in the system context, because booking rules can live there and the parked list is
 * site wide. Carries the rule in 'other', and the ids of the rows it is about.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_check_blocked extends \core\event\base {
    /**
     * Init.
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Get name.
     * @return string
     */
    public static function get_name() {
        return get_string('eventbulkcheckblocked', 'mod_booking');
    }

    /**
     * Get description.
     * @return string
     */
    public function get_description() {
        $count = (int) ($this->other['count'] ?? 1);
        return "Blocked: $count rule mail(s) of booking rule with id '" . $this->other['ruleid'] . "'.";
    }

    /**
     * Get url.
     * @return moodle_url
     */
    public function get_url() {
        return new moodle_url('/mod/booking/bulkcheck.php', ['ruleid' => $this->other['ruleid']]);
    }

    /**
     * Custom validation.
     *
     * @throws coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['ruleid'])) {
            throw new coding_exception("The 'ruleid' value must be set in other.");
        }
    }
}
