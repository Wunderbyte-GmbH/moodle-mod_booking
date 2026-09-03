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

namespace mod_booking;

use mod_booking\checklist\checklist_generator;
use mod_booking\signinsheet\signinsheet_config;
use mod_booking\signinsheet\signinsheet_generator;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

/**
 * The template based PDFs (sign-in sheet in HTML mode, checklist) are PDF/A-2b when the
 * setting local_wunderbyte_table/pdfaenabled is on - and exactly the previous output when it is off.
 *
 * Structural checks only; the full ISO validation runs when VERAPDF_BIN points to
 * the veraPDF CLI (local check).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\signinsheet\signinsheet_generator::render_html
 * @covers \mod_booking\signinsheet\signinsheet_generator::create_pdf_from_html
 * @covers \mod_booking\checklist\checklist_generator::render_html
 * @covers \mod_booking\checklist\checklist_generator::create_pdf_from_html
 * @covers \mod_booking\checklist\checklist_pdf
 */
final class pdfa_documents_test extends booking_advanced_testcase {
    /**
     * Fonts that must never appear unembedded (TCPDF core fonts).
     */
    private const COREFONTS = '#/BaseFont\s*/(Helvetica|Times|Courier|Symbol|ZapfDingbats)#';

    /**
     * Enables PDF/A for the tests (off by default in the plugin).
     */
    protected function setUp(): void {
        parent::setUp();
        set_config('pdfaenabled', 1, 'local_wunderbyte_table');
    }

