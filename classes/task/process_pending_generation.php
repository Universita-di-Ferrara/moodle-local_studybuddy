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
 * Adhoc task that processes queued AI generation jobs.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_pending_generation extends \core\task\adhoc_task {
    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:process_pending_generation', 'local_studybuddy');
    }

    /**
     * Execute the queued generation.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $type = !empty($data->type) ? (string)$data->type : '';
        $id = !empty($data->id) ? (int)$data->id : 0;

        if ($id <= 0) {
            return;
        }

        $service = new \local_studybuddy\local\async_generation_service();
        if ($type === 'practice') {
            mtrace(get_string('task:startpractice', 'local_studybuddy', $id));
            $service->process_practice($id);
            mtrace(get_string('task:completepractice', 'local_studybuddy', $id));
            return;
        }

        if ($type === 'draft') {
            mtrace(get_string('task:startdraft', 'local_studybuddy', $id));
            $service->process_draft($id);
            mtrace(get_string('task:completedraft', 'local_studybuddy', $id));
        }
    }
}
