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
 * Server-side renderer for the native Moodle question preview.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\questions;

use context;
use question_bank;
use question_engine;
use question_display_options;

/**
 * Renders freshly created questions inline using Moodle's native question rendering.
 *
 * This mirrors what /question/bank/previewquestion/preview.php does (build a transient
 * question_usage in the question bank's module context, start the question, render it),
 * but returns the rendered HTML so it can be handed to the agent preview pane via a skill's
 * get_result_preview() instead of opening the standalone preview page.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_preview_renderer {
    /** Hard cap on how many questions we render inline (keeps the payload sane). */
    private const MAX_RENDER = 20;

    /** Behaviour used for the (non-interactive) preview render. */
    private const PREVIEW_BEHAVIOUR = 'deferredfeedback';

    /**
     * Render the given questions as trusted HTML plus the render-time JS that initialises them.
     *
     * Best-effort: any question that cannot be loaded or rendered is skipped. Returns
     * ['html' => '', 'js' => ''] when nothing could be rendered, so the caller can expose no preview.
     *
     * The question renderer (filters, MathJax, qtype init) registers JavaScript on $PAGE->requires.
     * On a normal page that JS is emitted into the footer; here we are inside the synchronous webservice
     * request, so instead we COLLECT it (Moodle's fragment pattern: start_collecting_javascript_
     * requirements + get_end_code) and hand it back as a separate 'js' string. The client injects the
     * HTML and runs that JS via core/templates replaceNodeContents — the only correct way to ship
     * render-time JS into the page (a static AMD module name cannot represent it).
     *
     * @param int[]  $questionids     Question ids to render (in creation order).
     * @param int    $bankcontextid   Context id of the question bank module the questions live in.
     * @param string $bankurl         URL of the question bank (for the "open in bank" link).
     * @return array{html:string,js:string} Rendered HTML and the collected JS, both '' when nothing rendered.
     */
    public function render(array $questionids, int $bankcontextid, string $bankurl = ''): array {
        // This is a plain service, not a Moodle renderer subclass (it has no $this->page). It uses the
        // global $PAGE requirements manager on purpose to collect render-time JS via the fragment pattern
        // documented above, so the renderer-specific "use $this->page" rule does not apply here.
        // phpcs:disable moodle.PHP.ForbiddenGlobalUse
        global $CFG, $PAGE;
        require_once($CFG->libdir . '/questionlib.php');

        $empty = ['html' => '', 'js' => ''];

        $questionids = array_values(array_filter(array_map('intval', $questionids)));
        if (empty($questionids)) {
            return $empty;
        }

        $context = context::instance_by_id($bankcontextid, IGNORE_MISSING);
        if (!$context) {
            return $empty;
        }

        $options = self::build_display_options();
        $headhtml = '';
        $bodyhtml = '';
        $js = '';
        $rendered = 0;

        // Switch the page over to a fragment requirements manager so the render-time JS (qtype init,
        // filters, MathJax) is COLLECTED instead of emitted into the page footer (which never runs in a
        // JSON webservice). This needs a minimally initialised page output, which initialise_theme_and_
        // output() provides WITHOUT emitting any HTML. If that cannot be set up in this request context,
        // we degrade gracefully: render the HTML anyway, just without the collected JS (js stays '').
        $collecting = false;
        try {
            $PAGE->initialise_theme_and_output();
            if ($PAGE->requires) {
                $PAGE->start_collecting_javascript_requirements();
                $collecting = true;
            }
        } catch (\Throwable $e) {
            $collecting = false;
        }

        // The ob_start call additionally swallows any stray echo (debug notices) so it can never corrupt the JSON
        // webservice envelope.
        ob_start();
        try {
            foreach ($questionids as $questionid) {
                if ($rendered >= self::MAX_RENDER) {
                    break;
                }
                try {
                    $question = question_bank::load_question($questionid);
                    // Each question gets its own transient usage so one bad question cannot poison the rest.
                    $quba = question_engine::make_questions_usage_by_activity('bookingextension_agent', $context);
                    $quba->set_preferred_behaviour(self::PREVIEW_BEHAVIOUR);
                    $slot = $quba->add_question($question, $question->defaultmark);
                    $quba->start_question($slot);

                    $headhtml .= $quba->render_question_head_html($slot);
                    $bodyhtml .= \html_writer::div(
                        $quba->render_question($slot, $options, (string)($rendered + 1)),
                        'bookingextension_agent-question-preview-item'
                    );
                    $rendered++;
                } catch (\Throwable $e) {
                    continue;
                }
            }
            if ($rendered > 0) {
                question_engine::initialise_js();
            }
        } catch (\Throwable $e) {
            unset($e);
        } finally {
            ob_end_clean();
        }

        if ($collecting) {
            try {
                // Read the collected code BEFORE end_collecting restores the page's real manager.
                if ($rendered > 0) {
                    $js = (string)$PAGE->requires->get_end_code();
                }
            } catch (\Throwable $e) {
                $js = '';
            } finally {
                try {
                    $PAGE->end_collecting_javascript_requirements();
                } catch (\Throwable $e) {
                    unset($e);
                }
            }
        }
        // phpcs:enable moodle.PHP.ForbiddenGlobalUse

        if ($rendered === 0) {
            return $empty;
        }

        $heading = \html_writer::tag(
            'h5',
            get_string('previewquestions_heading', 'bookingextension_agent', $rendered),
            ['class' => 'bookingextension_agent-question-preview-heading']
        );

        $banklink = '';
        if (trim($bankurl) !== '') {
            $banklink = \html_writer::div(
                \html_writer::link(
                    $bankurl,
                    get_string('previewquestions_openbank', 'bookingextension_agent'),
                    ['target' => '_blank', 'rel' => 'noopener']
                ),
                'bookingextension_agent-question-preview-banklink'
            );
        }

        $html = \html_writer::div(
            $headhtml . $heading . $bodyhtml . $banklink,
            'bookingextension_agent-question-preview'
        );

        return ['html' => $html, 'js' => $js];
    }

    /**
     * Read-only display options that surface the correct answer and feedback so a teacher can judge
     * the generated question, without showing marks or an attempt.
     *
     * @return question_display_options
     */
    private static function build_display_options(): question_display_options {
        $options = new question_display_options();
        $options->readonly = true;
        $options->flags = question_display_options::HIDDEN;
        $options->marks = question_display_options::HIDDEN;
        $options->manualcomment = question_display_options::HIDDEN;
        $options->history = question_display_options::HIDDEN;
        $options->correctness = question_display_options::HIDDEN;
        $options->numpartscorrect = false;
        $options->feedback = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;
        return $options;
    }
}
