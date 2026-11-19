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
 * Reads the content of a stored file that may be missing in the file storage.
 *
 * @package     mod_booking
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use stored_file;

/**
 * Reads the content of a stored file that may be missing in the file storage.
 *
 * A stored_file is only the database record of a file. Its content lives in the file storage (filedir of the
 * dataroot or an object storage) under the content hash, and the two can get out of step: a database copied
 * without its dataroot, an incomplete restore, a cleaned up filedir. stored_file::get_content() then raises a
 * PHP warning and returns false. For optional decoration such as a logo or a user picture in a document this
 * must not be an error, so the content is read here and null says "leave the image out".
 *
 * @package     mod_booking
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_content {
    /** @var array content hashes already reported in this request, so a footer on 30 pages logs once */
    private static array $reported = [];

    /**
     * Content of the file, or null when there is none to use.
     *
     * @param stored_file $file
     * @return string|null the content; null for an empty file and for a file whose content cannot be read
     */
    public static function read(stored_file $file): ?string {
        if (!$file->get_filesize()) {
            return null;
        }
        try {
            // The warning of file_get_contents() is the only way core reports the missing content; it is
            // replaced by the log entry below. The return value is checked, so nothing is swallowed.
            $content = @$file->get_content();
        } catch (\Throwable $e) {
            // Other file systems (object storage) may throw instead.
            $content = false;
        }
        if (!is_string($content) || $content === '') {
            $hash = $file->get_contenthash();
            if (!isset(self::$reported[$hash])) {
                self::$reported[$hash] = true;
                // Not debugging(): it would print into the output of the document that is being generated.
                // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
                error_log('mod_booking: content of stored file is missing in the file storage: ' .
                    $file->get_component() . '/' . $file->get_filearea() . '/' . $file->get_itemid() .
                    $file->get_filepath() . $file->get_filename() .
                    ' (file id ' . $file->get_id() . ', contenthash ' . $hash . ')');
            }
            return null;
        }
        return $content;
    }
}
