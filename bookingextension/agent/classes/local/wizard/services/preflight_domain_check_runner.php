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

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\dto\preflight_result_v2;
use bookingextension_agent\local\wizard\booking_issue_code_provider;
use bookingextension_agent\local\wizard\interfaces\issue_code_provider_interface;

/**
 * Layer-2 domain preflight checks (read-only).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_domain_check_runner {
    /**
     * Per-command preflight time budget in milliseconds.
     *
     * The elapsed check receives the preflight START time, so it measures everything the
     * pipeline did before this layer (schema validation, target resolution, Gate 2, the
     * skills' own preflights) — not the domain classification below. The budget therefore
     * scales with the number of commands in the batch: a five-option series is allowed five
     * budgets. The per-command value is sized for slow dev hardware; a single mform-backed
     * create preflight can legitimately take several hundred ms (threads 544/549: the old
     * flat 500 ms batch budget made every series creation time out deterministically).
     *
     * @var int
     */
    private const PER_COMMAND_TIMEOUT_MS = 2000;

    /** @var issue_code_provider_interface Supplies the domain-specific confirmable issue codes. */
    private issue_code_provider_interface $issuecodeprovider;

    /**
     * Constructor.
     *
     * @param issue_code_provider_interface|null $issuecodeprovider Domain issue-code provider.
     *        Defaults to the booking provider (same fallback pattern as agent_decision_service),
     *        so the engine carries no booking-specific issue-code knowledge of its own.
     */
    public function __construct(?issue_code_provider_interface $issuecodeprovider = null) {
        $this->issuecodeprovider = $issuecodeprovider ?? new booking_issue_code_provider();
    }

    /**
     * Evaluate domain-level issue codes and classify the result.
     *
     * @param string[] $issuecodes
     * @param float $startmicrotime Preflight batch start (microtime), for the time budget.
     * @param int $commandcount Number of commands in the batch; scales the time budget.
     * @return preflight_result_v2
     */
    public function run(array $issuecodes, float $startmicrotime, int $commandcount = 1): preflight_result_v2 {
        $elapsedms = (int)max(0, (microtime(true) - $startmicrotime) * 1000);
        $budgetms = self::PER_COMMAND_TIMEOUT_MS * max(1, $commandcount);
        if ($elapsedms > $budgetms) {
            return new preflight_result_v2(
                'retry_hint',
                ['DOMAIN_CHECK_TIMEOUT'],
                'domain',
                500,
                0,
                $elapsedms
            );
        }

        $normalizedcodes = array_values(array_unique(array_filter(array_map('trim', $issuecodes))));
        $hardblockcodes = [
            'PERMISSION_ERROR',
            'VALIDATION_ERROR',
            'SCHEMA_ERROR',
        ];
        // DOMAIN_CONFLICT is the engine-generic confirmable (soft-block) code; the
        // domain-specific confirmable codes (e.g. DUPLICATE_TITLE_*) come from the issue-code
        // provider, so this engine layer holds no booking-specific knowledge of its own.
        $softblockcodes = array_values(array_unique(array_merge(
            ['DOMAIN_CONFLICT'],
            array_map(
                static fn($code): string => strtoupper(trim((string)$code)),
                $this->issuecodeprovider->get_prevalidation_confirmable_issue_codes()
            )
        )));
        foreach ($normalizedcodes as $code) {
            $normalizedcode = strtoupper(trim($code));
            if ($normalizedcode === '') {
                continue;
            }
            if (in_array($normalizedcode, $hardblockcodes, true) || str_starts_with($normalizedcode, 'MISSING_')) {
                return new preflight_result_v2('hard_block', [$normalizedcode], 'domain', 0, 0, $elapsedms);
            }
            if (in_array($normalizedcode, $softblockcodes, true)) {
                return new preflight_result_v2('soft_block', [$normalizedcode], 'domain', 0, 0, $elapsedms);
            }
        }

        return new preflight_result_v2('pass', [], '', 0, 0, $elapsedms);
    }
}
