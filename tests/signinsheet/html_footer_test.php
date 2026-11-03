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
 * Tests for the footer of the sign-in sheet HTML template.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_system;
use mod_booking\signinsheet\pdfa_signin_pdf;
use mod_booking\signinsheet\signin_pdf;
use mod_booking\signinsheet\signinsheet_config;
use mod_booking\signinsheet\signinsheet_generator;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use PhpOffice\PhpWord\Element\PreserveText;
use stdClass;

/**
 * The footer template (setting signinsheethtmlfooter) is printed on every page of the PDF with the
 * placeholders of the sheet template, the page numbers and the footer logo; Word gets it as text.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\signinsheet\signinsheet_generator::render_footer_html
 * @covers \mod_booking\signinsheet\signinsheet_generator::create_pdf_from_html
 * @covers \mod_booking\signinsheet\signinsheet_generator::create_word_from_html
 * @covers \mod_booking\signinsheet\html_footer_trait
 */
final class html_footer_test extends booking_advanced_testcase {
    /**
     * Footer template of the tests: a two line address, the page numbers and the footer logo.
     */
    private const FOOTER = '<style>p { margin: 0; }</style>'
        . '<p style="text-align: center; font-size: 8pt;">EPFL VPO-SE, Station 4, CH-1015 Lausanne</p>'
        . '<p style="text-align: center; font-size: 8pt;">[[BookingOptionName]] - [[bookedplaces]] participants'
        . ' - <img src="[[footerlogourl]]" height="10" /></p>'
        . '<p style="text-align: center; font-size: 8pt;">Page [[page]] / [[pages]]</p>';

