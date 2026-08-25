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

namespace local_studybuddy;

/**
 * Event observer for course module content changes.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Marks source metadata for a module as stale after content changes.
     *
     * @param \core\event\base $event Event.
     * @return void
     */
    public static function course_module_changed(\core\event\base $event): void {
        self::mark_cmid_stale((int)$event->contextinstanceid);
    }

    /**
     * Marks source metadata stale and queues cleanup after module deletion.
     *
     * @param \core\event\base $event Event.
     * @return void
     */
    public static function course_module_deleted(\core\event\base $event): void {
        $cmid = (int)$event->contextinstanceid;
        self::mark_cmid_stale($cmid);
        (new \local_studybuddy\local\moodle_publisher())->mark_deleted_activity_reusable($cmid);
        self::queue_remote_cleanup((int)$event->courseid, 'module', $cmid);
    }

    /**
     * Queues remote provider cleanup after a course deletion.
     *
     * @param \core\event\course_deleted $event Event.
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        self::queue_remote_cleanup((int)$event->objectid, 'course');
    }

    /**
     * Queues provider cleanup after a course or module deletion.
     *
     * Event observers must not wait for remote provider HTTP calls. Moodle runs
     * cleanup in an adhoc task after the originating request has completed.
     *
     * @param int $courseid Course id.
     * @param string $scope Cleanup scope, either course or module.
     * @param int $cmid Course module id for module cleanup.
     * @return void
     */
    private static function queue_remote_cleanup(int $courseid, string $scope, int $cmid = 0): void {
        global $DB;

        if ($courseid <= 0 || ($scope === 'module' && $cmid <= 0)) {
            return;
        }

        $providers = $DB->get_fieldset_select(
            'local_studybuddy_stores',
            'provider',
            'courseid = :courseid',
            ['courseid' => $courseid]
        );
        foreach (array_unique($providers) as $provider) {
            if (!in_array($provider, ['openai', 'google', 'vertexai'], true)) {
                continue;
            }

            $task = new \local_studybuddy\task\cleanup_remote_resources();
            $task->set_custom_data([
                'courseid' => $courseid,
                'provider' => $provider,
                'scope' => $scope,
                'cmid' => $cmid,
            ]);
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    /**
     * Marks module source metadata stale. Remote files are removed by the
     * asynchronous provider cleanup task.
     *
     * @param int $cmid Course module id.
     * @return void
     */
    private static function mark_cmid_stale(int $cmid): void {
        global $DB;

        if ($cmid <= 0) {
            return;
        }

        $documents = $DB->get_records('local_studybuddy_documents', ['cmid' => $cmid]);
        foreach ($documents as $document) {
            $DB->set_field('local_studybuddy_documents', 'status', 'stale', ['id' => $document->id]);
        }
    }
}
