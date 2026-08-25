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

/**
 * Maintains the Moodle source catalogue used to scope cloud provider files.
 *
 * The catalogue stores source metadata and enabled state only. Chunking,
 * embeddings and retrieval are performed by the selected cloud provider.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_source_catalogue {
    /** @var content_extractor Moodle content discovery service. */
    private content_extractor $extractor;

    /**
     * Constructor.
     *
     * @param content_extractor|null $extractor Content extractor.
     */
    public function __construct(?content_extractor $extractor = null) {
        $this->extractor = $extractor ?? new content_extractor();
    }

    /**
     * Discovers course sources and updates their local metadata records.
     *
     * The returned records contain a transient `text` property for native
     * Moodle content. It is used only to prepare a provider upload and is
     * never persisted as a local RAG index.
     *
     * @param int $courseid Course id.
     * @return \stdClass[] Ready source records with transient text.
     */
    public function synchronise(int $courseid): array {
        global $DB;

        $documents = $this->extractor->extract_course($courseid);
        $ready = [];
        $seenhashes = [];
        $seencontenthashes = [];

        foreach ($documents as $document) {
            $sourcehash = (string)$document['sourcehash'];
            $contentkey = !empty($document['contenthash'])
                ? 'content:' . $document['contenthash']
                : 'text:' . $document['texthash'];
            $seenhashes[$sourcehash] = true;

            $existing = $DB->get_record('local_studybuddy_documents', [
                'courseid' => $courseid,
                'sourcehash' => $sourcehash,
            ]);

            if (isset($seencontenthashes[$contentkey])) {
                if ($existing) {
                    $this->mark_stale($existing);
                }
                continue;
            }

            $record = $this->upsert($document, $existing);
            $record->text = (string)$document['text'];
            $ready[] = $record;
            $seencontenthashes[$contentkey] = true;
        }

        $this->mark_missing_sources_stale($courseid, array_keys($seenhashes));

        return $ready;
    }

    /**
     * Upserts source metadata while preserving the teacher's enabled state.
     *
     * @param array $document Extracted document.
     * @param \stdClass|null $existing Existing metadata record.
     * @return \stdClass Updated record.
     */
    private function upsert(array $document, ?\stdClass $existing): \stdClass {
        global $DB;

        $now = time();
        $record = (object)[
            'courseid' => (int)$document['courseid'],
            'contextid' => (int)$document['contextid'],
            'cmid' => (int)$document['cmid'],
            'sourcehash' => (string)$document['sourcehash'],
            'sourcetype' => (string)$document['sourcetype'],
            'sourceidentifier' => (string)$document['sourceidentifier'],
            'title' => (string)$document['title'],
            'contenthash' => $document['contenthash'] ?: null,
            'texthash' => (string)$document['texthash'],
            'status' => 'ready',
            'enabled' => $existing ? (int)$existing->enabled : 1,
            'metadata' => json_encode($document['metadata'], JSON_UNESCAPED_UNICODE),
            'timemodified' => $now,
            'timeindexed' => $now,
        ];

        if ($existing) {
            $record->id = (int)$existing->id;
            $record->timecreated = (int)$existing->timecreated;
            $DB->update_record('local_studybuddy_documents', $record);
            return $record;
        }

        $record->timecreated = $now;
        $record->id = $DB->insert_record('local_studybuddy_documents', $record);

        return $record;
    }

    /**
     * Marks a source as stale without touching provider chunks or embeddings.
     *
     * @param \stdClass $record Source record.
     * @return void
     */
    private function mark_stale(\stdClass $record): void {
        global $DB;

        $DB->update_record('local_studybuddy_documents', (object)[
            'id' => $record->id,
            'status' => 'stale',
            'timemodified' => time(),
        ]);
    }

    /**
     * Marks records no longer discovered in Moodle as stale.
     *
     * @param int $courseid Course id.
     * @param string[] $seenhashes Current source hashes.
     * @return void
     */
    private function mark_missing_sources_stale(int $courseid, array $seenhashes): void {
        global $DB;

        $seen = array_fill_keys($seenhashes, true);
        foreach ($DB->get_records('local_studybuddy_documents', ['courseid' => $courseid]) as $record) {
            if (!isset($seen[(string)$record->sourcehash])) {
                $this->mark_stale($record);
            }
        }
    }
}
