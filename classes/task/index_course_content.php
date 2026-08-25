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

namespace local_studybuddy\task;

use local_studybuddy\local\provider\google\google_course_file_search_store_service;
use local_studybuddy\local\provider\openai\openai_course_vector_store_service;
use local_studybuddy\local\provider\vertexai\vertexai_course_rag_service;

/**
 * Scheduled course content indexing task.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class index_course_content extends \core\task\scheduled_task {
    /**
     * Returns the task name.
     *
     * @return string Task name.
     */
    public function get_name(): string {
        return get_string('task:index_course_content', 'local_studybuddy');
    }

    /**
     * Indexes course sources using the configured provider.
     *
     * @return void
     */
    public function execute(): void {
        $provider = (string)get_config('local_studybuddy', 'provider');
        if ($provider === 'openai') {
            $summary = (new openai_course_vector_store_service())->sync_all_courses(20);

            if (CLI_SCRIPT) {
                mtrace('[local_studybuddy] OpenAI sync: ' . $summary['courses'] . ' courses, ' .
                    $summary['uploaded'] . ' uploaded, ' . $summary['completed'] . ' completed.');
            }

            return;
        }

        if ($provider === 'google') {
            $summary = (new google_course_file_search_store_service())->sync_all_courses(20);

            if (CLI_SCRIPT) {
                mtrace('[local_studybuddy] Google sync: ' . $summary['courses'] . ' courses, ' .
                    $summary['uploaded'] . ' uploaded, ' . $summary['completed'] . ' completed.');
            }

            return;
        }

        if ($provider === 'vertexai') {
            $summary = (new vertexai_course_rag_service())->sync_all_courses(20);

            if (CLI_SCRIPT) {
                mtrace('[local_studybuddy] Vertex AI RAG sync: ' . $summary['courses'] . ' courses, ' .
                    $summary['uploaded'] . ' uploaded, ' . $summary['completed'] . ' completed.');
            }

            return;
        }

        throw new \moodle_exception('invalidprovider', 'local_studybuddy', '', $provider ?: '');
    }
}
