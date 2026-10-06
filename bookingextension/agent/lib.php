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
 * Booking extension library.
 *
 * @package     bookingextension_agent
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Fragment callback: render the AI agent panel for an arbitrary context.
 *
 * Used by the navbar magic wand to load the same aiinstructions panel that
 * mod/booking's view.php renders inline — aiready is context-agnostic since
 * the context consolidation, so any module/course/system context works.
 *
 * @param array|object $args expects 'contextid'
 * @return string rendered panel HTML
 */
function bookingextension_agent_output_fragment_aipanel($args): string {
    global $OUTPUT, $USER;

    $args = (array)$args;
    $contextid = (int)($args['contextid'] ?? 0);
    $context = \context::instance_by_id($contextid, MUST_EXIST);

    require_capability('bookingextension/agent:useaiinstructions', $context);

    $aiready = new \bookingextension_agent\local\wizard\aiready($contextid, (int)$USER->id);

    return $OUTPUT->render_from_template('bookingextension_agent/aiinstructions', $aiready->export_for_template());
}
