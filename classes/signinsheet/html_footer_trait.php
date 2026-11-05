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
 * Footer of the sign-in sheet HTML template, printed on every page of the PDF.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\signinsheet;

/**
 * Prints the footer of the sign-in sheet HTML template (setting signinsheethtmlfooter) on every page.
 *
 * Used by the TCPDF classes of the sign-in sheet ({@see signin_pdf}, {@see pdfa_signin_pdf}). TCPDF
 * calls Footer() while a page is closed, so the footer has to be rendered by the PDF class itself:
 * the generator hands over the footer HTML with all placeholders resolved except [[page]] and
 * [[pages]], which only TCPDF can fill in - as its page number aliases, replaced once the total
 * number of pages is known.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait html_footer_trait {
    /**
     * Footer HTML as rendered by signinsheet_generator::render_footer_html(), '' for no footer.
     *
     * @var string
     */
    protected string $footerhtml = '';

    /**
     * Sets the footer HTML and reserves room for it at the bottom of every page.
     *
     * The footer is measured once, so the automatic page break happens above it, however many
     * lines it has. Must be called before the first page is added. An empty footer switches the
     * footer off, as before.
     *
     * @param string $html
     * @return void
     */
    public function set_html_footer(string $html): void {
        $this->footerhtml = $html;
        if (trim($html) === '') {
            $this->setPrintFooter(false);
            return;
        }
        $height = $this->measure_html_footer();
        // The footer starts $footermargin above the lower edge of the paper and ends PDF_MARGIN_FOOTER
        // (10 mm) above it, like the default footer of TCPDF; the content breaks get_footer_gap() above it.
        $footermargin = $height + PDF_MARGIN_FOOTER;
        $this->setFooterMargin($footermargin);
        $this->setAutoPageBreak(true, $footermargin + $this->get_footer_gap());
        $this->setPrintFooter(true);
    }

    /**
     * Distance between the end of the page content and the top of the footer in mm.
     *
     * A method instead of a constant, because traits cannot have constants before PHP 8.2 and the
     * plugin supports Moodle 4.5 on PHP 8.1. A class using the trait may override it.
     *
     * @return float
     */
    protected function get_footer_gap(): float {
        return 5.0;
    }

    /**
     * Height of the rendered footer HTML in mm.
     *
     * The HTML is written onto a throw-away page inside a TCPDF transaction, which is rolled back
     * afterwards, so the document itself stays untouched. The same font, padding and width are used
     * as later in the footer (see TCPDF::setFooter()).
     *
     * @return float
     */
    protected function measure_html_footer(): float {
        $this->startTransaction();
        $this->setPrintHeader(false);
        $this->setPrintFooter(false);
        $this->AddPage();
        $this->setFont($this->footer_font[0], $this->footer_font[1], $this->footer_font[2]);
        $this->setCellPadding(0);
        $top = $this->GetY();
        $this->writeHTMLCell(0, 0, '', '', $this->get_footer_html_for_pdf(), 0, 1, false, true, '', true);
        $height = $this->GetY() - $top;
        $this->rollbackTransaction(true);
        return $height;
    }

    /**
     * The footer HTML with [[page]] and [[pages]] replaced by the page number aliases of TCPDF.
     *
     * @return string
     */
    protected function get_footer_html_for_pdf(): string {
        return str_ireplace(
            ['[[pages]]', '[[page]]'],
            [$this->getAliasNbPages(), $this->getAliasNumPage()],
            $this->footerhtml
        );
    }

    /**
     * Writes the footer HTML at the footer position. To be called from Footer().
     *
     * @return void
     */
    protected function print_html_footer(): void {
        $this->writeHTMLCell(0, 0, '', '', $this->get_footer_html_for_pdf(), 0, 0, false, true, '', true);
    }
}
