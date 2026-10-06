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
 * Admin setting for the documentation corpora textarea.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\admin;

use bookingextension_agent\local\wizard\services\lookup\corpus_source_parser;

/**
 * Textarea setting that validates each documentation corpus line.
 *
 * Lines whose target escapes $CFG->dirroot, or that are otherwise unparseable, are reported back to
 * the admin and block the save (the E2 confinement is hard). Lines that are syntactically fine but
 * point at a directory that does not exist yet are allowed (declared-but-unresolvable): the index
 * keeps such a corpus's data and picks it up once the directory appears.
 */
class setting_docs_corpora extends \admin_setting_configtextarea {
    /**
     * Validate the submitted textarea.
     *
     * @param string $data
     * @return string|true true on success; an error message blocks the save.
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }

        $parsed = corpus_source_parser::parse((string)$data);
        if (!empty($parsed['warnings'])) {
            return implode("\n", array_map('s', $parsed['warnings']));
        }

        return true;
    }
}
