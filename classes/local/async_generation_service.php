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
 * Queues and processes AI study activity generation.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class async_generation_service {
    /**
     * Queue a temporary student practice generation.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @param string $query User prompt.
     * @param array $params Generation parameters.
     * @return int Practice id.
     */
    public function queue_practice(int $courseid, int $userid, string $query, array $params): int {
        global $DB;

        $this->require_provider_ready($courseid, $userid);
        $params = $this->normalise_params($params, $courseid, $query);
        $retentiondays = max(1, (int)(get_config('local_studybuddy', 'practiceretention') ?: 7));
        $now = time();

        $practiceid = (int)$DB->insert_record('local_studybuddy_practice', (object)[
            'courseid' => $courseid,
            'userid' => $userid,
            'querytext' => $query,
            'paramsjson' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'resultjson' => '{}',
            'status' => 'pending',
            'timecreated' => $now,
            'timemodified' => $now,
            'expiresat' => $now + ($retentiondays * DAYSECS),
        ]);

        try {
            $this->queue_task('practice', $practiceid);
        } catch (\Throwable $e) {
            $DB->update_record('local_studybuddy_practice', (object)[
                'id' => $practiceid,
                'status' => 'failed',
                'resultjson' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
                'timemodified' => time(),
            ]);
            throw $e;
        }
        return $practiceid;
    }

    /**
     * Queue a teacher draft generation.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @param string $title Draft title.
     * @param string $prompt Teacher prompt.
     * @param array $params Generation parameters.
     * @return int Draft id.
     */
    public function queue_draft(int $courseid, int $userid, string $title, string $prompt, array $params): int {
        global $DB;

        $this->require_provider_ready($courseid, $userid);
        $params = $this->normalise_params($params, $courseid, $prompt);
        $activitytype = (string)$params['activitytype'];
        $now = time();

        $draftid = (int)$DB->insert_record('local_studybuddy_drafts', (object)[
            'courseid' => $courseid,
            'userid' => $userid,
            'activitytype' => $activitytype,
            'title' => $title,
            'prompttext' => $prompt,
            'resultjson' => '{}',
            'status' => 'pending',
            'metadata' => json_encode(['params' => $params], JSON_UNESCAPED_UNICODE),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        try {
            $this->queue_task('draft', $draftid);
        } catch (\Throwable $e) {
            $DB->update_record('local_studybuddy_drafts', (object)[
                'id' => $draftid,
                'status' => 'failed',
                'metadata' => json_encode(['params' => $params, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
                'timemodified' => time(),
            ]);
            throw $e;
        }
        return $draftid;
    }

    /**
     * Process a queued practice generation.
     *
     * @param int $practiceid Practice id.
     * @return void
     */
    public function process_practice(int $practiceid): void {
        global $DB;

        $claimed = $DB->set_field_select(
            'local_studybuddy_practice',
            'status',
            'running',
            'id = :id AND status = :pending',
            ['id' => $practiceid, 'pending' => 'pending']
        );
        if (!$claimed) {
            return;
        }
        $practice = $DB->get_record('local_studybuddy_practice', ['id' => $practiceid], '*', MUST_EXIST);

        $params = json_decode((string)$practice->paramsjson, true);
        if (!is_array($params)) {
            $params = [];
        }
        $params = $this->normalise_params($params, (int)$practice->courseid, (string)$practice->querytext);

        try {
            $result = $this->generate_activity((int)$practice->courseid, $params, (int)$practice->userid);
            $DB->update_record('local_studybuddy_practice', (object)[
                'id' => $practice->id,
                'paramsjson' => json_encode($params, JSON_UNESCAPED_UNICODE),
                'resultjson' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'status' => 'ready',
                'timemodified' => time(),
            ]);
        } catch (\Throwable $e) {
            $this->mark_practice_failed($practice, $e);
            throw $e;
        }
    }

    /**
     * Process a queued teacher draft generation.
     *
     * @param int $draftid Draft id.
     * @return void
     */
    public function process_draft(int $draftid): void {
        global $DB;

        $claimed = $DB->set_field_select(
            'local_studybuddy_drafts',
            'status',
            'running',
            'id = :id AND status = :pending',
            ['id' => $draftid, 'pending' => 'pending']
        );
        if (!$claimed) {
            return;
        }
        $draft = $DB->get_record('local_studybuddy_drafts', ['id' => $draftid], '*', MUST_EXIST);

        $metadata = json_decode((string)$draft->metadata, true);
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $params = is_array($metadata) && isset($metadata['params']) && is_array($metadata['params']) ? $metadata['params'] : [];
        $params = $this->normalise_params($params, (int)$draft->courseid, (string)$draft->prompttext);

        try {
            $result = $this->generate_activity((int)$draft->courseid, $params, (int)$draft->userid);
            if ((string)$params['activitytype'] === 'h5p_flashcards') {
                $result['activitytype'] = 'h5p_flashcards';
            }
            $metadata['params'] = $params;

            $DB->update_record('local_studybuddy_drafts', (object)[
                'id' => $draft->id,
                'resultjson' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                'status' => 'draft',
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'timemodified' => time(),
            ]);
        } catch (\Throwable $e) {
            $this->mark_draft_failed($draft, $e, $params);
            throw $e;
        }
    }

    /**
     * Generate the requested activity with the configured provider.
     *
     * @param int $courseid Course id.
     * @param array $params Generation parameters.
     * @param int $userid User who requested the activity.
     * @return array Generated activity.
     */
    private function generate_activity(int $courseid, array &$params, int $userid): array {
        $this->require_provider_ready($courseid, $userid);
        $provider = (string)(get_config('local_studybuddy', 'provider') ?: 'openai');
        if ($provider === 'openai') {
            $storeservice = new openai_course_vector_store_service();
            $vectorstore = $storeservice->ensure_vector_store($courseid);
            $scope = (new provider_file_scope())->get_scope($courseid, $provider, $userid);
            if (empty($scope['files'])) {
                throw new \moodle_exception('chat:nosources', 'local_studybuddy');
            }
            $params['vectorstoreid'] = $vectorstore->externalid;
            $params['openai_file_filter'] = (new provider_file_scope())->openai_document_filter($scope['documentids']);
            $retrieved = [];
        } else if ($provider === 'google') {
            $storeservice = new google_course_file_search_store_service();
            $filestore = $storeservice->ensure_file_search_store($courseid);
            $scope = (new provider_file_scope())->get_scope($courseid, $provider, $userid);
            if (empty($scope['files'])) {
                throw new \moodle_exception('chat:nosources', 'local_studybuddy');
            }
            $params['googlestoreid'] = $filestore->externalid;
            $params['google_metadata_filter'] = (new provider_file_scope())->google_metadata_filter(
                $scope['documentids']
            );
            $retrieved = [];
        } else if ($provider === 'vertexai') {
            $ragservice = new vertexai_course_rag_service();
            $corpus = $ragservice->ensure_rag_corpus($courseid);
            $scope = (new provider_file_scope())->get_scope($courseid, $provider, $userid);
            if (empty($scope['files'])) {
                throw new \moodle_exception('chat:nosources', 'local_studybuddy');
            }
            $params['vertexragcorpus'] = $corpus->externalid;
            $params['vertexragfileids'] = $scope['vertexragfileids'];
            $retrieved = [];
        } else {
            throw new \moodle_exception('invalidprovider', 'local_studybuddy', '', $provider);
        }

        $generationparams = $params;
        if ((string)($generationparams['activitytype'] ?? '') === 'h5p_flashcards') {
            $generationparams['activitytype'] = 'flashcards';
        }
        return (new activity_generator())->generate_activity($retrieved, $generationparams);
    }

    /**
     * Prevent generation while the selected provider has no ready course store.
     *
     * @param int $courseid Course id.
     * @param int $userid Requesting user id.
     * @return void
     */
    private function require_provider_ready(int $courseid, int $userid): void {
        $status = (new source_service())->get_sync_status($courseid, $userid);
        $context = \context_course::instance($courseid);
        $technicalstatus = has_any_capability([
            'local/studybuddy:managesources',
            'local/studybuddy:generate',
            'local/studybuddy:review',
            'local/studybuddy:publish',
            'local/studybuddy:manage',
        ], $context, $userid);
        if (!empty($status['needsreindex'])) {
            throw new \moodle_exception(
                $technicalstatus ? 'providerreindexrequired' : 'syncstatus:temporarilydisabled',
                'local_studybuddy',
                '',
                (object)['provider' => (string)($status['providerlabel'] ?? $status['provider'] ?? '')]
            );
        }
        if (empty($status['available'])) {
            throw new \moodle_exception(
                $technicalstatus ? 'chat:nosources' : 'syncstatus:temporarilydisabled',
                'local_studybuddy'
            );
        }
    }

    /**
     * Queue the Moodle adhoc task.
     *
     * @param string $type Generation type.
     * @param int $id Record id.
     * @return void
     */
    private function queue_task(string $type, int $id): void {
        $task = new \local_studybuddy\task\process_pending_generation();
        $task->set_component('local_studybuddy');
        $task->set_custom_data((object)[
            'type' => $type,
            'id' => $id,
        ]);
        manager::queue_adhoc_task($task);
    }

    /**
     * Normalise generation parameters.
     *
     * @param array $params Raw params.
     * @param int $courseid Course id.
     * @param string $query Query.
     * @return array
     */
    private function normalise_params(array $params, int $courseid, string $query): array {
        $activitytype = (string)($params['activitytype'] ?? 'quiz');
        if (!in_array($activitytype, ['quiz', 'flashcards', 'conceptmap', 'h5p_flashcards'], true)) {
            $activitytype = 'quiz';
        }

        $normalised = [
            'query' => $query,
            'courseid' => $courseid,
            'activitytype' => $activitytype,
        ] + $params;

        if ($activitytype === 'quiz' || $activitytype === 'flashcards' || $activitytype === 'h5p_flashcards') {
            $normalised['nquestions'] = max(1, min(10, (int)($params['nquestions'] ?? 5)));
            $normalised['difficulty'] = clean_param((string)($params['difficulty'] ?? 'medium'), PARAM_ALPHA);
        } else {
            unset($normalised['nquestions']);
            unset($normalised['difficulty']);
        }

        return $normalised;
    }

    /**
     * Mark a practice record as failed.
     *
     * @param \stdClass $practice Practice record.
     * @param \Throwable $e Failure.
     * @return void
     */
    private function mark_practice_failed(\stdClass $practice, \Throwable $e): void {
        global $DB;

        $DB->update_record('local_studybuddy_practice', (object)[
            'id' => $practice->id,
            'resultjson' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
            'status' => 'failed',
            'timemodified' => time(),
        ]);
    }

    /**
     * Mark a draft record as failed.
     *
     * @param \stdClass $draft Draft record.
     * @param \Throwable $e Failure.
     * @param array $params Params.
     * @return void
     */
    private function mark_draft_failed(\stdClass $draft, \Throwable $e, array $params): void {
        global $DB;

        $DB->update_record('local_studybuddy_drafts', (object)[
            'id' => $draft->id,
            'resultjson' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
            'status' => 'failed',
            'metadata' => json_encode(['params' => $params, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
            'timemodified' => time(),
        ]);
    }
}
