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
 * The page's own plugin breaks a tie — and never more than that.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\discovery\context_prior_builder;
use bookingextension_agent\local\wizard\services\discovery\family_signal_ranker;
use bookingextension_agent\local\wizard\services\embeddings\family_embeddings_retrieval_service;

/**
 * Tests for the context-owner preference in family ranking.
 *
 * The request behind this: "on a taskflow assignment page 'rules' means taskflow rules; on mod/booking/view.php
 * the same word means booking rules" (George, 2026-09-20). The signal is the Moodle context and page type, never
 * the wording of the request.
 *
 * The danger is the opposite case, and it is the common one in the baselines: sitting inside a booking module
 * while asking about a course. UA-3, SC-2, ACS-2 and EU-2 all do exactly that, and all four need a namespace
 * other than the one owning the page. So the preference must be small enough that a clear semantic winner from
 * another namespace survives it. Both directions are pinned here.
 *
 * @covers \bookingextension_agent\local\wizard\services\discovery\family_signal_ranker
 * @covers \bookingextension_agent\local\wizard\services\embeddings\family_embeddings_retrieval_service
 */
final class context_family_tiebreak_test extends \advanced_testcase {
    /**
     * Rank two candidates and return the winning skill name.
     *
     * @param float $bookingscore Cosine score of the booking candidate.
     * @param float $taskflowscore Cosine score of the taskflow candidate.
     * @param string $owner Namespace owning the current page ('' = none).
     * @return string
     */
    private function winner(float $bookingscore, float $taskflowscore, string $owner): string {
        $prior = (new context_prior_builder())->build(1, ['context_namespace' => $owner]);
        $families = ['mod_booking.general', 'local_taskflow.general'];
        $familyscores = (new family_signal_ranker())->score_families($families, $prior, []);

        $rows = (new family_embeddings_retrieval_service())->boost_skill_rows([
            ['skill' => 'mod_booking.analyze_rules', 'score' => $bookingscore],
            ['skill' => 'local_taskflow.search_rules', 'score' => $taskflowscore],
        ], $familyscores);

        return (string)$rows[0]['skill'];
    }

    /**
     * On a taskflow page the taskflow skill wins a near-tie; on a booking page the booking skill does.
     */
    public function test_the_owning_plugin_wins_a_near_tie(): void {
        $this->resetAfterTest();

        $this->assertSame(
            'local_taskflow.search_rules',
            $this->winner(0.701, 0.700, 'local_taskflow'),
            'On a taskflow page the taskflow sibling must win an otherwise even match.'
        );
        $this->assertSame(
            'mod_booking.analyze_rules',
            $this->winner(0.700, 0.701, 'mod_booking'),
            'On a booking page the booking sibling must win an otherwise even match.'
        );
    }

    /**
     * A clear semantic winner from ANOTHER namespace survives the preference.
     *
     * This is the case the baselines are full of: the user sits in a booking module and asks about a course.
     * A gap of 0.10 is far more than the preference may move.
     */
    public function test_a_clear_winner_from_another_namespace_survives(): void {
        $this->resetAfterTest();

        $this->assertSame(
            'local_taskflow.search_rules',
            $this->winner(0.60, 0.70, 'mod_booking'),
            'The page owner must not overturn a candidate that is clearly better semantically.'
        );
    }

    /**
     * Without an identified owner the ranking is the pure semantic one.
     */
    public function test_no_owner_changes_nothing(): void {
        $this->resetAfterTest();

        $this->assertSame('mod_booking.analyze_rules', $this->winner(0.701, 0.700, ''));
        $this->assertSame('local_taskflow.search_rules', $this->winner(0.700, 0.701, ''));
    }

    /**
     * Catalogue popularity must not act as a context signal: namespace_hint alone may not decide a tie.
     *
     * It resolves to whichever plugin registers the most skills and is identical on every page of the site.
     * Until 2026-09-20 it carried the largest weight of all, which would have preferred that one plugin
     * everywhere.
     */
    public function test_catalogue_popularity_alone_does_not_decide(): void {
        $this->resetAfterTest();

        $prior = (new context_prior_builder())->build(1, ['namespace_hint' => 'local_taskflow']);
        $scores = (new family_signal_ranker())->score_families(
            ['mod_booking.general', 'local_taskflow.general'],
            $prior,
            []
        );
        $rows = (new family_embeddings_retrieval_service())->boost_skill_rows([
            ['skill' => 'mod_booking.analyze_rules', 'score' => 0.72],
            ['skill' => 'local_taskflow.search_rules', 'score' => 0.70],
        ], $scores);

        $this->assertSame(
            'mod_booking.analyze_rules',
            (string)$rows[0]['skill'],
            'Popularity may nudge, but must not flip a candidate that scored higher.'
        );
    }
}
