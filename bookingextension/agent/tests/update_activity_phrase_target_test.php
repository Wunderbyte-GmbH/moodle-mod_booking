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

namespace bookingextension_agent;

use advanced_testcase;
use context_course;
use bookingextension_agent\local\wizard\course\skills\update_activity_skill;

/**
 * A phrase that describes an activity finds it by the words that match.
 *
 * UA-3 (N46 threads 18455/18538, replay of the L49 call: 5 of 20 runs): the constructor writes "le lien vers la
 * fédération" - the URL is called "Fédération nationale d'apiculture". No name holds the phrase, and the name's words
 * are not all in the phrase, so the turn asked for the activity. As for persons and courses: the words that match
 * any activity must agree on the candidates, a word that matches nothing carries no meaning.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\course\skills\update_activity_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class update_activity_phrase_target_test extends advanced_testcase {
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass */
    private $course;
    /** @var int */
    private int $ctxid = 0;
    /** @var \stdClass The URL activity. */
    private $url;

    /**
     * A course with a URL, a page and a forum; the teacher acts from the course context.
     */
    protected function setUp(): void {
        global $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['fullname' => 'Apikultur – Bienenkunde']);
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->url = $this->getDataGenerator()->create_module('url', [
            'course' => $this->course->id,
            'name' => "Fédération nationale d'apiculture",
            'externalurl' => 'https://www.example.org/old',
        ]);
        $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'name' => 'Skript Bienenkunde']);
        $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id, 'name' => 'Fragen zur Prüfung']);
        $coursecontext = context_course::instance($this->course->id);
        $this->ctxid = (int)$coursecontext->id;
        $this->setUser($this->teacher);
        $PAGE->set_context($coursecontext);
    }

    /**
     * Preflight of an update with a name for the activity.
     *
     * @param string $activityquery
     * @return \bookingextension_agent\local\wizard\dto\preflight_result
     */
    private function preflight(string $activityquery) {
        return (new update_activity_skill())->preflight(
            ['activityquery' => $activityquery, 'settings' => ['externalurl' => 'https://www.example.org/new']],
            $this->ctxid,
            (int)$this->teacher->id
        );
    }

    /**
     * The describing phrase, with and without the accent, finds the URL.
     */
    public function test_a_describing_phrase_finds_the_activity(): void {
        foreach (['le lien vers la fédération', 'the link to the federation'] as $phrase) {
            $pf = $this->preflight($phrase);
            $this->assertSame('pass', (string)$pf->status, $phrase . ': ' . json_encode($pf->issues));
            $this->assertSame((int)$this->url->cmid, (int)($pf->preparedinput['cmid'] ?? 0), $phrase);
        }
    }

    /**
     * Non-success paths: a word that matches several activities leaves a choice (a clarification with the candidates,
     * never an error); words that match different activities do not agree, and a phrase whose words match nothing is
     * not found - nothing is guessed either way.
     */
    public function test_several_or_no_matches_do_not_guess(): void {
        $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'name' => 'Bienenkunde Woche 2']);

        $pf = $this->preflight('das Dokument über Bienenkunde');
        $this->assertNotSame('pass', (string)$pf->status);
        $this->assertNotSame('error', (string)$pf->status);
        $codes = array_map(static fn($issue): string => (string)((array)$issue)['code'], (array)$pf->issues);
        $this->assertContains('UPDATE_ACTIVITY_AMBIGUOUS', $codes, json_encode($pf->issues));

        // The word "zur" stands in the forum "Fragen zur Prüfung", "Bienenkunde" in the pages: no activity carries both.
        foreach (['die Seite zur Bienenkunde', 'der Kalender der Exkursion'] as $phrase) {
            $pf = $this->preflight($phrase);
            $this->assertNotSame('pass', (string)$pf->status, $phrase);
            $codes = array_map(static fn($issue): string => (string)((array)$issue)['code'], (array)$pf->issues);
            $this->assertContains('UPDATE_ACTIVITY_NOT_FOUND', $codes, $phrase . ': ' . json_encode($pf->issues));
        }
    }
}
