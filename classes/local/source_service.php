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

namespace local_studybuddy\local;

use core\task\manager;
use local_studybuddy\local\provider\google\google_course_file_search_store_service;
use local_studybuddy\local\provider\openai\openai_course_vector_store_service;
use local_studybuddy\local\provider\provider_file_scope;
use local_studybuddy\local\provider\vertexai\vertexai_course_rag_service;

/**
 * Course source management for StudyBuddy.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source_service {
    /** @var int Number of seconds after which a running sync job is considered stale. */
    private const STALE_JOB_THRESHOLD = 1800;

    /**
     * Queue a background course sync.
     *
     * @param int $courseid Course id.
     * @param string $triggertype Trigger type.
     * @return array Queue summary.
     */
    public function queue_sync(int $courseid, string $triggertype = 'manual'): array {
        global $DB;

        $this->recover_stale_jobs($courseid);

        $provider = $this->get_provider_name();
        $activejob = $DB->get_record_select(
            'local_studybuddy_syncjob',
            'courseid = :courseid AND provider = :provider AND status IN (:queued, :running)',
            [
                'courseid' => $courseid,
                'provider' => $provider,
                'queued' => 'queued',
                'running' => 'running',
            ],
            'id DESC'
        );
        if ($activejob) {
            return [
                'jobid' => (int)$activejob->id,
                'status' => (string)$activejob->status,
                'provider' => $provider,
            ];
        }

        $now = time();
        $jobid = (int)$DB->insert_record('local_studybuddy_syncjob', (object)[
            'courseid' => $courseid,
            'provider' => $provider,
            'trigger_type' => $triggertype,
            'status' => 'queued',
            'payload' => null,
            'started_at' => null,
            'finished_at' => null,
            'error_text' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $store = $DB->get_record('local_studybuddy_stores', [
            'courseid' => $courseid,
            'provider' => $provider,
        ]);
        if ($store) {
            $store->status = 'queued';
            $store->lasterror = null;
            $store->timemodified = $now;
            $DB->update_record('local_studybuddy_stores', $store);
        }

        try {
            $task = new \local_studybuddy\task\process_pending_sync();
            $task->set_custom_data([
                'courseid' => $courseid,
                'provider' => $provider,
            ]);
            manager::queue_adhoc_task($task);
        } catch (\Throwable $e) {
            $DB->update_record('local_studybuddy_syncjob', (object)[
                'id' => $jobid,
                'status' => 'failed',
                'finished_at' => time(),
                'error_text' => $e->getMessage(),
                'timemodified' => time(),
            ]);
            if ($store) {
                $store->status = 'failed';
                $store->lasterror = $e->getMessage();
                $store->timemodified = time();
                $DB->update_record('local_studybuddy_stores', $store);
            }
            throw $e;
        }

        return [
            'jobid' => $jobid,
            'status' => 'queued',
            'provider' => $provider,
        ];
    }

    /**
     * Indexes one course using the configured provider path.
     *
     * @param int $courseid Course id.
     * @return array Sync summary.
     */
    public function sync_course(int $courseid): array {
        $provider = $this->get_provider_name();

        if ($provider === 'openai') {
            return (new openai_course_vector_store_service())->sync_course($courseid);
        }

        if ($provider === 'google') {
            return (new google_course_file_search_store_service())->sync_course($courseid);
        }

        if ($provider === 'vertexai') {
            return (new vertexai_course_rag_service())->sync_course($courseid);
        }

        throw new \moodle_exception('invalidprovider', 'local_studybuddy', '', $provider);
    }

    /**
     * Lists indexed course sources.
     *
     * @param int $courseid Course id.
     * @return array Source rows.
     */
    public function list_sources(int $courseid): array {
        global $DB;

        $records = $DB->get_records('local_studybuddy_documents', ['courseid' => $courseid], 'title ASC');
        $sources = [];

        foreach ($records as $record) {
            $sources[] = [
                'id' => (int)$record->id,
                'title' => format_string($record->title),
                'sourcetype' => (string)$record->sourcetype,
                'status' => (string)$record->status,
                'enabled' => (int)$record->enabled,
                'cmid' => !empty($record->cmid) ? (int)$record->cmid : 0,
                'timeindexed' => !empty($record->timeindexed) ? (int)$record->timeindexed : 0,
            ];
        }

        return $sources;
    }

    /**
     * Toggles one indexed source.
     *
     * @param int $courseid Course id.
     * @param int $documentid Document id.
     * @param bool $enabled Enabled state.
     * @return array Updated source row.
     */
    public function toggle_source(int $courseid, int $documentid, bool $enabled): array {
        global $DB;

        $record = $DB->get_record('local_studybuddy_documents', [
            'id' => $documentid,
            'courseid' => $courseid,
        ], '*', MUST_EXIST);

        $record->enabled = $enabled ? 1 : 0;
        $record->timemodified = time();
        $DB->update_record('local_studybuddy_documents', $record);

        return [
            'id' => (int)$record->id,
            'title' => format_string($record->title),
            'sourcetype' => (string)$record->sourcetype,
            'status' => (string)$record->status,
            'enabled' => (int)$record->enabled,
            'cmid' => !empty($record->cmid) ? (int)$record->cmid : 0,
            'timeindexed' => !empty($record->timeindexed) ? (int)$record->timeindexed : 0,
        ];
    }

    /**
     * Returns compact indexing metrics.
     *
     * @param int $courseid Course id.
     * @param int $userid User id, or zero for an unscoped course view.
     * @return array Metrics.
     */
    public function get_sync_status(int $courseid, int $userid = 0): array {
        global $DB;

        $this->recover_stale_jobs($courseid);

        $provider = $this->get_provider_name();
        $latestjob = $this->get_latest_sync_job($courseid, $provider);
        $store = $DB->get_record('local_studybuddy_stores', [
            'courseid' => $courseid,
            'provider' => $provider,
        ]);
        $storeid = $store ? (int)$store->id : 0;
        $completedfiles = 0;
        $failedfiles = 0;
        $pendingfiles = 0;

        if ($storeid > 0) {
            $completedfiles = $DB->count_records_select(
                'local_studybuddy_store_files',
                'vectorstoreid = :storeid AND status IN (:ready, :completed)',
                ['storeid' => $storeid, 'ready' => 'ready', 'completed' => 'completed']
            );
            $failedfiles = $DB->count_records('local_studybuddy_store_files', [
                'vectorstoreid' => $storeid,
                'status' => 'failed',
            ]);
            $pendingfiles = $DB->count_records_select(
                'local_studybuddy_store_files',
                'vectorstoreid = :storeid AND status NOT IN (:ready, :completed, :failed)',
                ['storeid' => $storeid, 'ready' => 'ready', 'completed' => 'completed', 'failed' => 'failed']
            );
        }

        $status = 'idle';
        $jobid = 0;
        $lasterror = $store ? (string)($store->lasterror ?? '') : '';
        if ($latestjob) {
            $status = (string)$latestjob->status;
            $jobid = (int)$latestjob->id;
            if (!empty($latestjob->error_text)) {
                $lasterror = (string)$latestjob->error_text;
            }
        } else if ($store && !empty($store->status)) {
            $status = $this->normalise_store_status((string)$store->status);
        }

        if ($status === 'completed') {
            $status = $store && !empty($store->status) ? $this->normalise_store_status((string)$store->status) : 'ready';
        }

        // A provider task can finish after all local file mappings are ready,
        // while the store row still contains its previous transient status.
        // Reconcile that state so old jobs cannot keep the UI spinning.
        if (
            $store &&
            $pendingfiles === 0 &&
            $failedfiles === 0 &&
            in_array($status, ['syncing', 'in_progress'], true)
        ) {
            $store->status = 'completed';
            $store->laststatuscheck = time();
            $store->lasterror = null;
            $store->timemodified = time();
            $DB->update_record('local_studybuddy_stores', $store);
            $status = 'ready';
        }

        $access = new course_source_access();
        $visiblecmids = $access->get_visible_cmids($courseid, $userid);
        $documents = $DB->get_records('local_studybuddy_documents', ['courseid' => $courseid]);
        if ($visiblecmids !== null) {
            $documents = array_filter($documents, function (\stdClass $document) use ($access, $visiblecmids): bool {
                return $access->document_is_visible($document, $visiblecmids);
            });
        }

        $enabled = count(array_filter($documents, static function (\stdClass $document): bool {
            return (string)$document->status === 'ready' && (int)$document->enabled === 1;
        }));
        $stale = count(array_filter($documents, static function (\stdClass $document): bool {
            return (string)$document->status === 'stale';
        }));
        $enabledstale = count(array_filter($documents, static function (\stdClass $document): bool {
            return (string)$document->status === 'stale' && (int)$document->enabled === 1;
        }));
        $activefiles = 0;
        if ($storeid > 0) {
            $activefiles = count((new provider_file_scope())->get_scope($courseid, $provider, $userid)['files']);
        }
        $available = $activefiles;

        $syncing = in_array($status, ['queued', 'pending', 'running', 'syncing', 'in_progress'], true);
        $providerready = $store &&
            in_array((string)$store->status, ['ready', 'completed'], true) &&
            $activefiles > 0 &&
            $enabledstale === 0;
        $needsreindex = $enabled > 0 && !$syncing && !$providerready;

        return [
            'total' => count($documents),
            'ready' => count(array_filter($documents, static function (\stdClass $document): bool {
                return (string)$document->status === 'ready';
            })),
            'enabled' => $enabled,
            'available' => $available,
            'stale' => $stale,
            'status' => $status,
            'provider' => $provider,
            'providerlabel' => get_string('provider:' . $provider, 'local_studybuddy'),
            'jobid' => $jobid,
            'storestatus' => $store ? (string)$store->status : '',
            'completedfiles' => $completedfiles,
            'activefiles' => $activefiles,
            'failedfiles' => $failedfiles,
            'pendingfiles' => $pendingfiles,
            'lasterror' => $lasterror,
            'needsreindex' => $needsreindex,
        ];
    }

    /**
     * Get the selected provider name.
     *
     * @return string Provider name.
     */
    private function get_provider_name(): string {
        $provider = (string)get_config('local_studybuddy', 'provider');
        return $provider !== '' ? $provider : 'openai';
    }

    /**
     * Find latest sync job for this course/provider.
     *
     * @param int $courseid Course id.
     * @param string $provider Provider name.
     * @return \stdClass|null Job record.
     */
    private function get_latest_sync_job(int $courseid, string $provider): ?\stdClass {
        global $DB;

        $records = $DB->get_records('local_studybuddy_syncjob', [
            'courseid' => $courseid,
            'provider' => $provider,
        ], 'id DESC', '*', 0, 1);

        return $records ? reset($records) : null;
    }

    /**
     * Recover sync jobs that look stuck after a failed worker process.
     *
     * @param int $courseid Course id.
     * @return void
     */
    private function recover_stale_jobs(int $courseid): void {
        global $DB;

        $threshold = time() - self::STALE_JOB_THRESHOLD;
        $records = $DB->get_records_select(
            'local_studybuddy_syncjob',
            'courseid = :courseid AND status = :status AND started_at < :threshold',
            ['courseid' => $courseid, 'status' => 'running', 'threshold' => $threshold]
        );
        if (empty($records)) {
            return;
        }

        $now = time();
        foreach ($records as $record) {
            $record->status = 'failed';
            $record->finished_at = $now;
            $record->error_text = get_string('sync:stalejob', 'local_studybuddy');
            $record->timemodified = $now;
            $DB->update_record('local_studybuddy_syncjob', $record);
        }
    }

    /**
     * Convert provider store status to UI sync status.
     *
     * @param string $status Store status.
     * @return string Normalised status.
     */
    private function normalise_store_status(string $status): string {
        if (in_array($status, ['ready', 'completed'], true)) {
            return 'ready';
        }
        if (in_array($status, ['queued', 'pending', 'in_progress', 'syncing'], true)) {
            return 'syncing';
        }
        if ($status === 'failed') {
            return 'failed';
        }

        return 'idle';
    }
}
