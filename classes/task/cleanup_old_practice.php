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

/**
 * Deletes expired temporary practice generations.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_old_practice extends \core\task\scheduled_task {
    /**
     * Returns the task name.
     *
     * @return string Task name.
     */
    public function get_name(): string {
        return get_string('task:cleanup_old_practice', 'local_studybuddy');
    }

    /**
     * Deletes expired temporary practice records.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $params = ['now' => time()];
        $deleted = $DB->count_records_select('local_studybuddy_practice', 'expiresat < :now', $params);
        $DB->delete_records_select('local_studybuddy_practice', 'expiresat < :now', $params);

        if (CLI_SCRIPT) {
            mtrace(get_string('task:cleanupcount', 'local_studybuddy', $deleted));
        }
    }
}
