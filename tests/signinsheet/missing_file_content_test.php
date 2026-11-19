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
 * Sign-in sheet with logos and user pictures whose content is missing in the file storage.
 *
 * @package     mod_booking
 * @category    test
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_system;
use context_user;
use mod_booking\local\file_content;
use mod_booking\signinsheet\signinsheet_config;
use mod_booking\signinsheet\signinsheet_generator;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;
use stored_file;

/**
 * Sign-in sheet with logos and user pictures whose content is missing in the file storage.
 *
 * The files table still lists the file, but filedir has no content for it (database copied without its
 * dataroot, incomplete restore). Reading it raises a PHP warning; printed into the output buffer, that
 * warning made the download fail with "TCPDF ERROR: Some data has already been output". The sheet has to be
 * generated without the image and without any warning.
 *
 * @package     mod_booking
 * @category    test
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\file_content
 * @covers \mod_booking\signinsheet\signinsheet_generator
 * @covers \mod_booking\signinsheet\signin_pdf
 */
final class missing_file_content_test extends booking_advanced_testcase {
    /** @var array PHP warnings and notices raised while a sheet was generated */
    private array $phperrors = [];

    /**
     * Sheet template with the header logo and the user picture, on top of the usual columns.
     */
    private const TEMPLATE = '<h1>[[tablename]]</h1><img src="[[logourl]]" width="100" />'
        . '<table class="signaturetable" border="1"><tr><th>Name</th><th>Picture</th></tr>'
        . '[[users]]<tr><td>[[fullname]]</td><td>[[userpic]]</td></tr>[[/users]]</table>';