    /**
     * Booking option with two booked users.
     *
     * @return booking_option
     */
    private function create_booked_option(): booking_option {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Sign-in booking',
            'course' => $course->id,
            'signinsheetfields' => ['fullname', 'email', 'signature'],
        ]);
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'FOBS 1+2';
        $record->coursestarttime = strtotime('tomorrow 10:00');
        $record->courseendtime = strtotime('tomorrow 12:00');
        $option = $plugingenerator->create_option($record);

        foreach ([['Anna', 'Bianchi'], ['Ben', 'Tester']] as [$firstname, $lastname]) {
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
     * Sign-in sheet generator in HTML template mode with the download presets of the plugin.
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
     * Stores a png as the footer logo of the site settings.
     *
     * @return void
     */
    private function store_footer_logo(): void {
        $image = imagecreatetruecolor(20, 10);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 0, 0));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        get_file_storage()->create_file_from_string([
            'contextid' => context_system::instance()->id,
            'component' => 'booking',
            'filearea' => 'mod_booking_signinlogo_footer',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'footer.png',
        ], $png);
        set_config('signinlogofooter', '/footer.png', 'booking');
    }

    /**
     * The uncompressed PDF, so its text can be searched.
     *
     * @param \pdf $pdf
     * @return string
     */
    private function pdf_output(\pdf $pdf): string {
        $pdf->setCompression(false);
        return $pdf->Output('signinsheet.pdf', 'S');
    }

    /**
     * How often a text is shown in the PDF.
     *
     * TCPDF writes the text of core fonts as is and the text of unicode fonts (e.g. the FreeSerif
     * default of Moodle) as UTF-16BE, so both encodings are counted.
     *
     * @param string $output uncompressed PDF
     * @param string $text
     * @return int
     */
    private function count_pdf_text(string $output, string $text): int {
        return substr_count($output, $text)
            + substr_count($output, mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'));
    }

    /**
     * The placeholders of the sheet template are resolved in the footer, [[page]] and [[pages]] are
     * left to the PDF, a missing footer logo drops its img tag, and the footer is printed on the page.
     */
    public function test_footer_is_rendered_with_placeholders_and_page_numbers(): void {
        $bookingoption = $this->create_booked_option();
        set_config('signinsheethtml', '', 'booking');
        set_config('signinsheethtmlfooter', self::FOOTER, 'booking');
        $generator = $this->create_signinsheet_generator($bookingoption);

        $footer = $generator->render_footer_html();
        $this->assertStringContainsString('Station 4', $footer);
        $this->assertStringContainsString('FOBS 1+2 - 2 participants', $footer);
        $this->assertStringContainsString('Page [[page]] / [[pages]]', $footer);
        $this->assertStringNotContainsString('<img', $footer);
        $this->assertStringNotContainsString('footerlogourl', $footer);
        $this->assertStringContainsString('p { margin: 0; }', $footer);

        $pdf = $generator->create_pdf_from_html($generator->render_html());
        $this->assertInstanceOf(signin_pdf::class, $pdf);
        $this->assertSame(1, $pdf->getNumPages());
        // Room for three lines of footer plus the 10 mm below it (TCPDF reserves nothing by default).
        $this->assertGreaterThan(PDF_MARGIN_FOOTER, $pdf->getFooterMargin());
        $output = $this->pdf_output($pdf);
        $this->assertSame(1, $this->count_pdf_text($output, 'Station 4'));
        $this->assertSame(1, $this->count_pdf_text($output, 'Page 1 / 1'));
        $this->assertSame(0, $this->count_pdf_text($output, ':pnp:'));
        $this->assertSame(0, $this->count_pdf_text($output, ':ptp:'));
    }

    /**
     * The footer is on every page of a multi-page sheet, with the right page numbers.
     */
    public function test_footer_is_printed_on_every_page(): void {
        $bookingoption = $this->create_booked_option();
        set_config('signinsheethtml', '<h1>[[tablename]]</h1>' . str_repeat('<p>Line of the sheet</p>', 150), 'booking');
        set_config('signinsheethtmlfooter', self::FOOTER, 'booking');
        $generator = $this->create_signinsheet_generator($bookingoption);

        $pdf = $generator->create_pdf_from_html($generator->render_html());
        $pages = $pdf->getNumPages();
        $this->assertGreaterThan(1, $pages);
        $output = $this->pdf_output($pdf);
        $this->assertSame($pages, $this->count_pdf_text($output, 'Station 4'));
        for ($page = 1; $page <= $pages; $page++) {
            $this->assertSame(1, $this->count_pdf_text($output, "Page $page / $pages"));
        }
    }

    /**
     * The footer logo of the sign-in sheet settings is inlined as data URI.
     */
    public function test_footer_logo_is_inlined(): void {
        $bookingoption = $this->create_booked_option();
        set_config('signinsheethtmlfooter', self::FOOTER, 'booking');
        $this->store_footer_logo();
        $generator = $this->create_signinsheet_generator($bookingoption);

        $footer = $generator->render_footer_html();
        $this->assertStringContainsString('<img src="data:image/png;base64,', $footer);
        $this->assertStringNotContainsString('footerlogourl', $footer);
        $pdf = $generator->create_pdf_from_html($generator->render_html());
        $this->assertStringContainsString('/Subtype /Image', $this->pdf_output($pdf));
    }

    /**
     * Without a footer template the sheet is generated as before: no footer, no footer margin.
     */
    public function test_no_footer_without_template(): void {
        $bookingoption = $this->create_booked_option();
        set_config('signinsheethtml', '', 'booking');
        set_config('signinsheethtmlfooter', ' <p> </p> ', 'booking');
        $generator = $this->create_signinsheet_generator($bookingoption);

        $this->assertSame('', $generator->render_footer_html());
        $pdf = $generator->create_pdf_from_html($generator->render_html());
        $this->assertEmpty($pdf->getFooterMargin());
        $output = $this->pdf_output($pdf);
        $this->assertSame(1, $this->count_pdf_text($output, 'Bianchi'));
        $this->assertSame(0, $this->count_pdf_text($output, 'Page 1 / 1'));
    }

    /**
     * With PDF/A enabled the footer is printed by the PDF/A class of the sign-in sheet.
     */
    public function test_footer_in_pdfa_mode(): void {
        $bookingoption = $this->create_booked_option();
        set_config('pdfaenabled', 1, 'local_wunderbyte_table');
        set_config('signinsheethtml', '', 'booking');
        set_config('signinsheethtmlfooter', self::FOOTER, 'booking');
        $generator = $this->create_signinsheet_generator($bookingoption);

        $pdf = $generator->create_pdf_from_html($generator->render_html());
        $this->assertInstanceOf(pdfa_signin_pdf::class, $pdf);
        $output = $this->pdf_output($pdf);
        $this->assertStringContainsString('/Type /OutputIntent', $output);
        $this->assertSame(1, $this->count_pdf_text($output, 'Station 4'));
        $this->assertSame(1, $this->count_pdf_text($output, 'Page 1 / 1'));
        $this->assertSame(0, $this->count_pdf_text($output, ':pnp:'));
    }

    /**
     * Word gets the footer as centered text lines with the fields PAGE and NUMPAGES.
     */
    public function test_word_footer_is_plain_text_with_fields(): void {
        $bookingoption = $this->create_booked_option();
        set_config('signinsheethtml', '', 'booking');
        set_config('signinsheethtmlfooter', self::FOOTER, 'booking');
        $generator = $this->create_signinsheet_generator($bookingoption);

        $worddoc = $generator->create_word_from_html($generator->render_html());
        $footers = $worddoc->getSections()[0]->getFooters();
        $this->assertCount(1, $footers);
        $lines = [];
        foreach (reset($footers)->getElements() as $element) {
            $this->assertInstanceOf(PreserveText::class, $element);
            $lines[] = implode('', $element->getText());
        }
        $this->assertSame(
            ['EPFL VPO-SE, Station 4, CH-1015 Lausanne', 'FOBS 1+2 - 2 participants -', 'Page {PAGE} / {NUMPAGES}'],
            $lines
        );
    }

    /**
     * Without a footer template the Word document has no footer.
     */
    public function test_word_without_footer(): void {
        $bookingoption = $this->create_booked_option();
        set_config('signinsheethtml', '', 'booking');
        set_config('signinsheethtmlfooter', '', 'booking');
        $generator = $this->create_signinsheet_generator($bookingoption);

        $worddoc = $generator->create_word_from_html($generator->render_html());
        $this->assertSame([], $worddoc->getSections()[0]->getFooters());
    }
}
