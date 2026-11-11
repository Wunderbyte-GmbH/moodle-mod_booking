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
 * Tests for the PDF download helper.
 *
 * @package     mod_booking
 * @category    test
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

/**
 * Tests for the PDF download helper.
 *
 * @package     mod_booking
 * @category    test
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\pdf_download
 */
final class pdf_download_test extends \advanced_testcase {
    /**
     * Output printed while the PDF was rendered (a PHP warning about an image) is removed from the buffer,
     * because TCPDF refuses to send a document as long as ob_get_contents() returns anything.
     */
    public function test_stray_output_is_discarded_and_returned(): void {
        $warning = '<b>Warning</b>: imagecreatefrompng(): "https://example.com/logo.png" is not a valid PNG file';
        $levelbefore = ob_get_level();
        ob_start();
        try {
            echo $warning;

            $this->assertSame($warning, pdf_download::discard_stray_output());

            // This is the condition TCPDF::Output() checks before it sends a download.
            $this->assertSame('', ob_get_contents());
            // The buffer is emptied, not closed: its owner still has to close it.
            $this->assertSame($levelbefore + 1, ob_get_level());
            // Nothing left to discard.
            $this->assertSame('', pdf_download::discard_stray_output());
        } finally {
            ob_end_clean();
        }
    }
}
