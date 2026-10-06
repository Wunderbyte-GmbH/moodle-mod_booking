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

namespace bookingextension_agent\local\wizard\services\discovery;

use bookingextension_agent\local\wizard\contracts\skill_family_contract;
use bookingextension_agent\local\wizard\dto\discovery_result;

/**
 * Resolves deterministic family candidates from prompt contracts.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class family_registry_service {
    /** @var core_family_set */
    private core_family_set $corefamilyset;

    /**
     * Constructor.
     *
     * @param core_family_set|null $corefamilyset
     */
    public function __construct(?core_family_set $corefamilyset = null) {
        $this->corefamilyset = $corefamilyset ?? new core_family_set();
    }

    /**
     * Discover family candidates for the current context.
     *
     * @param array[] $promptcontracts
     * @param array $contextprior
     * @return discovery_result
     */
    public function discover(array $promptcontracts, array $contextprior = []): discovery_result {
        $allfamilies = [];
        foreach ($promptcontracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $skillname = trim((string)($contract['skill'] ?? ''));
            $family = skill_family_contract::resolve_from_prompt_contract($contract, $skillname);
            $allfamilies[] = $family;
        }

        $allfamilies = array_values(array_unique(array_filter(array_map('strval', $allfamilies))));
        sort($allfamilies, SORT_STRING);

        $namespacehint = trim((string)($contextprior['namespace_hint'] ?? ''));
        $contextfamilies = [];
        if ($namespacehint !== '') {
            foreach ($allfamilies as $family) {
                if (strpos($family, $namespacehint . '.') === 0) {
                    $contextfamilies[] = $family;
                }
            }
        }

        if (empty($contextfamilies)) {
            $contextfamilies = $allfamilies;
        }

        $corefamilies = $this->corefamilyset->resolve($promptcontracts);

        // Context is a ranking PRIOR, not a hard filter (flowchart LG_DET). The ranking
        // universe must therefore stay the FULL family set so the semantic/intent signal can
        // surface a cross-namespace family (e.g. course.* from a booking context). The
        // namespace match only marks the Stage A prior subset ($contextfamilies); it must NOT
        // narrow the candidate universe, otherwise non-context skills become undiscoverable.
        $families = array_values(array_unique(array_merge($allfamilies, $corefamilies)));
        sort($families, SORT_STRING);

        return new discovery_result($families, $contextfamilies, $corefamilies, $contextprior);
    }
}
