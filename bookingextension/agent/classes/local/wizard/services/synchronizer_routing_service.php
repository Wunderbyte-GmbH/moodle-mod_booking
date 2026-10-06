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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\orchestrator;

/**
 * Routes synchronizer finalization through the existing orchestrator entrypoint.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class synchronizer_routing_service {
    /**
     * Call the synchronizer route using the planner-safe retrieval step.
     *
     * @param orchestrator $orchestrator
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param array $observations
     * @param string $continuation Continuation marker (synchronizer_prompt_builder::CONTINUATION_*); default none.
     * @param string[] $omittedfields Detail fields read skills did not look up this turn.
     * @param string[] $activetokens Anonymizer tokens active in this thread.
     * @param string $replylanguage ISO code of the conversation's reply language, '' = none.
     * @return array
     */
    public function call_synchronizer_step(
        orchestrator $orchestrator,
        int $threadid,
        int $contextid,
        int $userid,
        array $observations,
        string $continuation = synchronizer_prompt_builder::CONTINUATION_NONE,
        array $omittedfields = [],
        array $activetokens = [],
        string $replylanguage = ''
    ): array {
        return $orchestrator->process_synchronizer(
            $threadid,
            $contextid,
            $userid,
            $observations,
            $continuation,
            $omittedfields,
            $activetokens,
            $replylanguage
        );
    }
}
