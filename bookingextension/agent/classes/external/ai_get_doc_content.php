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
 * External service: load a booking/docs markdown file and return it as rendered HTML.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use core\context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\services\lookup\docs_corpus_registry;
use bookingextension_agent\local\wizard\services\lookup\markdown_renderer;

/**
 * Read one booking/docs markdown file and return it as safe HTML.
 *
 * The path is resolved strictly inside the bookingextension_agent/docs directory;
 * any traversal attempt results in an error response.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_get_doc_content extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id.'),
            'corpus_id' => new external_value(PARAM_ALPHANUMEXT, 'Documentation corpus id, e.g. mod_booking.'),
            'path' => new external_value(PARAM_PATH, 'Relative path inside the corpus, e.g. booking_rules/README.md'),
        ]);
    }

    /**
     * Load and render a documentation markdown file.
     *
     * @param int    $contextid
     * @param string $corpusid  Documentation corpus id (resolved to a root via the registry).
     * @param string $path  Relative path inside the corpus.
     * @return array{success:bool, html:string, title:string, error:string}
     */
    public static function execute(int $contextid, string $corpusid, string $path): array {
        global $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['contextid' => $contextid, 'corpus_id' => $corpusid, 'path' => $path]
        );

        $authz = new authorization_service();
        if ($problem = $authz->check_use_readiness((int)$USER->id, (int)$params['contextid'])) {
            return ['success' => false, 'html' => '', 'title' => '', 'error' => $problem['message']];
        }
        $context = context::instance_by_id((int)$params['contextid'], MUST_EXIST);
        self::validate_context($context);

        // Resolve the corpus root strictly via the registry (the only trusted corpus_id → root map).
        $corpusroot = (new docs_corpus_registry())->resolve_root((string)$params['corpus_id']);
        if ($corpusroot === null) {
            return ['success' => false, 'html' => '', 'title' => '', 'error' => 'unknown documentation corpus'];
        }

        $docsroot = realpath($corpusroot);
        if ($docsroot === false || !is_dir($docsroot)) {
            return ['success' => false, 'html' => '', 'title' => '', 'error' => 'docs directory not found'];
        }

        // Resolve the requested path strictly inside this corpus root — prevent any traversal.
        $requested = realpath($docsroot . DIRECTORY_SEPARATOR . $params['path']);
        if (
            $requested === false
            || !is_file($requested)
            || strpos($requested, $docsroot) !== 0
            || strtolower(pathinfo($requested, PATHINFO_EXTENSION)) !== 'md'
        ) {
            return ['success' => false, 'html' => '', 'title' => '', 'error' => 'file not found or not accessible'];
        }

        $markdown = file_get_contents($requested);
        if ($markdown === false) {
            return ['success' => false, 'html' => '', 'title' => '', 'error' => 'could not read file'];
        }

        $title = '';
        if (preg_match('/^#\s+(.+)$/m', $markdown, $m)) {
            $title = trim($m[1]);
        }

        $relativepath = ltrim(str_replace('\\', '/', substr($requested, strlen($docsroot))), '/');
        $html = markdown_renderer::render(
            $markdown,
            $relativepath,
            (int)$params['contextid'],
            (string)$params['corpus_id']
        );

        return ['success' => true, 'html' => $html, 'title' => $title, 'error' => ''];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the file was loaded successfully.'),
            'html'    => new external_value(PARAM_RAW, 'Rendered HTML content of the markdown file.'),
            'title'   => new external_value(PARAM_TEXT, 'H1 title extracted from the document.'),
            'error'   => new external_value(PARAM_TEXT, 'Error message if success=false, otherwise empty.'),
        ]);
    }
}
