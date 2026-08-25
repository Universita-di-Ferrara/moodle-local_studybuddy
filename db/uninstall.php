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

use local_studybuddy\local\provider\google\google_course_file_search_store_service;
use local_studybuddy\local\provider\openai\openai_course_vector_store_service;
use local_studybuddy\local\provider\vertexai\vertexai_course_rag_service;

/**
 * Removes best-effort remote provider resources before local tables disappear.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
function xmldb_local_studybuddy_uninstall(): bool {
    global $DB;

    $stores = $DB->get_records('local_studybuddy_stores', [], '', 'courseid, provider');
    foreach ($stores as $store) {
        try {
            if ($store->provider === 'openai') {
                (new openai_course_vector_store_service())->delete_course_resources(
                    (int)$store->courseid
                );
            } else if ($store->provider === 'google') {
                (new google_course_file_search_store_service())->delete_course_resources(
                    (int)$store->courseid
                );
            } else if ($store->provider === 'vertexai') {
                (new vertexai_course_rag_service())->delete_course_resources(
                    (int)$store->courseid
                );
            }
        } catch (\Throwable $e) {
            debugging(get_string('uninstallcleanupfailed', 'local_studybuddy'), DEBUG_DEVELOPER);
        }
    }

    return true;
}
