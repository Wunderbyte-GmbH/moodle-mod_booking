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

namespace bookingextension_agent\local\wizard\services\questions;

use context;
use stdClass;

/**
 * Imports questions into a Moodle question bank from a GIFT document.
 *
 * Wraps Moodle's core question import API (qformat_gift::importprocess()) so the agent
 * never touches core_question internals directly: it hands Moodle a syntactically valid
 * GIFT file and lets core parse, validate and create the questions.
 *
 * On any import error the partially created questions are rolled back, so the caller can
 * regenerate and retry without leaving orphans or duplicates.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_import_service {
    /**
     * Import GIFT-formatted questions into the default category of a module question bank.
     *
     * In Moodle 5.x question banks live in module contexts (e.g. a mod_qbank "Question bank"
     * activity), so $context must be a CONTEXT_MODULE context — question_get_default_category()
     * only creates default categories there.
     *
     * @param string   $gift       GIFT document text.
     * @param context  $context    Target module (question-bank) context that receives the questions.
     * @param stdClass $course     Course record the import runs against.
     * @param int|null $categoryid Specific category to import into; null = the context's default category.
     * @return array{success:bool,imported:int,questionids:int[],categoryid:int,errors:string}
     */
    public function import_gift(string $gift, context $context, stdClass $course, ?int $categoryid = null): array {
        global $CFG, $DB;
        // Load questionlib.php: it defines question_get_default_category() AND the global question_bank
        // class (via question/engine/lib.php). The base qformat importprocess() calls question_bank
        // unqualified, and question/format.php does not load it itself — in the agent's webservice/adhoc
        // request that class is otherwise not present ("Class \"question_bank\" not found").
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/gift/format.php');

        $fail = static function (string $error): array {
            return [
                'success' => false,
                'imported' => 0,
                'questionids' => [],
                'categoryid' => 0,
                'errors' => $error,
            ];
        };

        if (trim($gift) === '') {
            return $fail('The generated GIFT document was empty.');
        }

        // Use the explicitly chosen category, or get/create the context's default category.
        if ($categoryid !== null && $categoryid > 0) {
            $category = $DB->get_record('question_categories', ['id' => $categoryid, 'contextid' => $context->id]);
            if (empty($category)) {
                return $fail('The chosen question category does not belong to the target question bank.');
            }
        } else {
            $category = question_get_default_category((int)$context->id, true);
        }
        if (empty($category) || empty($category->id)) {
            return $fail('No question category is available for the target context.');
        }

        // Write the GIFT to a request-scoped temp file (auto-cleaned at end of request).
        $tmpfile = make_request_directory() . '/agent_questions.gift.txt';
        if (file_put_contents($tmpfile, $gift) === false) {
            return $fail('Could not write the temporary GIFT import file.');
        }

        $qformat = new \qformat_gift();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);
        $qformat->setCourse($course);
        $qformat->setFilename($tmpfile);
        $qformat->setRealfilename('agent_questions.gift.txt');
        $qformat->setMatchgrades('error');
        $qformat->setCatfromfile(false);
        $qformat->setContextfromfile(false);
        // Stop on the first invalid question: a partial import is treated as a failed attempt
        // and rolled back, so the generation step can retry the whole document cleanly.
        $qformat->setStoponerror(true);

        // The importpreprocess and importprocess calls echo progress and error markup; capture it.
        ob_start();
        $ok = $qformat->importpreprocess() && $qformat->importprocess();
        if ($ok) {
            $qformat->importpostprocess();
        }
        $output = trim(html_to_text((string)ob_get_clean()));

        $ids = array_values(array_filter(array_map('intval', (array)($qformat->questionids ?? []))));

        // Safety net (#2493): GIFT turns every paragraph without an answer block into a description question.
        // Generated documents carry only real questions, so a description question means text that is not a
        // question slipped through - the attempt fails and is rolled back like any other.
        if ($ok && !empty($ids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($ids);
            if ($DB->record_exists_select('question', "qtype = 'description' AND id $insql", $inparams)) {
                $ok = false;
                $output = 'The GIFT document contained text that is not a question (it became a description '
                    . 'question). Return only GIFT questions, each with a ::name:: and an answer block.';
            }
        }

        if (!$ok || empty($ids)) {
            // Roll back anything created during a failed attempt so a retry does not duplicate.
            foreach ($ids as $id) {
                question_delete_question($id);
            }
            return [
                'success' => false,
                'imported' => 0,
                'questionids' => [],
                'categoryid' => (int)$category->id,
                'errors' => $output !== '' ? $output : 'The GIFT import did not create any question.',
            ];
        }

        return [
            'success' => true,
            'imported' => count($ids),
            'questionids' => $ids,
            'categoryid' => (int)$category->id,
            'errors' => '',
        ];
    }
}
