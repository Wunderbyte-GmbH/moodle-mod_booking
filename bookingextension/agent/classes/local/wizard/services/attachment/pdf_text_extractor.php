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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\attachment;

/**
 * Extracts plain text from PDF files.
 *
 * Strategy (in order):
 *   1. pdftotext (poppler-utils shell command) — fast, accurate.
 *   2. smalot/pdfparser (bundled pure-PHP library) — dependency-free fallback that
 *      works on any server without a system binary or PHP exec(). The library is
 *      vendored under thirdparty/pdfparser and loaded via a lazy PSR-4 autoloader
 *      registered by this class (no Composer vendor/autoload.php is required).
 *   3. Throws moodle_exception if neither is available.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_text_extractor {
    /** Maximum characters of extracted text to keep. ~3750 tokens. */
    public const MAX_CHARS = 15000;

    /** PSR-4 prefix of the bundled pure-PHP PDF library. */
    private const PDFPARSER_NAMESPACE_PREFIX = 'Smalot\\PdfParser\\';

    /** @var bool Whether the bundled pdfparser autoloader has already been registered. */
    private static bool $pdfparserautoloaderregistered = false;

    /**
     * Whether at least one extraction method is available.
     *
     * @return bool
     */
    public function is_available(): bool {
        return $this->has_pdftotext() || $this->has_pdfparser();
    }

    /**
     * Extract text from a PDF file.
     *
     * @param string $filepath Absolute path to PDF file.
     * @param int|null $maxchars Optional custom character cap. Null (default) keeps the existing
     *              attachment behaviour: truncate at MAX_CHARS with a localized truncation note
     *              appended. A custom cap truncates HARD at that many characters WITHOUT the
     *              localized note — callers that need deterministic, language-independent output
     *              (e.g. the site-search chunk pipeline, whose index-time and query-time text
     *              must be byte-identical) use this mode.
     * @return string Extracted text (possibly truncated).
     * @throws \moodle_exception When no extraction method is available.
     */
    public function extract(string $filepath, ?int $maxchars = null): string {
        if ($this->has_pdftotext()) {
            $text = $this->extract_via_shell($filepath);
            if ($text !== null) {
                return $this->truncate($text, $maxchars);
            }
        }

        if ($this->has_pdfparser()) {
            $text = $this->extract_via_pdfparser($filepath);
            if ($text !== null) {
                return $this->truncate($text, $maxchars);
            }
        }

        throw new \moodle_exception('ai_pdf_extraction_unavailable', 'bookingextension_agent');
    }

    /**
     * Truncate extracted text.
     *
     * Default mode ($maxchars null): cap at MAX_CHARS and append a localized note if truncated.
     * Custom-cap mode: hard, note-free truncation (deterministic output, see extract()).
     *
     * @param string $text
     * @param int|null $maxchars Custom character cap, or null for the MAX_CHARS + note default.
     * @return string
     */
    private function truncate(string $text, ?int $maxchars = null): string {
        $text = trim($text);
        if ($maxchars !== null) {
            if (mb_strlen($text) <= $maxchars) {
                return $text;
            }
            return rtrim(mb_substr($text, 0, $maxchars));
        }
        if (mb_strlen($text) <= self::MAX_CHARS) {
            return $text;
        }

        $truncated = mb_substr($text, 0, self::MAX_CHARS);
        $note = get_string('ai_pdf_truncated', 'bookingextension_agent', number_format(self::MAX_CHARS));
        return $truncated . "\n\n" . $note;
    }

    /**
     * Whether pdftotext is available on this system.
     *
     * @return bool
     */
    private function has_pdftotext(): bool {
        if (!function_exists('exec')) {
            return false;
        }
        $output = [];
        $ret = 0;
        @exec('pdftotext -v 2>&1', $output, $ret);
        // Pdftotext returns 0 or 99 on version print; just check the command exists.
        return $ret !== 127;
    }

    /**
     * Whether the smalot/pdfparser library is available.
     *
     * Registers the bundled library's autoloader first, so this returns true on any
     * server regardless of whether Composer is used.
     *
     * @return bool
     */
    private function has_pdfparser(): bool {
        self::ensure_pdfparser_autoloader();
        return class_exists('\Smalot\PdfParser\Parser');
    }

    /**
     * Register a lazy PSR-4 autoloader for the bundled pure-PHP PDF library.
     *
     * The library is vendored under thirdparty/pdfparser/src and declared in the
     * plugin's thirdpartylibs.xml. It ships no Composer autoloader, so this maps the
     * Smalot\PdfParser\ namespace onto the vendored src directory. Registered once,
     * lazily, and only resolves classes under that prefix.
     *
     * @return void
     */
    private static function ensure_pdfparser_autoloader(): void {
        if (self::$pdfparserautoloaderregistered) {
            return;
        }
        self::$pdfparserautoloaderregistered = true;

        $srcroot = dirname(__DIR__, 5) . '/thirdparty/pdfparser/src';

        spl_autoload_register(static function (string $class) use ($srcroot): void {
            if (strpos($class, self::PDFPARSER_NAMESPACE_PREFIX) !== 0) {
                return;
            }
            $relative = substr($class, strlen(self::PDFPARSER_NAMESPACE_PREFIX));
            $file = $srcroot . '/Smalot/PdfParser/' . str_replace('\\', '/', $relative) . '.php';
            if (is_readable($file)) {
                require_once($file);
            }
        });
    }

    /**
     * Extract text via pdftotext shell command.
     *
     * @param string $filepath
     * @return string|null Extracted text or null on failure.
     */
    private function extract_via_shell(string $filepath): ?string {
        $output = [];
        $ret = 0;
        $safepath = escapeshellarg($filepath);

        // Limit execution time for this call.
        $prevlimit = ini_get('max_execution_time');
        @set_time_limit(30);

        @exec('pdftotext -enc UTF-8 ' . $safepath . ' - 2>/dev/null', $output, $ret);

        if ((int)$prevlimit > 0) {
            @set_time_limit((int)$prevlimit);
        }

        if ($ret !== 0 || empty($output)) {
            return null;
        }

        return implode("\n", $output);
    }

    /**
     * Extract text via smalot/pdfparser PHP library.
     *
     * @param string $filepath
     * @return string|null Extracted text or null on failure.
     */
    private function extract_via_pdfparser(string $filepath): ?string {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filepath);
            $text = $pdf->getText();
            return is_string($text) && trim($text) !== '' ? $text : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
