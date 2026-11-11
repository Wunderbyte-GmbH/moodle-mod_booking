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
 * Sends a generated PDF to the browser as a download.
 *
 * @package     mod_booking
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

/**
 * Sends a generated PDF to the browser as a download.
 *
 * TCPDF refuses to send a document ("Some data has already been output, can't send PDF file") as soon as the
 * output buffer of the request holds a single character. While a PDF is rendered, such output comes from PHP
 * warnings and debugging notices, e.g. when an image of an HTML template is loaded from another server and that
 * server answers with something that is not an image. A notice must not cost the user the whole document, so
 * the buffer is emptied before the download and its content goes to the error log instead.
 *
 * @package     mod_booking
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_download {
    /**
     * Send the PDF as a download, whatever has been printed during the request so far.
     *
     * @param \TCPDF $pdf the finished document
     * @param string $filename name of the downloaded file, with extension
     * @return void
     */
    public static function send(\TCPDF $pdf, string $filename): void {
        $strayoutput = self::discard_stray_output();
        if ($strayoutput !== '') {
            // Not debugging(): with debugdisplay on it would print into the buffer that has just been emptied.
            // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
            error_log('mod_booking: output discarded before sending ' . $filename . ': ' .
                \core_text::substr(trim(html_to_text($strayoutput, 0, false)), 0, 2000));
        }
        $pdf->Output($filename, 'D');
    }

    /**
     * Empty the output buffers of the request and return what they held.
     *
     * The buffers stay open (ob_clean(), not ob_end_clean()): Moodle and PHPUnit expect to close their own.
     *
     * @return string the discarded output, an empty string when nothing had been printed
     */
    public static function discard_stray_output(): string {
        if (ob_get_level() === 0) {
            return '';
        }
        $output = (string)ob_get_contents();
        if ($output !== '') {
            ob_clean();
        }
        return $output;
    }
}