    /**
     * A small png.
     *
     * @return string
     */
    private function png(): string {
        $image = imagecreatetruecolor(20, 10);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 0, 0));
        ob_start();
        imagepng($image);
        return ob_get_clean();
    }

    /**
     * Stores a png in a file area.
     *
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param string $filename
     * @return stored_file
     */
    private function store_png(int $contextid, string $component, string $filearea, string $filename): stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $this->png() . $filename); // Trailing bytes: every file gets a content hash of its own.
    }

    /**
     * Removes the content of a file from filedir, the files record stays.
     *
     * @param stored_file $file
     * @return void
     */
    private function lose_content(stored_file $file): void {
        global $CFG;
        $hash = $file->get_contenthash();
        $path = "$CFG->dataroot/filedir/" . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        $this->assertFileExists($path);
        unlink($path);
    }

    /**
     * Booking option with one booked user; header logo, footer logo and user picture are stored.
     *
     * @param bool $losecontent remove the content of the three files from filedir
     * @return booking_option
     */
    private function create_booked_option_with_images(bool $losecontent): booking_option {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Sign-in booking',
            'course' => $course->id,
            'signinsheetfields' => ['fullname', 'userpic', 'signature'],
        ]);
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'FOBS 1+2';
        $record->coursestarttime = strtotime('tomorrow 10:00');
        $record->courseendtime = strtotime('tomorrow 12:00');
        $option = $plugingenerator->create_option($record);

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Bianchi']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $user->id]);

        $systemcontextid = context_system::instance()->id;
        $files = [
            $this->store_png($systemcontextid, 'booking', 'mod_booking_signinlogo', 'header.png'),
            $this->store_png($systemcontextid, 'booking', 'mod_booking_signinlogo_footer', 'footer.png'),
            $this->store_png(context_user::instance($user->id)->id, 'user', 'icon', 'f1.png'),
        ];
        set_config('signinlogo', '/header.png', 'booking');
        set_config('signinlogofooter', '/footer.png', 'booking');
        if ($losecontent) {
            foreach ($files as $file) {
                $this->lose_content($file);
            }
        }

        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        return singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
    }

    /**
     * Sign-in sheet generator with the download presets of the plugin.
     *
     * @param booking_option $bookingoption
     * @return signinsheet_generator
     */
    private function create_signinsheet_generator(booking_option $bookingoption): signinsheet_generator {
        $pdfoptions = signinsheet_config::pdfoptions_from_config(signinsheet_config::for_option($bookingoption->optionid));
        $pdfoptions->saveasformat = 'pdf';
        return new signinsheet_generator($pdfoptions, $bookingoption);
    }

    /**
     * Runs the code and returns what it printed; PHP warnings and notices are collected in $this->phperrors.
     *
     * In a web request with debug display these messages are printed into the output buffer, which is what
     * TCPDF checks before it sends the download.
     *
     * @param callable $code
     * @return string the output of the code
     */
    private function run_collecting_errors(callable $code): string {
        $this->phperrors = [];
        set_error_handler(function (int $errno, string $errstr): bool {
            // Like PHP itself: a message silenced with @ is not reported.
            if (error_reporting() & $errno) {
                $this->phperrors[] = $errstr;
            }
            return true;
        });
        // The log entries about the missing content are expected, keep them out of the test output.
        $errorlog = ini_set('error_log', '/dev/null');
        ob_start();
        try {
            $code();
        } finally {
            $output = ob_get_clean();
            ini_set('error_log', (string)$errorlog);
            restore_error_handler();
        }
        return $output;
    }

    /**
     * How often an image is embedded in the PDF.
     *
     * @param \TCPDF $pdf
     * @return int
     */
    private function count_images(\TCPDF $pdf): int {
        $pdf->setCompression(false);
        return substr_count($pdf->Output('signinsheet.pdf', 'S'), '/Subtype /Image');
    }

    /**
     * HTML template mode: logo, footer logo and user picture without content are left out, nothing is printed.
     *
     * @param bool $losecontent
     * @param bool $expectimages
     * @dataProvider content_provider
     */
    public function test_html_template_sheet(bool $losecontent, bool $expectimages): void {
        $bookingoption = $this->create_booked_option_with_images($losecontent);
        set_config('signinsheetmode', 'htmltemplate', 'booking');
        set_config('signinsheethtml', self::TEMPLATE, 'booking');
        set_config('signinsheethtmlfooter', '<p>Footer <img src="[[footerlogourl]]" height="10" /></p>', 'booking');
        $generator = $this->create_signinsheet_generator($bookingoption);

        $html = '';
        $pdf = null;
        $output = $this->run_collecting_errors(function () use ($generator, &$html, &$pdf) {
            $html = $generator->render_html();
            $pdf = $generator->create_pdf_from_html($html);
        });

        $this->assertSame([], $this->phperrors);
        $this->assertSame('', $output);
        $this->assertStringContainsString('Anna', $html);
        $this->assertStringNotContainsString('logourl', $html);
        $this->assertSame($expectimages ? 2 : 0, substr_count($html, '<img'));
        $this->assertSame(1, $pdf->getNumPages());
        // Header logo, user picture and footer logo.
        $this->assertSame($expectimages ? 3 : 0, $this->count_images($pdf));
    }

    /**
     * With and without the content of the files.
     *
     * @return array
     */
    public static function content_provider(): array {
        return [
            'content is there' => [false, true],
            'content is missing in filedir' => [true, false],
        ];
    }

    /**
     * The helper returns the content, and null (without a PHP warning) when the content is gone.
     */
    public function test_file_content_read(): void {
        $this->resetAfterTest();
        $file = $this->store_png(context_system::instance()->id, 'mod_booking', 'test', 'logo.png');
        $empty = get_file_storage()->create_file_from_string([
            'contextid' => context_system::instance()->id,
            'component' => 'mod_booking',
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'empty.png',
        ], '');

        $this->assertSame($this->png() . 'logo.png', file_content::read($file));
        $this->assertNull(file_content::read($empty));

        $this->lose_content($file);
        $content = 'not read';
        $this->run_collecting_errors(function () use ($file, &$content) {
            $content = file_content::read($file);
        });
        $this->assertNull($content);
        $this->assertSame([], $this->phperrors);
    }
}