    /**
     * Creates a booking instance with one option and three booked users (one with a profile picture).
     *
     * @return booking_option
     */
    private function create_booked_option(): booking_option {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'PDF/A booking',
            'course' => $course->id,
            // Fields shown on the sign-in sheet (the module form defaults to all of them).
            'signinsheetfields' => ['fullname', 'email', 'signature'],
        ]);
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Yoga für Anfänger:innen – Kurs "A/B"';
        $record->location = 'Halle 1';
        $record->coursestarttime = strtotime('tomorrow 10:00');
        $record->courseendtime = strtotime('tomorrow 12:00');
        $option = $plugingenerator->create_option($record);

        foreach (['Ärzte' => 'Müller', 'Zoë' => 'Łukasz', 'Anna' => 'Bianchi'] as $firstname => $lastname) {
            $user = $this->getDataGenerator()->create_user([
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => strtolower($firstname) . '@example.com',
            ]);
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
            $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $user->id]);
        }
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        return singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
    }

    /**
     * Sign-in sheet generator in HTML template mode.
     *
     * @param booking_option $bookingoption
     * @return signinsheet_generator
     */
    private function create_signinsheet_generator(booking_option $bookingoption): signinsheet_generator {
        set_config('signinsheetmode', 'htmltemplate', 'booking');
        $pdfoptions = signinsheet_config::pdfoptions_from_config(signinsheet_config::for_option($bookingoption->optionid));
        $pdfoptions->saveasformat = 'pdf';
        return new signinsheet_generator($pdfoptions, $bookingoption);
    }

    /**
     * Structural PDF/A-2b assertions.
     *
     * @param string $pdf
     */
    private function assert_pdfa2b(string $pdf): void {
        $this->assertStringStartsWith('%PDF-1.7', $pdf);
        $this->assertStringContainsString('<pdfaid:part>2</pdfaid:part>', $pdf);
        $this->assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $pdf);
        $this->assertStringContainsString('/OutputIntents', $pdf);
        $this->assertDoesNotMatchRegularExpression(self::COREFONTS, $pdf, 'Unembedded core font found');
        $this->assertStringNotContainsString('/DeviceCMYK', $pdf);
        $descriptors = preg_match_all('#/Type\s*/FontDescriptor#', $pdf);
        $this->assertGreaterThan(0, $descriptors);
        $this->assertSame($descriptors, preg_match_all('#/FontFile2\s+\d+ 0 R#', $pdf));
        // Subset fonts: a fully embedded FreeSerif+FreeSans document is > 2.5 MB.
        $this->assertLessThan(400 * 1024, strlen($pdf));
        $this->assert_verapdf($pdf);
    }

    /**
     * Runs veraPDF when VERAPDF_BIN points to the CLI (optional local check, skipped otherwise).
     *
     * @param string $pdf
     */
    private function assert_verapdf(string $pdf): void {
        $bin = getenv('VERAPDF_BIN');
        if (empty($bin) || !is_executable($bin)) {
            return;
        }
        $file = make_request_directory() . '/pdfa.pdf';
        file_put_contents($file, $pdf);
        $output = shell_exec(escapeshellarg($bin) . ' -f 2b --format text -v ' . escapeshellarg($file) . ' 2>&1');
        $this->assertStringStartsWith('PASS', trim((string)$output), "veraPDF: $output");
    }

    /**
     * Small RGBA PNG (logo with transparency).
     *
     * @return string
     */
    private function create_alpha_png(): string {
        $image = imagecreatetruecolor(40, 20);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 5, 5, 35, 15, imagecolorallocatealpha($image, 249, 128, 18, 40));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        return $png;
    }

    /**
     * Stores a sign-in sheet logo in the global setting file area.
     *
     * @param string $content
     */
    private function store_signinsheet_logo(string $content): void {
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_booking',
            'filearea' => 'mod_booking_signinlogo',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'logo.png',
        ], $content);
        set_config('signinlogo', '/logo.png', 'booking');
    }

    /**
     * Sign-in sheet (HTML mode) with the built-in default template and a transparent logo.
     */
    public function test_signinsheet_default_template_is_pdfa(): void {
        $bookingoption = $this->create_booked_option();
        set_config('signinsheethtml', '', 'booking');
        $this->store_signinsheet_logo($this->create_alpha_png());

        $generator = $this->create_signinsheet_generator($bookingoption);
        $html = $generator->render_html();
        $this->assertStringContainsString('Müller', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html, 'Logo must be inlined as data URI');

        $pdf = $generator->create_pdf_from_html($html)->Output('signinsheet.pdf', 'S');
        $this->assert_pdfa2b($pdf);
        // The transparent logo is embedded with a soft mask, which PDF/A-2 allows.
        $this->assertStringContainsString('/SMask', $pdf);
    }

    /**
     * Sign-in sheet with an admin template using core font names, links and monospaced text.
     */
    public function test_signinsheet_custom_template_is_pdfa(): void {
        $bookingoption = $this->create_booked_option();
        set_config(
            'signinsheethtml',
            '<h1 style="font-family: Helvetica, Arial, sans-serif">[[tablename]]</h1>'
            . '<p style="font-family: Times New Roman, serif">[[location]] – [[dates]] – <a href="https://example.com">Info</a></p>'
            . '<table class="signaturetable" border="1"><tr><th>Name</th><th>E-Mail</th><th>Unterschrift</th></tr>'
            . '[[users]]<tr><td>[[fullname]]</td><td><tt>[[email]]</tt></td><td></td></tr>[[/users]]</table>'
            . '<pre>Stand: heute</pre>',
            'booking'
        );

        $generator = $this->create_signinsheet_generator($bookingoption);
        $html = $generator->render_html();
        $this->assertStringContainsString('anna@example.com', $html);

        $pdf = $generator->create_pdf_from_html($html)->Output('signinsheet.pdf', 'S');
        $this->assert_pdfa2b($pdf);
        $this->assertStringContainsString('FreeSans', $pdf);
        $this->assertStringContainsString('FreeSerif', $pdf);
        $this->assertStringContainsString('FreeMono', $pdf);
        $this->assertStringContainsString('/URI (https://example.com)', $pdf);
    }

    /**
     * Checklist with the default template and with an admin template.
     */
    public function test_checklist_is_pdfa(): void {
        $bookingoption = $this->create_booked_option();

        set_config('checklisthtml', '', 'booking');
        $generator = new checklist_generator($bookingoption);
        $html = $generator->render_html();
        $this->assertStringContainsString('Yoga für Anfänger:innen', $html);
        $pdf = $generator->create_pdf_from_html($html)->Output('checklist.pdf', 'S');
        $this->assert_pdfa2b($pdf);

        set_config(
            'checklisthtml',
            '<h1 style="font-family: sans-serif">[[booking_text]]</h1><p style="font-family: monospace">[[dates]]</p>'
            . '<p>[[teachers]] – [[location]] – <a href="[[course_url]]">Kurs</a></p>',
            'booking'
        );
        $generator = new checklist_generator($bookingoption);
        $pdf = $generator->create_pdf_from_html($generator->render_html())->Output('checklist.pdf', 'S');
        $this->assert_pdfa2b($pdf);
        $this->assertStringContainsString('FreeSans', $pdf);
        $this->assertStringContainsString('FreeMono', $pdf);
    }

    /**
     * With the setting off both documents are generated with the previous classes and
     * without any PDF/A processing (core fonts stay unembedded).
     */
    public function test_without_setting_previous_output_is_kept(): void {
        set_config('pdfaenabled', 0, 'local_wunderbyte_table');
        $bookingoption = $this->create_booked_option();
        set_config(
            'signinsheethtml',
            '<h1 style="font-family: sans-serif">[[tablename]]</h1><table class="signaturetable">'
            . '[[users]]<tr><td>[[fullname]]</td></tr>[[/users]]</table>',
            'booking'
        );
        $generator = $this->create_signinsheet_generator($bookingoption);
        $doc = $generator->create_pdf_from_html($generator->render_html());
        $this->assertInstanceOf(\mod_booking\signinsheet\signin_pdf::class, $doc);
        $pdf = $doc->Output('signinsheet.pdf', 'S');
        $this->assertStringNotContainsString('pdfaid:part', $pdf);
        $this->assertStringNotContainsString('/OutputIntents', $pdf);
        $this->assertMatchesRegularExpression('#/BaseFont\s*/Helvetica#', $pdf);

        set_config('checklisthtml', '<h1 style="font-family: sans-serif">[[booking_text]]</h1>', 'booking');
        $generator = new checklist_generator($bookingoption);
        $doc = $generator->create_pdf_from_html($generator->render_html());
        $this->assertInstanceOf(\mod_booking\checklist\checklist_pdf::class, $doc);
        $pdf = $doc->Output('checklist.pdf', 'S');
        $this->assertStringNotContainsString('pdfaid:part', $pdf);
        $this->assertMatchesRegularExpression('#/BaseFont\s*/Helvetica#', $pdf);
    }
}
