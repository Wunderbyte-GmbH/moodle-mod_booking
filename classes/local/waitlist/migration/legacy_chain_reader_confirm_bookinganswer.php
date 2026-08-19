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
 * Reads the confirm_bookinganswer chain's legacy state (M2, WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md
 * §3.2) from a pending, untouched \mod_booking\task\confirm_bookinganswer_by_rule_adhoc DIRECT
 * task - unlike the mail chain, this represents a single currently-open offer for exactly one
 * user, not an accumulating array (confirm_bookinganswer::execute(), classes/booking_rules/
 * actions/confirm_bookinganswer.php). The repeat task (re-trigger only, no state of its own) is
 * deliberately not read here.
 *
 * Note: a send_mail_interval-driven chain also queues a confirm_bookinganswer companion task for
 * its own directly-mailed user (send_mail_interval::execute() calls both actions unconditionally)
 * - upgrade_step's own de-duplication (an optionid/userid pair already migrated by an earlier
 * reader in the same run is skipped) is what prevents that from being double-reconstructed here.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist\migration;

use mod_booking\task\confirm_bookinganswer_by_rule_adhoc;
use stdClass;

/**
 * Reader for the confirm_bookinganswer chain's direct-task format.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class legacy_chain_reader_confirm_bookinganswer implements legacy_chain_reader {
    /** @var string the {task_adhoc}.classname value this reader looks for. */
    private const CLASSNAME = '\\' . confirm_bookinganswer_by_rule_adhoc::class;

    /**
     * {@inheritDoc}
     */
    public function can_read(stdClass $taskrecord): bool {
        if (($taskrecord->classname ?? '') !== self::CLASSNAME) {
            return false;
        }

        $customdata = json_decode($taskrecord->customdata ?? '');
        if (empty($customdata) || !empty($customdata->repeat)) {
            // Only the direct task represents a single currently-open offer - the repeat task
            // is just a re-trigger, carrying no state of its own worth migrating.
            return false;
        }

        return !empty($customdata->optionid) && !empty($customdata->userid);
    }

    /**
     * {@inheritDoc}
     */
    public function extract(stdClass $taskrecord): legacy_chain_state {
        $customdata = json_decode($taskrecord->customdata);

        return new legacy_chain_state(
            (int) $customdata->optionid,
            (int) ($customdata->ruleid ?? 0),
            [(int) $customdata->userid],
            (int) $taskrecord->nextruntime
        );
    }
}
