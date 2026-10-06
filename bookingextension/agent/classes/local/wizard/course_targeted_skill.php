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
 * Shared cross-context targeting for course-scoped skills.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

use bookingextension_agent\local\wizard\dto\target_selector;

/**
 * Skills that operate on a course resolved from courseid/coursequery share the exact same
 * cross-context targeting contract. This trait removes the verbatim copies that previously lived in
 * each of those skills (add/update_activity, add/update_quiz, generate_questions,
 * analyze_course_structure).
 */
trait course_targeted_skill {
    /**
     * These skills can run against a target course different from the current page context.
     *
     * @return bool
     */
    public function supports_target_context(): bool {
        return true;
    }

    /**
     * Resolve the operating-context selector from the command's courseid/coursequery, or null when
     * neither is provided (then the current context applies).
     *
     * @param array $input
     * @return target_selector|null
     */
    public function get_target_selector(array $input): ?target_selector {
        $courseid = (int)($input['courseid'] ?? 0);
        $coursequery = trim((string)($input['coursequery'] ?? ''));
        if ($courseid <= 0 && $coursequery === '') {
            return null;
        }
        return target_selector::for_course($courseid > 0 ? $courseid : null, $coursequery !== '' ? $coursequery : null);
    }
}
