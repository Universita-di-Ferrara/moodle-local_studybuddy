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

namespace local_studybuddy\local\provider\vertexai;

use local_studybuddy\local\course_source_catalogue;

/**
 * Synchronises Moodle course content with Vertex AI RAG Engine.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vertexai_course_rag_service {
    /** @var vertexai_rag_api RAG API. */
    private vertexai_rag_api $ragapi;

    /** @var bool Whether the last ensure call recreated a missing remote corpus. */
    private bool $lastensurecreatedreplacement = false;

    /**
     * Constructor.
     *
     * @param vertexai_client|null $client Client.
     */
    public function __construct(?vertexai_client $client = null) {
        $client = $client ?? new vertexai_client();
        $this->ragapi = new vertexai_rag_api($client);
    }

    /**
     * Ensures that a Vertex AI RAG corpus exists for a course.
     *
     * @param int $courseid Course id.
     * @return \stdClass Local vector store record.
     */
    public function ensure_rag_corpus(int $courseid): \stdClass {
        global $CFG, $DB;

        $this->lastensurecreatedreplacement = false;
        $record = $DB->get_record('local_studybuddy_stores', [
            'courseid' => $courseid,
            'provider' => 'vertexai',
        ]);

        if ($record) {
            if ($this->is_operation_name((string)$record->externalid)) {
                return $this->refresh_pending_corpus($record);
            }

            if ($this->remote_corpus_exists((string)$record->externalid)) {
                return $record;
            }

            $this->reset_missing_corpus($record);
            $this->lastensurecreatedreplacement = true;
            $record = null;
        }

        $course = get_course($courseid);
        $metadata = [
            'moodle_courseid' => (string)$courseid,
            'component' => 'local_studybuddy',
            'site' => substr((string)$CFG->wwwroot, 0, 512),
        ];
        $name = shorten_text('Moodle course ' . $courseid . ': ' . $course->shortname, 255);
        $operation = $this->ragapi->create_corpus(
            $name,
            'RAG corpus for Moodle course ' . $courseid,
            (string)(get_config('local_studybuddy', 'vertexairagembeddingmodel') ?: 'publishers/google/models/text-embedding-004')
        );
        $created = $this->resolve_operation_response($operation, 3);
        $corpusname = (string)($created['name'] ?? '');
        $status = 'completed';
        if ($corpusname === '' && !empty($operation['name'])) {
            $corpusname = (string)$operation['name'];
            $status = 'in_progress';
        } else if ($corpusname === '') {
            throw new \moodle_exception('vertexai:corpusmissing', 'local_studybuddy');
        }

        $now = time();
        $record = (object)[
            'courseid' => $courseid,
            'provider' => 'vertexai',
            'externalid' => $corpusname,
            'name' => $name,
            'status' => $status,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'lastsynced' => null,
            'laststatuscheck' => $now,
            'lasterror' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_studybuddy_stores', $record);

        return $record;
    }

    /**
     * Whether the most recent ensure call recreated a missing remote corpus.
     *
     * @return bool
     */
    public function last_ensure_created_replacement(): bool {
        return $this->lastensurecreatedreplacement;
    }

    /**
     * Deletes the remote and local resources for one course.
     *
     * @param int $courseid Course id.
     * @return void
     */
    public function delete_course_resources(int $courseid): void {
        global $DB;

        $corpus = $DB->get_record('local_studybuddy_stores', [
            'courseid' => $courseid,
            'provider' => 'vertexai',
        ]);
        if (!$corpus) {
            return;
        }

        try {
            if (!empty($corpus->externalid) && !$this->is_operation_name((string)$corpus->externalid)) {
                $this->ragapi->delete_corpus((string)$corpus->externalid);
            }
        } catch (\Throwable $e) {
            unset($e);
        }

        $DB->delete_records('local_studybuddy_store_files', ['vectorstoreid' => $corpus->id]);
        $DB->delete_records('local_studybuddy_stores', ['id' => $corpus->id]);
    }

    /**
     * Synchronises all current course documents to Vertex AI RAG Engine.
     *
     * @param int $courseid Course id.
     * @return array Sync summary.
     */
    public function sync_course(int $courseid): array {
        global $DB;

        $summary = [
            'documents' => 0,
            'uploaded' => 0,
            'reused' => 0,
            'deleted' => 0,
            'completed' => 0,
            'failed' => 0,
            'vector_store_id' => null,
            'vector_store_status' => null,
        ];

        // Keep only source metadata locally. Vertex AI owns parsing, chunking,
        // embeddings and retrieval.
        $documents = (new course_source_catalogue())->synchronise($courseid);
        $documents = array_values(array_filter($documents, static function (\stdClass $document): bool {
            return (string)$document->status === 'ready';
        }));
        $summary['documents'] = count($documents);

        $corpus = $this->ensure_rag_corpus($courseid);
        $summary['vector_store_id'] = $corpus->externalid;
        $summary['vector_store_status'] = $corpus->status;

        if ($corpus->status === 'in_progress' || $this->is_operation_name((string)$corpus->externalid)) {
            return $summary;
        }

        try {
            $seen = [];
            $seencontenthashes = [];
            $existingbyhash = [];
            foreach ($DB->get_records('local_studybuddy_store_files', ['vectorstoreid' => $corpus->id]) as $record) {
                $existingbyhash[(string)$record->sourcehash] = $record;
            }

            foreach ($documents as $document) {
                $expectedcontenthash = $this->expected_upload_contenthash($document);
                $existing = $existingbyhash[(string)$document->sourcehash] ?? null;

                if (isset($seencontenthashes[$expectedcontenthash])) {
                    if ($existing) {
                        $this->delete_remote_file_mapping($existing);
                        $DB->delete_records('local_studybuddy_store_files', ['id' => $existing->id]);
                        $summary['deleted']++;
                    }
                    $summary['reused']++;
                    continue;
                }

                $seen[$document->sourcehash] = true;
                $seencontenthashes[$expectedcontenthash] = $document->sourcehash;

                if ($existing && $existing->contenthash === $expectedcontenthash && $existing->status === 'completed') {
                    $summary['reused']++;
                    continue;
                }

                if ($existing && $existing->contenthash !== $expectedcontenthash) {
                    $this->delete_remote_file_mapping($existing);
                    $DB->delete_records('local_studybuddy_store_files', ['id' => $existing->id]);
                    $existing = null;
                }

                if (!$existing) {
                    if ($this->upload_document($corpus, $document)) {
                        $summary['uploaded']++;
                    }
                    continue;
                }

                $summary['reused']++;
            }

            $summary['deleted'] += $this->delete_stale_files($corpus, $seen);
            $this->refresh_file_statuses($corpus, $summary);
            $this->update_corpus_status($corpus, 'completed', null);
            $summary['vector_store_status'] = 'completed';
        } catch (\Throwable $e) {
            $this->update_corpus_status($corpus, 'failed', $e->getMessage());
            throw $e;
        }

        return $summary;
    }

    /**
     * Synchronises multiple courses.
     *
     * @param int $limit Maximum courses.
     * @return array Aggregate summary.
     */
    public function sync_all_courses(int $limit = 20): array {
        global $DB;

        $courses = $DB->get_records_select('course', 'id <> ?', [SITEID], 'id', 'id', 0, $limit);
        $summary = [
            'courses' => 0,
            'documents' => 0,
            'uploaded' => 0,
            'reused' => 0,
            'deleted' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        foreach ($courses as $course) {
            $coursesummary = $this->sync_course((int)$course->id);
            $summary['courses']++;
            foreach (['documents', 'uploaded', 'reused', 'deleted', 'completed', 'failed'] as $key) {
                $summary[$key] += $coursesummary[$key] ?? 0;
            }
        }

        return $summary;
    }

    /**
     * Removes Vertex AI RAG files for a deleted course module.
     *
     * @param int $cmid Course module id.
     * @return int Number of local mappings removed.
     */
    public function delete_files_for_cmid(int $cmid): int {
        global $DB;

        if ($cmid <= 0) {
            return 0;
        }

        $deleted = 0;
        $records = $DB->get_records_sql(
            "SELECT vf.*
               FROM {local_studybuddy_store_files} vf
               JOIN {local_studybuddy_stores} vs ON vs.id = vf.vectorstoreid
              WHERE vs.provider = :provider",
            ['provider' => 'vertexai']
        );

        foreach ($records as $record) {
            $attributes = json_decode((string)$record->attributes, true);
            $attributes = is_array($attributes) ? $attributes : [];
            if ((int)($attributes['cmid'] ?? 0) !== $cmid) {
                continue;
            }

            $this->delete_remote_file_mapping($record);
            $DB->delete_records('local_studybuddy_store_files', ['id' => $record->id]);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Checks whether a remote corpus still exists.
     *
     * @param string $corpusname Corpus resource name.
     * @return bool
     */
    private function remote_corpus_exists(string $corpusname): bool {
        if ($this->is_operation_name($corpusname)) {
            return true;
        }

        try {
            $this->ragapi->get_corpus($corpusname);
            return true;
        } catch (\moodle_exception $e) {
            if ($this->is_missing_remote_resource_error($e)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Clears local mappings for a corpus that has disappeared remotely.
     *
     * @param \stdClass $corpus Local corpus record.
     * @return void
     */
    private function reset_missing_corpus(\stdClass $corpus): void {
        global $DB;

        $DB->delete_records('local_studybuddy_store_files', ['vectorstoreid' => $corpus->id]);
        $DB->delete_records('local_studybuddy_stores', ['id' => $corpus->id]);
    }

    /**
     * Detects Vertex AI "resource not found" errors without hiding other failures.
     *
     * @param \moodle_exception $exception API exception.
     * @return bool
     */
    private function is_missing_remote_resource_error(\moodle_exception $exception): bool {
        $message = strtolower($exception->getMessage());

        return str_contains($message, '404') ||
            str_contains($message, 'not found') ||
            str_contains($message, 'not_found') ||
            str_contains($message, 'does not exist');
    }

    /**
     * Uploads a single Moodle document to Vertex AI RAG Engine.
     *
     * @param \stdClass $corpus Local corpus record.
     * @param \stdClass $document Document record.
     * @return bool True when uploaded.
     */
    private function upload_document(\stdClass $corpus, \stdClass $document): bool {
        global $DB;

        $tmpdir = make_temp_directory('local_studybuddy_vertexai');
        $upload = $this->prepare_uploadable_document($document, $tmpdir);
        if ($upload === null) {
            return false;
        }

        try {
            $operation = $this->ragapi->upload_file(
                $corpus->externalid,
                $upload['path'],
                $upload['filename'],
                $upload['mimetype']
            );

            $attributes = $this->document_attributes($document);
            $now = time();

            // The upload API returns a direct UploadRagFileResponse.
            $ragfile = $operation['ragFile'] ?? [];
            $vectorfileid = $ragfile['name'] ?? '';
            $externalfileid = '';
            $status = 'completed';

            // Just in case they switch to long-running operations in the future.
            if (isset($operation['name']) && str_contains((string)$operation['name'], '/operations/')) {
                $status = !empty($operation['done']) ? 'completed' : 'in_progress';
                $externalfileid = $operation['name'];
                $response = $operation['response'] ?? [];
                $vectorfileid = $response['name'] ?? $vectorfileid;
            }

            $record = (object)[
                'vectorstoreid' => $corpus->id,
                'courseid' => $document->courseid,
                'documentid' => $document->id,
                'sourcehash' => $document->sourcehash,
                'contenthash' => $upload['contenthash'],
                'externalfileid' => $externalfileid,
                'vectorfileid' => $vectorfileid,
                'filename' => $upload['filename'],
                'status' => $status,
                'attributes' => json_encode($attributes, JSON_UNESCAPED_UNICODE),
                'lasterror' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('local_studybuddy_store_files', $record);
        } finally {
            @unlink($upload['path']);
        }

        return true;
    }

    /**
     * Refreshes status for in-progress upload operations.
     *
     * @param \stdClass $corpus Local corpus record.
     * @param array $summary Summary passed by reference.
     * @return void
     */
    private function refresh_file_statuses(\stdClass $corpus, array &$summary): void {
        global $DB;

        $records = $DB->get_records('local_studybuddy_store_files', ['vectorstoreid' => $corpus->id]);
        foreach ($records as $record) {
            if ($record->status === 'completed') {
                $summary['completed']++;
                continue;
            }

            if (empty($record->externalfileid)) {
                // Clean up orphaned records from previous upload parsing bug.
                $DB->delete_records('local_studybuddy_store_files', ['id' => $record->id]);
                continue;
            }

            try {
                $operation = $this->ragapi->get_operation($record->externalfileid);
                $status = empty($operation['done']) ? 'in_progress' : 'completed';
                $lasterror = null;
                if (!empty($operation['error'])) {
                    $status = 'failed';
                    $lasterror = $operation['error']['message'] ?? json_encode($operation['error']);
                }

                $update = (object)[
                    'id' => $record->id,
                    'status' => $status,
                    'vectorfileid' => $operation['response']['name'] ?? $record->vectorfileid,
                    'lasterror' => $lasterror,
                    'timemodified' => time(),
                ];
                $DB->update_record('local_studybuddy_store_files', $update);

                if ($status === 'completed') {
                    $summary['completed']++;
                } else if ($status === 'failed') {
                    $summary['failed']++;
                }
            } catch (\Throwable $e) {
                $DB->update_record('local_studybuddy_store_files', (object)[
                    'id' => $record->id,
                    'status' => 'failed',
                    'lasterror' => $e->getMessage(),
                    'timemodified' => time(),
                ]);
                $summary['failed']++;
            }
        }
    }

    /**
     * Deletes mappings no longer backed by current course documents.
     *
     * @param \stdClass $corpus Local corpus record.
     * @param array $seen Source hashes seen in this sync.
     * @return int Number deleted.
     */
    private function delete_stale_files(\stdClass $corpus, array $seen): int {
        global $DB;

        $deleted = 0;
        $records = $DB->get_records('local_studybuddy_store_files', ['vectorstoreid' => $corpus->id]);
        foreach ($records as $record) {
            if (isset($seen[$record->sourcehash])) {
                continue;
            }

            $this->delete_remote_file_mapping($record);
            $DB->delete_records('local_studybuddy_store_files', ['id' => $record->id]);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Best-effort deletion of a remote RAG file.
     *
     * @param \stdClass $record Vector file record.
     * @return void
     */
    private function delete_remote_file_mapping(\stdClass $record): void {
        if (empty($record->vectorfileid)) {
            return;
        }

        try {
            $this->ragapi->delete_file((string)$record->vectorfileid);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Updates local corpus status.
     *
     * @param \stdClass $corpus Local corpus record.
     * @param string $status Status.
     * @param string|null $error Error.
     * @return void
     */
    private function update_corpus_status(\stdClass $corpus, string $status, ?string $error): void {
        global $DB;

        $DB->update_record('local_studybuddy_stores', (object)[
            'id' => $corpus->id,
            'status' => $status,
            'lastsynced' => $error ? $corpus->lastsynced : time(),
            'laststatuscheck' => time(),
            'lasterror' => $error,
            'timemodified' => time(),
        ]);
    }

    /**
     * Refreshes a locally stored corpus creation operation.
     *
     * @param \stdClass $record Local corpus record.
     * @return \stdClass Updated or pending local corpus record.
     */
    private function refresh_pending_corpus(\stdClass $record): \stdClass {
        global $DB;

        $created = $this->resolve_operation_response(['name' => $record->externalid], 1);
        if (empty($created['name'])) {
            $record->status = 'in_progress';
            $DB->update_record('local_studybuddy_stores', (object)[
                'id' => $record->id,
                'status' => 'in_progress',
                'laststatuscheck' => time(),
                'timemodified' => time(),
            ]);

            return $record;
        }

        $record->externalid = (string)$created['name'];
        $record->status = 'completed';
        $DB->update_record('local_studybuddy_stores', (object)[
            'id' => $record->id,
            'externalid' => $record->externalid,
            'status' => 'completed',
            'laststatuscheck' => time(),
            'timemodified' => time(),
        ]);

        return $record;
    }

    /**
     * Resolves a long-running operation into its response.
     *
     * @param array $operation Operation or direct response.
     * @param int $attempts Number of polling attempts.
     * @return array Operation response.
     */
    private function resolve_operation_response(array $operation, int $attempts = 10): array {
        if (empty($operation['name']) || isset($operation['displayName'])) {
            return $operation;
        }

        $current = $operation;
        for ($i = 0; $i <= $attempts; $i++) {
            if (!array_key_exists('done', $current)) {
                $current = $this->ragapi->get_operation((string)$operation['name']);
            }

            if (!empty($current['done'])) {
                if (!empty($current['error'])) {
                    $message = $current['error']['message'] ?? json_encode($current['error']);
                    throw new \moodle_exception('vertexaiapierror', 'local_studybuddy', '', $message);
                }

                return $current['response'] ?? [];
            }

            if ($i < $attempts) {
                sleep(1);
                $current = []; // Force fetch on next iteration.
            }
        }

        return [];
    }

    /**
     * Checks whether a resource name points to a long-running operation.
     *
     * @param string $name Resource name.
     * @return bool
     */
    private function is_operation_name(string $name): bool {
        return str_contains($name, '/operations/');
    }

    /**
     * Returns the content hash that should be tracked for uploads.
     *
     * @param \stdClass $document Document record.
     * @return string
     */
    private function expected_upload_contenthash(\stdClass $document): string {
        $storedfile = $this->resolve_stored_file($document);
        if ($storedfile) {
            return (string)$storedfile->get_contenthash();
        }

        return (string)$document->texthash;
    }

    /**
     * Prepares the uploadable file for a document.
     *
     * @param \stdClass $document Document record.
     * @param string $tmpdir Temporary directory path.
     * @return array|null
     */
    private function prepare_uploadable_document(\stdClass $document, string $tmpdir): ?array {
        $storedfile = $this->resolve_stored_file($document);
        if ($storedfile) {
            $filename = clean_filename($storedfile->get_filename());
            if ($filename === '') {
                $filename = $document->id . '-source.bin';
            }
            $tmpfile = $tmpdir . '/' . uniqid('vertex_', true) . '_' . $filename;
            $storedfile->copy_content_to($tmpfile);

            return [
                'path' => $tmpfile,
                'filename' => $filename,
                'mimetype' => $storedfile->get_mimetype() ?: 'application/octet-stream',
                'contenthash' => (string)$storedfile->get_contenthash(),
            ];
        }

        $text = (string)($document->text ?? '');
        if (trim($text) === '') {
            return null;
        }

        $filename = $this->safe_filename($document->title, $document->id);
        $tmpfile = $tmpdir . '/' . $filename;
        file_put_contents($tmpfile, $text);

        return [
            'path' => $tmpfile,
            'filename' => $filename,
            'mimetype' => 'text/plain',
            'contenthash' => (string)$document->texthash,
        ];
    }

    /**
     * Builds metadata for local tracking.
     *
     * @param \stdClass $document Document record.
     * @return array
     */
    private function document_attributes(\stdClass $document): array {
        $metadata = json_decode((string)$document->metadata, true);
        $metadata = is_array($metadata) ? $metadata : [];

        return [
            'moodle_courseid' => (int)$document->courseid,
            'contextid' => (int)$document->contextid,
            'cmid' => (int)($document->cmid ?? 0),
            'component' => (string)($metadata['component'] ?? $metadata['module'] ?? $document->sourcetype),
            'filename' => shorten_text((string)($metadata['filename'] ?? $document->title), 512),
            'timemodified' => (int)$document->timemodified,
            'sourcehash' => $document->sourcehash,
        ];
    }

    /**
     * Resolves the original Moodle stored_file for file-based documents.
     *
     * @param \stdClass $document Document record.
     * @return \stored_file|null
     */
    private function resolve_stored_file(\stdClass $document): ?\stored_file {
        if ($document->sourcetype !== 'file') {
            return null;
        }

        $metadata = json_decode((string)$document->metadata, true);
        $metadata = is_array($metadata) ? $metadata : [];
        $fs = get_file_storage();

        if (!empty($metadata['storedfileid'])) {
            $file = $fs->get_file_by_id((int)$metadata['storedfileid']);
            if ($file && !$file->is_directory()) {
                return $file;
            }
        }

        if (
            !empty($metadata['component']) && isset($metadata['itemid']) &&
                array_key_exists('filepath', $metadata) && !empty($metadata['filename'])
        ) {
            $file = $fs->get_file(
                (int)$document->contextid,
                (string)$metadata['component'],
                (string)$metadata['filearea'],
                (int)$metadata['itemid'],
                (string)$metadata['filepath'],
                (string)$metadata['filename']
            );
            if ($file && !$file->is_directory()) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Creates a safe filename for uploaded generated text.
     *
     * @param string $title Document title.
     * @param int $documentid Document id.
     * @return string
     */
    private function safe_filename(string $title, int $documentid): string {
        $filename = clean_filename(shorten_text($title, 120));
        if ($filename === '') {
            $filename = 'document-' . $documentid;
        }

        return $documentid . '-' . $filename . '.txt';
    }
}
