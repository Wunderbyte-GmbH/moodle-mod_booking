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
 * Which plugin owns the page the request was made from.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services\discovery;

use context;

/**
 * Resolve the owning skill namespace of a Moodle context and page type.
 *
 * "Which rules are switched on?" means booking rules on a booking page and taskflow rules on a taskflow page.
 * The engine can know that without looking at the request at all: the Moodle context names the module, and
 * Moodle's page-type contract names the component ("mod-booking-view", "local-taskflow-index"). That is
 * structure, not phrase matching — no word of the user's text or of any model output is inspected here.
 *
 * The result is a WEAK preference. It is handed to the family ranker as one signal among several and only
 * ever breaks a near-tie; see family_signal_ranker and family_embeddings_retrieval_service::boost_skill_rows.
 * A hard filter would be wrong: on a booking page the correct answer is regularly a course or core skill
 * (baseline runs 17-19: UA-3, SC-2, ACS-2, EU-2 all sit in a booking module and all need another namespace).
 */
class context_owner_resolver {
    /**
     * The owning skill namespace, or '' when nothing identifies one.
     *
     * @param context $context The Moodle context the request was made in.
     * @param string $pagetype Moodle page type of the page the user was on, '' when unknown.
     * @param string[] $knownnamespaces Namespaces the skill registry actually offers.
     * @return string
     */
    public function resolve(context $context, string $pagetype, array $knownnamespaces): string {
        $known = array_flip(array_map('strval', $knownnamespaces));

        // 1. A module context names its plugin outright — the cheapest and most reliable signal.
        if ((int)$context->contextlevel === CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id('', (int)$context->instanceid, 0, false, IGNORE_MISSING);
            if ($cm && isset($known['mod_' . $cm->modname])) {
                return 'mod_' . $cm->modname;
            }
        }

        // 2. Plugins whose pages live at system or course level can only be recognised by their page type.
        $frompage = $this->namespace_from_pagetype($pagetype);
        if ($frompage !== '' && isset($known[$frompage])) {
            return $frompage;
        }

        // 3. A plain course page belongs to the course skills.
        if ((int)$context->contextlevel === CONTEXT_COURSE && isset($known['course'])) {
            return 'course';
        }

        return '';
    }

    /**
     * Map a Moodle page type to a skill namespace, following Moodle's own naming contract.
     *
     * Page types are component-prefixed by convention: "mod-booking-view", "local-taskflow-index",
     * "course-view". Everything else (site-index, my-index, admin-*) identifies no plugin and yields ''.
     *
     * @param string $pagetype
     * @return string
     */
    private function namespace_from_pagetype(string $pagetype): string {
        $parts = explode('-', trim($pagetype));
        if (count($parts) < 2) {
            return '';
        }

        if (($parts[0] === 'mod' || $parts[0] === 'local') && $parts[1] !== '') {
            return $parts[0] . '_' . $parts[1];
        }

        if ($parts[0] === 'course') {
            return 'course';
        }

        return '';
    }
}
