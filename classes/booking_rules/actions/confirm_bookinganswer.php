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
 * Legacy rule action, kept only so existing booking_rules rows referencing
 * actionname="confirm_bookinganswer" stay loadable (rule listing/editing UI instantiates actions
 * by name). Deliberately a no-op since the waitlist-progression refactoring (Phase 3,
 * WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §2.5): granting waitlist confirmation on notification
 * is now progression::offer()'s job (local/waitlist/progression.php,
 * grant_confirmation_if_required()), driven by the trigger adapters in classes/event/observer/,
 * not through this rule-engine dispatch path anymore.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_rules\actions;

use mod_booking\booking_rules\booking_rule_action;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * No-op rule action, kept only for backward compatibility with existing rule rows.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirm_bookinganswer implements booking_rule_action {
    /** @var string $actionname */
    public $actionname = 'confirm_bookinganswer';

    /** @var int|null $ruleid unused now (execute() is a no-op) - kept declared because
     *  rule_react_on_event::execute() unconditionally sets $action->ruleid on every action,
     *  regardless of type. */
    public $ruleid = null;

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a rule action record from DB
     */
    public function set_actiondata(stdClass $record) {
        // Nothing to set.
    }

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking rule
     */
    public function set_actiondata_from_json(string $json) {
        // Nothing to set.
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param array $repeateloptions
     * @return void
     */
    public function add_action_to_mform(MoodleQuickForm &$mform, array &$repeateloptions) {
        // No form.
    }

    /**
     * Get the name of the rule action
     * @param bool $localized
     * @return string the name of the rule action
     */
    public function get_name_of_action($localized = true) {
        return get_string('confirmbookinganswer', 'mod_booking');
    }

    /**
     * Is the booking rule action compatible with the current form data?
     * @param array $ajaxformdata the ajax form data entered by the user
     * @return bool true if compatible, else false
     */
    public function is_compatible_with_ajaxformdata(array $ajaxformdata = []) {
        return false;
    }

    /**
     * Save the JSON for all sendmail_daysbefore rules defined in form.
     * @param stdClass $data form data reference
     */
    public function save_action(stdClass &$data): void {
        // Nothing to save.
    }

    /**
     * Sets the rule defaults when loading the form.
     * @param stdClass $data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {
        // Nothing to set.
    }

    /**
     * Execute the action.
     *
     * Intentionally empty - see class docblock.
     *
     * @param stdClass $record
     */
    public function execute(stdClass $record) {
        // Intentionally empty - see class docblock.
    }
}
