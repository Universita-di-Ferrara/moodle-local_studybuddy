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

namespace local_studybuddy\local\provider\openai;

use local_studybuddy\local\course_source_catalogue;

/**
 * Synchronises Moodle course content with an OpenAI vector store.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class openai_course_vector_store_service {
    /** @var openai_files_api Files API. */
    private openai_files_api $filesapi;

    /** @var openai_vector_store_api Vector store API. */
    private openai_vector_store_api $vectorapi;

    /** @var bool Whether the last ensure call recreated a missing remote store. */
    private bool $lastensurecreatedreplacement = false;

    /**
     * Constructor.
     *
     * @param openai_client|null $client Client.
     */
    public function __construct(?openai_client $client = null) {
        $client = $client ?? new openai_client();
        $this->filesapi = new openai_files_api($client);
        $this->vectorapi = new openai_vector_store_api($client);
    }

    /**
     * Returns a course vector store id when one exists.
     *
     * @param int $courseid Course id.
     * @return string|null
     */
    public function get_vector_store_id(int $courseid): ?string {
        global $DB;

        $record = $DB->get_record('local_studybuddy_stores', [
            'courseid' => $courseid,
            'provider' => 'openai',
        ]);

        return $record ? (string)$record->externalid : null;
    }

    /**
     * Ensures that a vector store exists for a course.
     *
     * @param int $courseid Course id.
     * @return \stdClass Local vector store record.
     */
    public function ensure_vector_store(int $courseid): \stdClass {
        global $CFG, $DB;

        $this->lastensurecreatedreplacement = false;
        $record = $DB->get_record('local_studybuddy_stores', [
            'courseid' => $courseid,
            'provider' => 'openai',
        ]);

        if ($record) {
            if ($this->remote_vector_store_exists((string)$record->externalid)) {
                return $record;
            }

            $this->reset_missing_vector_store($record);
            $this->lastensurecreatedreplacement = true;
            $record = null;
        }

        if ($record) {
            return $record;
        }

        $course = get_course($courseid);
        $metadata = [
            'moodle_courseid' => (string)$courseid,
            'component' => 'local_studybuddy',
            'site' => substr((string)$CFG->wwwroot, 0, 512),
        ];
        $name = shorten_text('Moodle course ' . $courseid . ': ' . $course->shortname, 255);
        $created = $this->vectorapi->create($name, $metadata);
        $now = time();

        $record = (object)[
            'courseid' => $courseid,
            'provider' => 'openai',
            'externalid' => $created['id'],
            'name' => $name,
            'status' => $created['status'] ?? 'in_progress',
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
     * Whether the most recent ensure call recreated a missing remote store.
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

        $store = $DB->get_record('local_studybuddy_stores', [
            'courseid' => $courseid,
            'provider' => 'openai',
        ]);
        if (!$store) {
            return;
        }

        try {
            if (!empty($store->externalid)) {
                $this->vectorapi->delete((string)$store->externalid);
            }
        } catch (\Throwable $e) {
            unset($e);
        }

        $DB->delete_records('local_studybuddy_store_files', ['vectorstoreid' => $store->id]);
        $DB->delete_records('local_studybuddy_stores', ['id' => $store->id]);
    }

    /**
     * Checks whether the remote vector store still exists.
     *
     * @param string $vectorstoreid Vector store id.
     * @return bool
     */
    private function remote_vector_store_exists(string $vectorstoreid): bool {
        try {
            $this->vectorapi->retrieve($vectorstoreid);
            return true;
        } catch (\moodle_exception $e) {
            if ($this->is_missing_remote_resource_error($e)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Clears local mappings for a vector store that has disappeared remotely.
     *
     * @param \stdClass $vectorstore Local vector store record.
     * @return void
     */
    private function reset_missing_vector_store(\stdClass $vectorstore): void {
        global $DB;

        $DB->delete_records('local_studybuddy_store_files', ['vectorstoreid' => $vectorstore->id]);
        $DB->delete_records('local_studybuddy_stores', ['id' => $vectorstore->id]);
    }

    /**
     * Detects OpenAI "resource not found" errors without hiding other failures.
     *
     * @param \moodle_exception $exception API exception.
     * @return bool
     */
    private function is_missing_remote_resource_error(\moodle_exception $exception): bool {
        $message = strtolower($exception->getMessage());

        return str_contains($message, '404') ||
            str_contains($message, 'not found') ||
            str_contains($message, 'no vector store') ||
            str_contains($message, 'does not exist');
    }

    /**
     * Synchronises all indexed course documents to OpenAI.
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

        // Keep only source metadata locally. OpenAI owns parsing, chunking and retrieval.
        $documents = (new course_source_catalogue())->synchronise($courseid);
        $vectorstore = $this->ensure_vector_store($courseid);
        $summary['vector_store_id'] = $vectorstore->externalid;

        try {
            $documents = array_values(array_filter($documents, static function (\stdClass $document): bool {
                return (string)$document->status === 'ready';
            }));
            $summary['documents'] = count($documents);
            $seen = [];
            $seencontenthashes = [];

            foreach ($documents as $document) {
                $expectedcontenthash = $this->expected_upload_contenthash($document);
                $existing = $DB->get_record('local_studybuddy_store_files', [
                    'vectorstoreid' => $vectorstore->id,
                    'sourcehash' => $document->sourcehash,
                ]);

                if (isset($seencontenthashes[$expectedcontenthash])) {
                    if ($existing) {
                        $this->delete_remote_file_mapping($vectorstore, $existing);
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
                    $this->delete_remote_file_mapping($vectorstore, $existing);
                    $DB->delete_records('local_studybuddy_store_files', ['id' => $existing->id]);
                    $existing = null;
                }

                if (!$existing) {
                    if ($this->upload_document($vectorstore, $document)) {
                        $summary['uploaded']++;
                    }
                    continue;
                }

                $summary['reused']++;
            }

            $summary['deleted'] = $this->delete_stale_files($vectorstore, $seen);
            $this->refresh_file_statuses($vectorstore, $summary);
            $vectorstatus = $this->vectorapi->retrieve($vectorstore->externalid);
            $this->update_vector_store_status($vectorstore, $vectorstatus, null);
            $summary['vector_store_status'] = $vectorstatus['status'] ?? null;
        } catch (\Throwable $e) {
            $this->update_vector_store_status($vectorstore, null, $e->getMessage());
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
     * Removes OpenAI vector store files that belong to a deleted course module.
     *
     * @param int $cmid Course module id.
     * @return int Number of local vector file mappings removed.
     */
    public function delete_files_for_cmid(int $cmid): int {
        global $DB;

        if ($cmid <= 0) {
            return 0;
        }

        $deleted = 0;
        $records = $DB->get_records_sql(
            "SELECT vf.*, vs.externalid AS vectorstoreexternalid
               FROM {local_studybuddy_store_files} vf
               JOIN {local_studybuddy_stores} vs ON vs.id = vf.vectorstoreid
              WHERE vs.provider = :provider",
            ['provider' => 'openai']
        );

        foreach ($records as $record) {
            $attributes = json_decode((string)$record->attributes, true);
            $attributes = is_array($attributes) ? $attributes : [];
            if ((int)($attributes['cmid'] ?? 0) !== $cmid) {
                continue;
            }

            $vectorstore = (object)[
                'externalid' => $record->vectorstoreexternalid,
            ];
            $this->delete_remote_file_mapping($vectorstore, $record);
            $DB->delete_records('local_studybuddy_store_files', ['id' => $record->id]);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Uploads a single local document as a text file and attaches it to a vector store.
     *
     * @param \stdClass $vectorstore Local vector store record.
     * @param \stdClass $document Document record.
     * @return bool True when a file was uploaded.
     */
    private function upload_document(\stdClass $vectorstore, \stdClass $document): bool {
        global $DB;

        $tmpdir = make_temp_directory('local_studybuddy_openai');
        $upload = $this->prepare_uploadable_document($document, $tmpdir);
        if ($upload === null) {
            return false;
        }

        try {
            $file = $this->filesapi->upload($upload['path'], 'assistants', $upload['filename']);
            $attributes = $this->document_attributes($document);
            $vectorfile = $this->vectorapi->create_file($vectorstore->externalid, $file['id'], $attributes);
            $now = time();

            $record = (object)[
                'vectorstoreid' => $vectorstore->id,
                'courseid' => $document->courseid,
                'documentid' => $document->id,
                'sourcehash' => $document->sourcehash,
                'contenthash' => $upload['contenthash'],
                'externalfileid' => $file['id'],
                'vectorfileid' => $vectorfile['id'] ?? $file['id'],
                'filename' => $upload['filename'],
                'status' => $vectorfile['status'] ?? 'in_progress',
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
     * Returns the content hash that should be tracked for uploads.
     *
     * Stored Moodle files use the original file hash; native Moodle content
     * uses the extracted text hash.
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
     * Prepares the file uploaded to OpenAI for a document.
     *
     * Supported Moodle files are uploaded as their original binary. Native
     * Moodle content is uploaded as a temporary plain-text file.
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
                $filename = $document->id . '-source.pdf';
            }
            $tmpfile = $tmpdir . '/' . uniqid('source_', true) . '_' . $filename;
            $storedfile->copy_content_to($tmpfile);

            return [
                'path' => $tmpfile,
                'filename' => $filename,
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
            'contenthash' => (string)$document->texthash,
        ];
    }

    /**
     * Refreshes status for in-progress vector store files.
     *
     * @param \stdClass $vectorstore Local vector store record.
     * @param array $summary Summary passed by reference.
     * @return void
     */
    private function refresh_file_statuses(\stdClass $vectorstore, array &$summary): void {
        global $DB;

        $records = $DB->get_records('local_studybuddy_store_files', ['vectorstoreid' => $vectorstore->id]);
        foreach ($records as $record) {
            try {
                $remote = $this->vectorapi->retrieve_file($vectorstore->externalid, $record->externalfileid);
                $status = $remote['status'] ?? $record->status;

                $update = (object)[
                    'id' => $record->id,
                    'status' => $status,
                    'lasterror' => null,
                    'timemodified' => time(),
                ];
                $DB->update_record('local_studybuddy_store_files', $update);

                if ($status === 'completed') {
                    $summary['completed']++;
                } else if ($status === 'failed' || $status === 'cancelled') {
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
     * @param \stdClass $vectorstore Local vector store record.
     * @param array $seen Source hashes seen in this sync.
     * @return int Number deleted.
     */
    private function delete_stale_files(\stdClass $vectorstore, array $seen): int {
        global $DB;

        $deleted = 0;
        $records = $DB->get_records('local_studybuddy_store_files', ['vectorstoreid' => $vectorstore->id]);
        foreach ($records as $record) {
            if (isset($seen[$record->sourcehash])) {
                continue;
            }

            $this->delete_remote_file_mapping($vectorstore, $record);
            $DB->delete_records('local_studybuddy_store_files', ['id' => $record->id]);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Best-effort deletion of remote vector file and uploaded file.
     *
     * @param \stdClass $vectorstore Local vector store record.
     * @param \stdClass $record Vector file record.
     * @return void
     */
    private function delete_remote_file_mapping(\stdClass $vectorstore, \stdClass $record): void {
        try {
            $this->vectorapi->delete_file($vectorstore->externalid, $record->externalfileid);
        } catch (\Throwable $e) {
            unset($e);
        }

        try {
            $this->filesapi->delete($record->externalfileid);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Updates local vector store status.
     *
     * @param \stdClass $vectorstore Local vector store record.
     * @param array|null $remote Remote response.
     * @param string|null $error Error.
     * @return void
     */
    private function update_vector_store_status(\stdClass $vectorstore, ?array $remote, ?string $error): void {
        global $DB;

        $update = (object)[
            'id' => $vectorstore->id,
            'status' => $remote['status'] ?? ($error ? 'failed' : $vectorstore->status),
            'metadata' => isset($remote['metadata']) ?
                json_encode($remote['metadata'], JSON_UNESCAPED_UNICODE) :
                $vectorstore->metadata,
            'lastsynced' => $error ? $vectorstore->lastsynced : time(),
            'laststatuscheck' => time(),
            'lasterror' => $error,
            'timemodified' => time(),
        ];
        $DB->update_record('local_studybuddy_stores', $update);
    }

    /**
     * Builds OpenAI vector file attributes for filtering.
     *
     * @param \stdClass $document Document record.
     * @return array
     */
    private function document_attributes(\stdClass $document): array {
        $metadata = json_decode((string)$document->metadata, true);
        $metadata = is_array($metadata) ? $metadata : [];

        return [
            'moodle_courseid' => (int)$document->courseid,
            'documentid' => (int)$document->id,
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
