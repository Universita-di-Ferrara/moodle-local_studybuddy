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

namespace local_studybuddy\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Gets indexing status.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_sync_status extends external_api {
    /**
     * Returns the external function parameters.
     *
     * @return external_function_parameters Function parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, external_description::get('courseid')),
        ]);
    }

    /**
     * Returns the current course source synchronisation status.
     *
     * @param int $courseid Course id.
     * @return array Synchronisation status.
     */
    public static function execute(int $courseid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $context = \context_course::instance((int)$params['courseid']);
        self::validate_context($context);
        if (
            !has_any_capability([
            'local/studybuddy:chat',
            'local/studybuddy:managesources',
            ], $context)
        ) {
            require_capability('local/studybuddy:chat', $context);
        }

        return (new \local_studybuddy\local\source_service())->get_sync_status(
            (int)$params['courseid'],
            (int)$USER->id
        );
    }

    /**
     * Returns the external function response structure.
     *
     * @return external_single_structure Response structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'total' => new external_value(PARAM_INT, external_description::get('totalsources')),
            'ready' => new external_value(PARAM_INT, external_description::get('readysources')),
            'enabled' => new external_value(PARAM_INT, external_description::get('enabledreadysources')),
            'available' => new external_value(PARAM_INT, external_description::get('availablesources')),
            'stale' => new external_value(PARAM_INT, external_description::get('stalesources')),
            'status' => new external_value(PARAM_TEXT, external_description::get('syncstatus')),
            'provider' => new external_value(PARAM_TEXT, external_description::get('selectedprovider')),
            'providerlabel' => new external_value(PARAM_TEXT, external_description::get('selectedproviderlabel')),
            'jobid' => new external_value(PARAM_INT, external_description::get('latestjobid')),
            'storestatus' => new external_value(PARAM_TEXT, external_description::get('providerstorestatus')),
            'completedfiles' => new external_value(PARAM_INT, external_description::get('completedfiles')),
            'activefiles' => new external_value(PARAM_INT, external_description::get('activefiles')),
            'failedfiles' => new external_value(PARAM_INT, external_description::get('failedfiles')),
            'pendingfiles' => new external_value(PARAM_INT, external_description::get('pendingfiles')),
            'lasterror' => new external_value(PARAM_RAW, external_description::get('lastsyncerror')),
            'needsreindex' => new external_value(PARAM_BOOL, external_description::get('needsreindex')),
        ]);
    }
}
