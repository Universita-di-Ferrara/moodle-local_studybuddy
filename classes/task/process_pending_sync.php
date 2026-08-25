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
 * Adhoc task that synchronises a course corpus with the selected provider.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_pending_sync extends \core\task\adhoc_task {
    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:process_pending_sync', 'local_studybuddy');
    }

    /**
     * Execute the queued corpus sync.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (empty($data->courseid)) {
            return;
        }

        $courseid = (int)$data->courseid;
        $provider = !empty($data->provider) ? (string)$data->provider : 'openai';
        $jobids = $this->claim_queued_jobs($courseid, $provider);
        if (empty($jobids)) {
            return;
        }

        mtrace(get_string('task:startsync', 'local_studybuddy', (object)[
            'courseid' => $courseid,
            'provider' => $provider,
        ]));

        try {
            $summary = (new \local_studybuddy\local\source_service())->sync_course($courseid);
            if ($this->provider_sync_is_pending($summary)) {
                $this->reschedule_sync($courseid, $provider, $jobids);
                mtrace(get_string('task:syncpending', 'local_studybuddy'));
                return;
            }

            $this->mark_jobs_complete($jobids);
            mtrace(get_string('task:synccomplete', 'local_studybuddy'));
        } catch (\Throwable $e) {
            $this->mark_jobs_failed($jobids, $e->getMessage());
            mtrace(get_string('task:syncfailed', 'local_studybuddy', $e->getMessage()));
            throw $e;
        }
    }

    /**
     * Check whether the selected provider is still processing the corpus.
     *
     * Remote file ingestion may outlive the PHP task which started it. Keep
     * the job open and refresh the provider state until it reaches a terminal
     * status, so the UI does not remain in a permanent syncing state.
     *
     * @param array $summary Provider sync summary.
     * @return bool
     */
    private function provider_sync_is_pending(array $summary): bool {
        $status = (string)($summary['vector_store_status'] ?? '');

        return in_array($status, ['queued', 'pending', 'in_progress', 'syncing'], true);
    }

    /**
     * Requeue this sync after a short provider processing window.
     *
     * @param int $courseid Course id.
     * @param string $provider Provider name.
     * @param int[] $jobids Job ids.
     * @return void
     */
    private function reschedule_sync(int $courseid, string $provider, array $jobids): void {
        global $DB;

        $now = time();
        foreach ($jobids as $jobid) {
            $DB->update_record('local_studybuddy_syncjob', (object)[
                'id' => $jobid,
                'status' => 'queued',
                'started_at' => null,
                'finished_at' => null,
                'timemodified' => $now,
            ]);
        }

        $task = new self();
        $task->set_custom_data([
            'courseid' => $courseid,
            'provider' => $provider,
        ]);
        $task->set_next_run_time($now + 30);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Claim queued jobs for this course/provider.
     *
     * @param int $courseid Course id.
     * @param string $provider Provider name.
     * @return int[] Job ids.
     */
    private function claim_queued_jobs(int $courseid, string $provider): array {
        global $DB;

        $records = $DB->get_records('local_studybuddy_syncjob', [
            'courseid' => $courseid,
            'provider' => $provider,
            'status' => 'queued',
        ], 'id ASC', 'id');
        $now = time();
        $jobids = [];
        foreach (array_keys($records) as $jobid) {
            $claimed = $DB->set_field_select(
                'local_studybuddy_syncjob',
                'status',
                'running',
                'id = :id AND status = :queued',
                ['id' => (int)$jobid, 'queued' => 'queued']
            );
            if ($claimed) {
                $DB->update_record('local_studybuddy_syncjob', (object)[
                    'id' => (int)$jobid,
                    'started_at' => $now,
                    'finished_at' => null,
                    'error_text' => null,
                    'timemodified' => $now,
                ]);
                $jobids[] = (int)$jobid;
            }
        }

        return $jobids;
    }

    /**
     * Mark jobs complete.
     *
     * @param int[] $jobids Job ids.
     * @return void
     */
    private function mark_jobs_complete(array $jobids): void {
        global $DB;

        $now = time();
        foreach ($jobids as $jobid) {
            $DB->update_record('local_studybuddy_syncjob', (object)[
                'id' => $jobid,
                'status' => 'completed',
                'finished_at' => $now,
                'error_text' => null,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Mark jobs failed.
     *
     * @param int[] $jobids Job ids.
     * @param string $message Error message.
     * @return void
     */
    private function mark_jobs_failed(array $jobids, string $message): void {
        global $DB;

        $now = time();
        foreach ($jobids as $jobid) {
            $DB->update_record('local_studybuddy_syncjob', (object)[
                'id' => $jobid,
                'status' => 'failed',
                'finished_at' => $now,
                'error_text' => $message,
                'timemodified' => $now,
            ]);
        }
    }
}
