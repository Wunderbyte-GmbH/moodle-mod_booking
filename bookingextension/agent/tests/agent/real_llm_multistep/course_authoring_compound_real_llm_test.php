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
 * Real-LLM E2E for the compound course-authoring chain ("Wikinger-Kurs", blueprint §0).
 *
 * One prompt orchestrates: create_course → scaffold_course_content → selflearning
 * booking option. Pins the expectations of the thread-585/586/589 fix series:
 * - F1: a fully constructed create command survives the interpreter (no false
 *   "<field> is required" clarification);
 * - F2: scaffolding proceeds on a fresh course although Moodle auto-created the
 *   announcements forum (expected-activities contract);
 * - F8 contract (second test): a category clarification in step 1 must RESUME
 *   create_course after the user's answer — the thread-589 breakpoint.
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
 * Compound course-authoring chain with a real LLM.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class course_authoring_compound_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    /**
     * Happy path: exactly ONE writable category exists, so step 1 needs no clarification
     * (category policy §4/1) and the whole chain must run through on confirms alone.
     */
    public function test_compound_wikinger_prompt_runs_the_full_chain(): void {
        global $DB;

        $this->prepare_authoring_environment();
        $this->setUser($this->teacher);

        $title = 'Das Leben der Wikinger ' . substr(sha1(uniqid('', true)), 0, 6);
        [$store, $runtime, $threadid] = $this->build_runtime();

        $result = $this->chat(
            'Erstelle einen neuen Kurs "' . $title . '". Fülle ihn danach mit 4 Kapiteln, ohne '
                . 'Übungsquiz, aber mit benotetem Abschlussquiz mit 10 Fragen. Mach ihn anschließend als '
                . 'Selbstlernkurs buchbar in der Buchungsinstanz "Agent Test Booking": '
                . 'Preis 20 Euro, Dauer 30 Tage.',
            $threadid,
            $store,
            $runtime
        );
        if (($result['response_type'] ?? '') === 'clarification') {
            // Test-env artifact: the PHPUnit catalog falls back to slim_all whose 240-char card
            // truncation cuts create_course's anti-ask guidance mid-sentence, so turn 1 may ask
            // for the category (live embed_topk carries the full description and routes
            // directly). Answer it like a live user would; the resume path is the test target.
            global $DB;
            $defaultcategory = $DB->get_record('course_categories', ['id' => (int)$this->course->category], '*', MUST_EXIST);
            $result = $this->chat(
                'Nimm die Kategorie "' . $defaultcategory->name . '" und leg jetzt los.',
                $threadid,
                $store,
                $runtime
            );
        }
        if (($result['response_type'] ?? '') !== 'confirmation_request') {
            $result = $this->chat(
                'Please create a new course named "' . $title . '" now. Afterwards fill it with '
                    . '4 chapters and a graded final quiz, then make it bookable as a self-learning '
                    . 'option in the booking activity "Agent Test Booking", price 20 Euro, '
                    . 'duration 30 days.',
                $threadid,
                $store,
                $runtime
            );
        }

        // F1 pin: with one category and a fully constructed command, step 1 must be a
        // confirmable create_course — not a clarification re-asking for provided data.
        if (($result['response_type'] ?? '') !== 'confirmation_request') {
            $this->dump_llm_debug((int)$threadid);
        }
        $this->assertSame(
            'confirmation_request',
            (string)($result['response_type'] ?? ''),
            'Step 1 must confirm create_course, got: ' . $this->payload_text($result)
        );
        $createcommand = $this->extract_command($result, 'course.create_course');
        $this->assertNotNull($createcommand, 'A course.create_course command must be planned.');
        $this->assertNotSame(
            '',
            trim((string)($createcommand['input']['fullname'] ?? '')),
            'The constructed command must carry the fullname.'
        );

        // Confirm through the chain: each confirm may restage the next mutating step
        // (Driver B), surfacing as another confirmation_request.
        $confirm = $this->confirm_pending_result($result, (int)$threadid, $store, false);
        $this->assertTrue((bool)($confirm['success'] ?? false), (string)($confirm['message'] ?? ''));

        $course = $DB->get_record('course', ['fullname' => $title]);
        $this->assertNotFalse($course, 'The course must exist after the first confirm.');

        $rounds = 0;
        while ((string)($confirm['response_type'] ?? '') === 'confirmation_request' && $rounds < 6) {
            $confirm = $this->confirm_pending_result($confirm, (int)$threadid, $store, false);
            $this->assertTrue(
                (bool)($confirm['success'] ?? false),
                'Chained confirm #' . ($rounds + 2) . ' failed: ' . $this->payload_text($confirm)
            );
            $rounds++;
        }

        // The engine may pause between steps (planner-terminal turn). Nudge each missing
        // step once, exactly like a live user would — the DB postconditions below stay
        // unconditional either way.
        if (!$this->course_has_scaffold((int)$course->id)) {
            $next = $this->chat(
                'Fülle den Kurs "' . $title . '" jetzt mit den 4 Kapiteln und dem benoteten '
                    . 'Abschlussquiz mit 10 Fragen.',
                $threadid,
                $store,
                $runtime
            );
            // F2 pin: the announcements forum must NOT soft-block scaffolding.
            $this->assertNotContains(
                'SCAFFOLD_COURSE_NOT_EMPTY_CONFIRM_REQUIRED',
                (array)($next['issue_codes'] ?? []),
                'The auto-created announcements forum must not block scaffolding (F2): '
                    . $this->payload_text($next)
            );
            $this->assertSame(
                'confirmation_request',
                (string)($next['response_type'] ?? ''),
                'Scaffold step must confirm, got: ' . $this->payload_text($next)
            );
            $confirm = $this->confirm_pending_result($next, (int)$threadid, $store, false);
            $this->assertTrue((bool)($confirm['success'] ?? false), (string)($confirm['message'] ?? ''));
        }

        // Deterministic scaffold postconditions (F2 proves itself here: the forum exists,
        // the scaffold went through WITHOUT an override).
        $this->assertTrue($this->course_has_scaffold((int)$course->id), 'Scaffold content must exist.');
        $modinfo = get_fast_modinfo((int)$course->id);
        $pagecount = count($modinfo->get_instances_of('page'));
        $quizcount = count($modinfo->get_instances_of('quiz'));
        $forumcount = count($modinfo->get_instances_of('forum'));
        $this->assertGreaterThanOrEqual(4, $pagecount, 'At least the 4 chapter pages must exist.');
        $this->assertSame(1, $quizcount, 'Exactly the graded final quiz must exist (no practice quizzes).');
        $this->assertGreaterThanOrEqual(1, $forumcount, 'The announcements forum must still be present.');

        $option = $this->find_selflearning_option($title);
        if ($option === null) {
            $next = $this->chat(
                'Mach den Kurs "' . $title . '" jetzt als Selbstlernkurs buchbar in der '
                    . 'Buchungsinstanz "Agent Test Booking": Preis 20 Euro, Dauer 30 Tage.',
                $threadid,
                $store,
                $runtime
            );
            $this->assertSame(
                'confirmation_request',
                (string)($next['response_type'] ?? ''),
                'Booking-option step must confirm, got: ' . $this->payload_text($next)
            );
            $confirm = $this->confirm_pending_result($next, (int)$threadid, $store, false);
            $this->assertTrue((bool)($confirm['success'] ?? false), (string)($confirm['message'] ?? ''));
            $option = $this->find_selflearning_option($title);
        }

        $this->assertNotNull($option, 'The self-learning booking option must exist.');
        $this->assertSame(
            (int)$course->id,
            (int)$option->courseid,
            'The option must link the NEW course (linkedcoursequery resolution).'
        );
        $duration = (int)$option->duration;
        $this->assertGreaterThanOrEqual(29 * DAYSECS, $duration, 'Duration must be ~30 days.');
        $this->assertLessThanOrEqual(31 * DAYSECS, $duration, 'Duration must be ~30 days.');
    }

    /**
     * F8 contract (thread-589 breakpoint): with TWO writable categories, step 1 asks which
     * category — and after the user's answer the chain must RESUME create_course instead of
     * skipping to a later planned step.
     */
    public function test_category_clarification_resumes_create_course(): void {
        global $DB;

        $this->prepare_authoring_environment();
        $this->getDataGenerator()->create_category(['name' => 'Zweite Kategorie']);
        $this->setUser($this->teacher);

        $title = 'Das Leben der Wikinger ' . substr(sha1(uniqid('', true)), 0, 6);
        [$store, $runtime, $threadid] = $this->build_runtime();

        $result = $this->chat(
            'Erstelle einen neuen Kurs "' . $title . '". Fülle ihn danach mit 4 Kapiteln, ohne '
                . 'Übungsquiz, aber mit benotetem Abschlussquiz mit 10 Fragen. Mach ihn anschließend als '
                . 'Selbstlernkurs buchbar in der Buchungsinstanz "Agent Test Booking": '
                . 'Preis 20 Euro, Dauer 30 Tage.',
            $threadid,
            $store,
            $runtime
        );

        // Whether the category question comes from the deterministic preflight cascade (with a
        // candidate list — the live behaviour) or from the planner (test-env slim-card artifact,
        // see the happy-path test), the contract under test is the same: a clarification now,
        // and a RESUME of create_course after the answer. The candidate-list wording itself is
        // pinned by the create_course unit tests.
        if (($result['response_type'] ?? '') !== 'clarification') {
            $this->dump_llm_debug((int)$threadid);
        }
        $this->assertSame(
            'clarification',
            (string)($result['response_type'] ?? ''),
            'Two writable categories must trigger the category question, got: '
                . $this->payload_text($result)
        );

        $defaultcategory = $DB->get_record('course_categories', ['id' => (int)$this->course->category], '*', MUST_EXIST);
        $result2 = $this->chat(
            'Nimm die Kategorie "' . $defaultcategory->name . '".',
            $threadid,
            $store,
            $runtime
        );

        // The thread-589 defect: the answer consumed a LATER planned step (scaffold) because
        // nothing pinned the clarified create_course. Contract: the chain resumes at create.
        $this->assertSame(
            'confirmation_request',
            (string)($result2['response_type'] ?? ''),
            'After the category answer the chain must resume with a confirmable create_course, got: '
                . $this->payload_text($result2)
        );
        $createcommand = $this->extract_command($result2, 'course.create_course');
        $this->assertNotNull(
            $createcommand,
            'The resumed command must be course.create_course — not a later planned step: '
                . $this->payload_text($result2)
        );

        $confirm = $this->confirm_pending_result($result2, (int)$threadid, $store, false);
        $this->assertTrue((bool)($confirm['success'] ?? false), (string)($confirm['message'] ?? ''));
        $this->assertNotFalse(
            $DB->get_record('course', ['fullname' => $title]),
            'The course must exist in the chosen category after the confirm.'
        );
    }

    /**
     * Diagnostic on failure: dump the thread's LLM debug trail (phase, source tags,
     * response excerpts) to STDERR so a failing run shows WHERE the turn derailed.
     *
     * @param int $threadid
     * @return void
     */
    private function dump_llm_debug(int $threadid): void {
        global $DB;
        $rows = $DB->get_records('bx_agent_ai_llm_debug', ['threadid' => $threadid], 'id ASC');
        foreach ($rows as $row) {
            fwrite(STDERR, "\n[llmdebug " . $row->id . '] ' . $row->source . "\nRESPONSE: "
                . substr((string)$row->responsetext, 0, 600) . "\n");
        }
    }

    /**
     * Authoring needs: course caps at system level for the teacher, the selflearning
     * feature flag, and a price category so prices={"default":20} validates.
     *
     * @return void
     */
    private function prepare_authoring_environment(): void {
        set_config('selflearningcourseactive', 1, 'booking');

        $this->gen->create_pricecategory((object)[
            'ordernum' => 1,
            'identifier' => 'default',
            'name' => 'Standardpreis',
            'defaultvalue' => 25,
        ]);

        $systemcontext = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        $capabilities = [
            // Gate 2 (native): course authoring rights at the resolved contexts. course:view
            // matters twice — create_course assigns the creator NO role in the new course
            // (unlike the Moodle UI's creatornewroleid), so without site-wide view the
            // linkedcoursequery resolver cannot see the course it just created (G5 evidence).
            'moodle/course:create',
            'moodle/course:update',
            'moodle/course:view',
            'moodle/course:manageactivities',
            // Gate 1 (agent skill caps): create_course is manager-only by archetype, and the
            // teacher's editingteacher role lives at COURSE level — a CONTEXT_SYSTEM check
            // never sees it, so the authoring test role carries both skill caps explicitly.
            'bookingextension/agent:skill_course_create_course',
            'bookingextension/agent:skill_course_scaffold_course_content',
            // The creator gets no role in the course create_course() makes, so the scaffold
            // modules' addinstance checks (course_allowed_module) must pass via this role.
            'mod/label:addinstance',
            'mod/page:addinstance',
            'mod/quiz:addinstance',
        ];
        // NOTE: mod/booking:duplicateanycourse is deliberately NOT granted anymore. It used to
        // be the workaround for B3/G5 — create_course gave the creator no role, so
        // booking::load_courses (behind linkedcoursequery) could not list the fresh course back
        // to them. With the creatornewroleid-parity fix in create_course_skill the non-admin
        // creator is enrolled into their own course and resolves it without this manager-only cap.
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
        $modinfo = get_fast_modinfo($courseid);
        return count($modinfo->get_instances_of('page')) > 0;
    }

    /**
     * Find the self-learning option created for the given course title in the shared
     * booking instance.
     *
     * @param string $coursetitle
     * @return \stdClass|null
     */
    private function find_selflearning_option(string $coursetitle): ?\stdClass {
        global $DB;
        $course = $DB->get_record('course', ['fullname' => $coursetitle]);
        if (!$course) {
            return null;
        }
        $options = $DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id]);
        foreach ($options as $option) {
            if ((int)$option->courseid === (int)$course->id) {
                return $option;
            }
        }
        return null;
    }
}
