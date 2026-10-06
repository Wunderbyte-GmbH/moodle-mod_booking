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
 * Real-LLM E2E for the self-learning course compound (thread 593).
 *
 * One prompt: "create a self-learning course about Cicero, 3 chapters, a final quiz with
 * 5 questions, 30 EUR / 20 EUR for students, trainer X, available 30 days." The whole
 * course IS the self-learning offer; no booking activity is named. This pins the hardest
 * variant of the authoring chain and the defects thread 593 exposed at the option step:
 *
 * - the option must land in the (single) booking instance WITHOUT the model fabricating the
 *   just-created course name into activityquery (593: activityquery="Das Leben von Cicero…"
 *   → the module-target resolver found no such instance → hard "no matching activity");
 * - two price categories from prose ("30 Euro, 20 für Studierende") must become the canonical
 *   {default:30, student:20}, not an invented array-of-labels
 *   ([{price:30,label:"Normalpreis"},…], which 593 produced);
 * - the freshly created course must be linked to the option (courseid != 0 — B11/G5, which
 *   593 set only stochastically).
 *
 * RED until those are fixed; the DB post-conditions are the permanent contract.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

/**
 * Self-learning course compound chain with a real LLM.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class selflearning_course_compound_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    /**
     * Full Cicero chain: create_course → scaffold (3 chapters + graded quiz) →
     * self-learning option linking the new course, priced 30/20, 30 days, trainer.
     */
    public function test_selflearning_course_with_two_prices_links_and_bills(): void {
        global $DB;

        $this->prepare_authoring_environment();

        // A named trainer the option must end up carrying (like thread 593's "… ist Trainerin").
        $trainer = $this->getDataGenerator()->create_user([
            'firstname' => 'Cornelia',
            'lastname' => 'Ciceronia',
            'email' => 'cornelia.ciceronia.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($trainer->id, $this->course->id, 'editingteacher');

        $this->setUser($this->teacher);

        $title = 'Das Leben von Cicero ' . substr(sha1(uniqid('', true)), 0, 6);
        [$store, $runtime, $threadid] = $this->build_runtime();

        $result = $this->chat(
            'Erstelle mir einen Selbstlernkurs "' . $title . '" in drei Kapitel zum Leben von '
                . 'Cicero, mit einem Abschlusstest mit fünf Fragen. Er kostet 30 Euro, 20 Euro '
                . 'für Studierende. ' . fullname($trainer) . ' ist Trainerin und der Kurs soll '
                . '30 Tage verfügbar sein.',
            $threadid,
            $store,
            $runtime
        );

        // Drive the chain: answer a category clarification if it comes, then confirm each
        // mutating step, nudging a missing step once like a live user would. The DB
        // post-conditions below are unconditional regardless of the exact turn shape.
        $result = $this->answer_category_if_asked($result, $threadid, $store, $runtime);
        $this->confirm_chain($result, $threadid, $store);

        $course = $DB->get_record('course', ['fullname' => $title]);
        // Routing stochasticity: "Selbstlernkurs" is ambiguous (a Moodle course vs. a booking
        // option), so the first turn may start at the option instead of create_course. Nudge the
        // course explicitly like a live user would; the DB post-conditions stay the contract.
        if ($course === false) {
            $nudge = $this->chat(
                'Lege zuerst den Moodle-Kurs "' . $title . '" an (als Selbstlernkurs, 3 Kapitel, '
                    . 'Abschlusstest mit 5 Fragen).',
                $threadid,
                $store,
                $runtime
            );
            $nudge = $this->answer_category_if_asked($nudge, $threadid, $store, $runtime);
            $this->confirm_chain($nudge, $threadid, $store);
            $course = $DB->get_record('course', ['fullname' => $title]);
        }
        if ($course === false) {
            $this->dump_llm_debug((int)$threadid);
        }
        $this->assertNotFalse($course, 'The Cicero course must exist.');

        // Scaffold: at least the 3 chapter pages and exactly the one final quiz.
        if (!$this->course_has_scaffold((int)$course->id)) {
            $next = $this->chat(
                'Fülle den Kurs "' . $title . '" jetzt mit den 3 Kapiteln und dem '
                    . 'Abschlusstest mit 5 Fragen.',
                $threadid,
                $store,
                $runtime
            );
            $this->confirm_chain($next, $threadid, $store);
        }
        $modinfo = get_fast_modinfo((int)$course->id);
        $this->assertGreaterThanOrEqual(
            3,
            count($modinfo->get_instances_of('page')),
            'At least the 3 chapter pages must exist.'
        );
        $this->assertGreaterThanOrEqual(
            1,
            count($modinfo->get_instances_of('quiz')),
            'The final quiz must exist.'
        );

        // The option: created in the single test booking instance, WITHOUT the course name
        // fabricated into activityquery (thread 593's hard break).
        $option = $this->find_option_linking_course((int)$course->id);
        if ($option === null) {
            $next = $this->chat(
                'Mach den Kurs "' . $title . '" jetzt als Selbstlernkurs buchbar: '
                    . '30 Euro, 20 Euro für Studierende, 30 Tage, ' . fullname($trainer) . ' als Trainerin.',
                $threadid,
                $store,
                $runtime
            );
            $this->confirm_chain($next, $threadid, $store);
            $option = $this->find_option_linking_course((int)$course->id);
        }
        $this->assertNotNull(
            $option,
            'A self-learning option linking the Cicero course must exist (B11/G5 + activityquery fabrication).'
        );

        // Two price categories from prose → canonical {default:30, student:20}.
        $prices = $DB->get_records_menu(
            'booking_prices',
            ['itemid' => (int)$option->id, 'area' => 'option'],
            '',
            'pricecategoryidentifier, price'
        );
        $this->assertArrayHasKey('default', $prices, 'The default price category must be set: ' . json_encode($prices));
        $this->assertArrayHasKey('student', $prices, 'The student price category must be set: ' . json_encode($prices));
        $this->assertEqualsWithDelta(30.0, (float)$prices['default'], 0.001);
        $this->assertEqualsWithDelta(20.0, (float)$prices['student'], 0.001);

        // 30 days availability (self-learning duration).
        $this->assertGreaterThanOrEqual(29 * DAYSECS, (int)$option->duration, 'Duration must be ~30 days.');
        $this->assertLessThanOrEqual(31 * DAYSECS, (int)$option->duration, 'Duration must be ~30 days.');

        // The named trainer is on the option.
        $details = $this->exec_command('mod_booking.get_option_details', [
            'optionid' => (int)$option->id,
            'requested_fields' => ['title', 'teachers'],
            'includesessions' => false,
        ]);
        $this->assertStringContainsString(
            'Ciceronia',
            json_encode($details['optiondetails'] ?? [], JSON_UNESCAPED_UNICODE),
            'The named trainer must be assigned to the option.'
        );
    }

    /**
     * If the first turn asks for a course category, answer it (thread 593 chose "testcaps").
     *
     * @param array $result
     * @param int $threadid
     * @param conversation_store $store
     * @param agent_runtime $runtime
     * @return array The follow-up result, or the original when no category was asked.
     */
    private function answer_category_if_asked(array $result, int $threadid, $store, $runtime): array {
        if ((string)($result['response_type'] ?? '') !== 'clarification') {
            return $result;
        }
        global $DB;
        $category = $DB->get_record('course_categories', ['id' => (int)$this->course->category], '*', MUST_EXIST);
        return $this->chat('Nimm die Kategorie "' . $category->name . '".', $threadid, $store, $runtime);
    }

    /**
     * Confirm every chained confirmation_request in turn (Driver B restages the next step),
     * asserting each confirm succeeds. Stops at a non-confirmation turn.
     *
     * @param array $result
     * @param int $threadid
     * @param conversation_store $store
     * @return void
     */
    private function confirm_chain(array $result, int $threadid, $store): void {
        $rounds = 0;
        while ((string)($result['response_type'] ?? '') === 'confirmation_request' && $rounds < 8) {
            $confirm = $this->confirm_pending_result($result, (int)$threadid, $store, false);
            $this->assertTrue(
                (bool)($confirm['success'] ?? false),
                'Chained confirm #' . ($rounds + 1) . ' failed: ' . $this->payload_text($confirm)
            );
            $result = $confirm;
            $rounds++;
        }
    }

    /**
     * Find a booking option in the single test instance that links the given course.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    private function find_option_linking_course(int $courseid): ?\stdClass {
        global $DB;
        $options = $DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id]);
        foreach ($options as $option) {
            if ((int)$option->courseid === $courseid) {
                return $option;
            }
        }
        return null;
    }

    /**
     * Dump the thread's LLM debug trail to STDERR to locate where a failing run derailed.
     *
     * @param int $threadid
     * @return void
     */
    private function dump_llm_debug(int $threadid): void {
        global $DB;
        foreach ($DB->get_records('bx_agent_ai_llm_debug', ['threadid' => $threadid], 'id ASC') as $row) {
            fwrite(STDERR, "\n[llmdebug " . $row->id . '] ' . $row->source . "\nRESPONSE: "
                . substr((string)$row->responsetext, 0, 600) . "\n");
        }
    }

    /**
     * Two price categories (default + student), the self-learning flag, and the authoring
     * capability set (same non-admin persona as course_authoring_compound_real_llm_test).
     *
     * @return void
     */
    private function prepare_authoring_environment(): void {
        set_config('selflearningcourseactive', 1, 'booking');

        foreach ([['default', 'Standardpreis', 30], ['student', 'Studierende', 20]] as $i => $cat) {
            $this->gen->create_pricecategory((object)[
                'ordernum' => $i + 1,
                'identifier' => $cat[0],
                'name' => $cat[1],
                'defaultvalue' => $cat[2],
            ]);
        }

        $systemcontext = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        $capabilities = [
            'moodle/course:create',
            'moodle/course:update',
            'moodle/course:view',
            'moodle/course:manageactivities',
            'bookingextension/agent:skill_course_create_course',
            'bookingextension/agent:skill_course_scaffold_course_content',
            'mod/label:addinstance',
            'mod/page:addinstance',
            'mod/quiz:addinstance',
            'mod/booking:duplicateanycourse',
        ];
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id);
        }
        role_assign($roleid, $this->teacher->id, $systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Scaffold present = the course carries at least one page module.
     *
     * @param int $courseid
     * @return bool
     */
    private function course_has_scaffold(int $courseid): bool {
        return count(get_fast_modinfo($courseid)->get_instances_of('page')) > 0;
    }
}
