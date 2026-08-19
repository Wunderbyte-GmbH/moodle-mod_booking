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
 * Reads the send_mail_interval mail chain's legacy state (M1,
 * WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §3.2) from a pending \mod_booking\task\
 * send_mail_by_rule_adhoc repeat task. Only the repeat task (customdata->repeat == 1) carries the
 * full, current usersalreadytreated snapshot - see send_mail_interval::execute() (classes/
 * booking_rules/actions/send_mail_interval.php), which stores it inside customdata->rulejson (a
 * JSON string, itself nested inside the task's own JSON customdata) and re-persists it on every
 * chain iteration. This is the one concretely fixture-verified format
 * (tests/booking_rules/waitlist_old_chain_fixture_trait.php::build_running_mail_interval_chain()) -
 * see legacy_chain_reader's docblock for how unrecognised older formats are handled.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist\migration;

use mod_booking\task\send_mail_by_rule_adhoc;
use stdClass;

/**
 * Reader for the send_mail_interval mail chain's repeat-task format.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class legacy_chain_reader_send_mail_interval implements legacy_chain_reader {
    /** @var string the {task_adhoc}.classname value this reader looks for. */
    private const CLASSNAME = '\\' . send_mail_by_rule_adhoc::class;

    /**
     * {@inheritDoc}
     */
    public function can_read(stdClass $taskrecord): bool {
        if (($taskrecord->classname ?? '') !== self::CLASSNAME) {
            return false;
        }

        $customdata = json_decode($taskrecord->customdata ?? '');
        if (empty($customdata) || empty($customdata->repeat)) {
            // Only the repeat task carries the full usersalreadytreated snapshot - the direct
            // mail task for the most recently treated user is not itself a chain state.
            return false;
        }

        $rulejson = json_decode($customdata->rulejson ?? '');
        return !empty($rulejson) && isset($rulejson->intervaldata->usersalreadytreated);
    }

    /**
     * {@inheritDoc}
     */
    public function extract(stdClass $taskrecord): legacy_chain_state {
        $customdata = json_decode($taskrecord->customdata);
        $rulejson = json_decode($customdata->rulejson);
        $usersalreadytreated = array_map('intval', (array) $rulejson->intervaldata->usersalreadytreated);

        return new legacy_chain_state(
            (int) $customdata->optionid,
            (int) $customdata->ruleid,
            $usersalreadytreated,
            (int) $taskrecord->nextruntime
        );
    }
}
