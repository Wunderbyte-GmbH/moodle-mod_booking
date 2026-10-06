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

use bookingextension_agent\local\wizard\skill_registry;

/**
 * Unified L1 contract validator (schema + version/deprecation policy).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_contract_validator {
    /** Skill registration issue code. */
    public const ISSUE_SKILL_NOT_REGISTERED = preflight_version_validator::ISSUE_SKILL_NOT_REGISTERED;

    /** @var preflight_schema_validator */
    private preflight_schema_validator $schemavalidator;

    /** @var preflight_version_validator */
    private preflight_version_validator $versionvalidator;

    /** @var skill_version_policy */
    private skill_version_policy $versionpolicy;

    /**
     * Constructor.
     *
     * @param skill_registry $registry
     * @param preflight_schema_validator|null $schemavalidator
     * @param preflight_version_validator|null $versionvalidator
     * @param skill_version_policy|null $versionpolicy
     */
    public function __construct(
        skill_registry $registry,
        ?preflight_schema_validator $schemavalidator = null,
        ?preflight_version_validator $versionvalidator = null,
        ?skill_version_policy $versionpolicy = null
    ) {
        $this->versionpolicy = $versionpolicy ?? new skill_version_policy();
        $this->versionvalidator = $versionvalidator ?? new preflight_version_validator($registry, $this->versionpolicy);
        $this->schemavalidator = $schemavalidator ?? new preflight_schema_validator();
    }

    /**
     * Validate one command against the L1 preflight contract.
     *
     * @param array $command
     * @return array{valid:bool,error_class:string,issue_codes:string[],errors:string[]}
     */
    public function validate(array $command): array {
        $schemaresult = $this->schemavalidator->validate($command);
        if (($schemaresult['valid'] ?? false) !== true) {
            return [
                'valid' => false,
                'error_class' => trim((string)($schemaresult['error_class'] ?? 'schema_error')),
                'issue_codes' => array_values(array_unique(array_filter(array_map(
                    'strval',
                    (array)($schemaresult['issue_codes'] ?? ['SCHEMA_ERROR'])
                )))),
                'errors' => array_values(array_unique(array_filter(array_map('strval', (array)($schemaresult['errors'] ?? []))))),
            ];
        }

        $versionresult = $this->versionvalidator->validate($command);
        if (($versionresult['valid'] ?? false) !== true) {
            return [
                'valid' => false,
                'error_class' => trim((string)($versionresult['error_class'] ?? 'schema_error')),
                'issue_codes' => array_values(array_unique(array_filter(
                    array_map('strval', (array)($versionresult['issue_codes'] ?? []))
                ))),
                'errors' => array_values(array_unique(array_filter(array_map('strval', (array)($versionresult['errors'] ?? []))))),
            ];
        }

        $mergedissuecodes = array_values(array_unique(array_filter(array_merge(
            array_map('strval', (array)($schemaresult['issue_codes'] ?? [])),
            array_map('strval', (array)($versionresult['issue_codes'] ?? []))
        ))));

        return [
            'valid' => true,
            'error_class' => '',
            'issue_codes' => $mergedissuecodes,
            'errors' => [],
        ];
    }
}
