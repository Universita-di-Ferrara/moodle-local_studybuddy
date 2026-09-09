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
 * Extracts plain text from supported Moodle course sources.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_extractor {
    /**
     * Extracts supported content documents for a course.
     *
     * @param int $courseid Course id.
     * @return array[] Extracted documents.
     */
    public function extract_course(int $courseid): array {
        $documents = [];
        $modinfo = get_fast_modinfo($courseid);
        $cms = [];
        $instanceids = [
            'page' => [],
            'book' => [],
            'label' => [],
            'lesson' => [],
        ];

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->visible) {
                continue;
            }

            $cms[] = $cm;
            if (array_key_exists($cm->modname, $instanceids)) {
                $instanceids[$cm->modname][] = (int)$cm->instance;
            }
        }

        $moduledata = $this->preload_module_data($instanceids);

        foreach ($cms as $cm) {
            $context = \context_module::instance($cm->id);
            foreach ($this->extract_module_record($courseid, $cm, $context, $moduledata) as $document) {
                $documents[] = $document;
            }
            foreach ($this->extract_module_files($courseid, $cm, $context) as $document) {
                $documents[] = $document;
            }
        }

        return array_values(array_filter($documents, function (array $document): bool {
            return $this->should_keep_document($document);
        }));
    }

    /**
     * Extracts text stored directly in Moodle activity tables.
     *
     * @param int $courseid Course id.
     * @param \cm_info $cm Course module info.
     * @param \context_module $context Module context.
     * @param array $moduledata Preloaded module records.
     * @return array[]
     */
    private function extract_module_record(
        int $courseid,
        \cm_info $cm,
        \context_module $context,
        array $moduledata
    ): array {
        $documents = [];

        switch ($cm->modname) {
            case 'page':
                $page = $moduledata['page'][$cm->instance] ?? null;
                if ($page) {
                    $documents[] = $this->build_document(
                        $courseid,
                        $context->id,
                        $cm->id,
                        'mod_page',
                        'page:' . $page->id . ':content',
                        $page->name,
                        content_to_text((string)$page->content, (int)$page->contentformat),
                        null,
                        ['module' => 'page']
                    );
                }
                break;

            case 'book':
                $book = $moduledata['book'][$cm->instance] ?? null;
                $chapters = $moduledata['book_chapters'][$cm->instance] ?? [];
                foreach ($chapters as $chapter) {
                    $documents[] = $this->build_document(
                        $courseid,
                        $context->id,
                        $cm->id,
                        'mod_book',
                        'book:' . $cm->instance . ':chapter:' . $chapter->id,
                        ($book ? $book->name . ': ' : '') . $chapter->title,
                        content_to_text((string)$chapter->content, (int)$chapter->contentformat),
                        null,
                        ['module' => 'book', 'chapterid' => $chapter->id]
                    );
                }
                break;

            case 'label':
                $label = $moduledata['label'][$cm->instance] ?? null;
                if ($label) {
                    $documents[] = $this->build_document(
                        $courseid,
                        $context->id,
                        $cm->id,
                        'mod_label',
                        'label:' . $label->id . ':intro',
                        $cm->name,
                        content_to_text((string)$label->intro, (int)$label->introformat),
                        null,
                        ['module' => 'label']
                    );
                }
                break;

            case 'lesson':
                $lesson = $moduledata['lesson'][$cm->instance] ?? null;
                $pages = $moduledata['lesson_pages'][$cm->instance] ?? [];
                foreach ($pages as $page) {
                    $documents[] = $this->build_document(
                        $courseid,
                        $context->id,
                        $cm->id,
                        'mod_lesson',
                        'lesson:' . $cm->instance . ':page:' . $page->id,
                        ($lesson ? $lesson->name . ': ' : '') . $page->title,
                        content_to_text((string)$page->contents, (int)$page->contentsformat),
                        null,
                        ['module' => 'lesson', 'pageid' => $page->id]
                    );
                }
                break;
        }

        return $documents;
    }

    /**
     * Loads module records needed for the course in bulk.
     *
     * @param array[] $instanceids Instance IDs grouped by module name.
     * @return array Preloaded module records grouped by their relation.
     */
    private function preload_module_data(array $instanceids): array {
        global $DB;

        $moduledata = [
            'page' => [],
            'book' => [],
            'book_chapters' => [],
            'label' => [],
            'lesson' => [],
            'lesson_pages' => [],
        ];
        $tableexists = [];

        foreach (['page', 'book', 'label', 'lesson'] as $modname) {
            $ids = array_values(array_unique($instanceids[$modname] ?? []));
            if (!$ids) {
                continue;
            }

            $tableexists[$modname] = $DB->get_manager()->table_exists($modname);
            if (!$tableexists[$modname]) {
                continue;
            }

            $moduledata[$modname] = $this->get_records_by_ids($modname, $ids);
        }

        $bookids = array_values(array_unique($instanceids['book'] ?? []));
        if ($bookids && ($tableexists['book_chapters'] ??= $DB->get_manager()->table_exists('book_chapters'))) {
            $chapters = $this->get_records_by_foreign_ids(
                'book_chapters',
                'bookid',
                $bookids,
                'hidden = :hidden',
                ['hidden' => 0],
                'bookid, pagenum'
            );
            foreach ($chapters as $chapter) {
                $moduledata['book_chapters'][$chapter->bookid][] = $chapter;
            }
        }

        $lessonids = array_values(array_unique($instanceids['lesson'] ?? []));
        if ($lessonids && ($tableexists['lesson_pages'] ??= $DB->get_manager()->table_exists('lesson_pages'))) {
            $pages = $this->get_records_by_foreign_ids('lesson_pages', 'lessonid', $lessonids, '', [], 'lessonid, id');
            foreach ($pages as $page) {
                $moduledata['lesson_pages'][$page->lessonid][] = $page;
            }
        }

        return $moduledata;
    }

    /**
     * Loads records whose IDs are in the supplied list.
     *
     * @param string $table Database table name from the supported module list.
     * @param int[] $ids Record IDs.
     * @return \stdClass[] Records keyed by ID.
     */
    private function get_records_by_ids(string $table, array $ids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'instanceid');

        return $DB->get_records_sql(
            "SELECT *
               FROM {{$table}}
              WHERE id {$insql}",
            $params
        );
    }

    /**
     * Loads records related to any of the supplied foreign IDs.
     *
     * @param string $table Database table name from the supported module list.
     * @param string $field Foreign key field from the supported module list.
     * @param int[] $ids Foreign key IDs.
     * @param string $extra Condition fragment using named parameters.
     * @param array $params Additional query parameters.
     * @param string $sort Order fields from the supported module list.
     * @return \stdClass[] Records keyed by ID.
     */
    private function get_records_by_foreign_ids(
        string $table,
        string $field,
        array $ids,
        string $extra,
        array $params,
        string $sort
    ): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'foreignid');
        $where = "{$field} {$insql}";
        if ($extra !== '') {
            $where .= " AND {$extra}";
        }

        $orderby = $sort === '' ? '' : " ORDER BY {$sort}";

        return $DB->get_records_sql(
            "SELECT *
               FROM {{$table}}
              WHERE {$where}{$orderby}",
            $inparams + $params
        );
    }

    /**
     * Extracts supported files attached to an activity.
     *
     * @param int $courseid Course id.
     * @param \cm_info $cm Course module info.
     * @param \context_module $context Module context.
     * @return array[]
     */
    private function extract_module_files(int $courseid, \cm_info $cm, \context_module $context): array {
        $fs = get_file_storage();
        $documents = [];
        $component = 'mod_' . $cm->modname;

        foreach ($this->get_file_areas_for_module($cm->modname) as $filearea) {
            $files = $fs->get_area_files(
                $context->id,
                $component,
                $filearea,
                false,
                'sortorder, itemid, filepath, filename',
                false
            );

            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }

                // Cloud providers ingest the original Moodle file and own
                // parsing, chunking and embedding. Moodle only catalogues it.
                $text = '';

                $documents[] = $this->build_document(
                    $courseid,
                    $context->id,
                    $cm->id,
                    'file',
                    implode(':', [
                        'file',
                        $file->get_contextid(),
                        $file->get_component(),
                        $file->get_filearea(),
                        $file->get_itemid(),
                        $file->get_filepath(),
                        $file->get_filename(),
                        $file->get_contenthash(),
                    ]),
                    $cm->name . ': ' . $file->get_filename(),
                    $text,
                    $file->get_contenthash(),
                    [
                        'module' => $cm->modname,
                        'storedfileid' => $file->get_id(),
                        'component' => $file->get_component(),
                        'filearea' => $file->get_filearea(),
                        'itemid' => $file->get_itemid(),
                        'filepath' => $file->get_filepath(),
                        'filename' => $file->get_filename(),
                        'mimetype' => $file->get_mimetype(),
                    ]
                );
            }
        }

        return $documents;
    }

    /**
     * Creates a normalised extracted document record.
     *
     * @param int $courseid Course id.
     * @param int $contextid Context id.
     * @param int|null $cmid Course module id.
     * @param string $sourcetype Source type.
     * @param string $sourceidentifier Stable source identifier.
     * @param string $title Document title.
     * @param string $text Extracted text.
     * @param string|null $contenthash Content hash when available.
     * @param array $metadata Metadata.
     * @return array
     */
    private function build_document(
        int $courseid,
        int $contextid,
        ?int $cmid,
        string $sourcetype,
        string $sourceidentifier,
        string $title,
        string $text,
        ?string $contenthash,
        array $metadata
    ): array {
        $text = $this->normalise_extracted_text($text);

        return [
            'courseid' => $courseid,
            'contextid' => $contextid,
            'cmid' => $cmid,
            'sourcehash' => sha1($sourceidentifier),
            'sourcetype' => $sourcetype,
            'sourceidentifier' => shorten_text($sourceidentifier, 255),
            'title' => shorten_text($title, 255),
            'text' => $text,
            'contenthash' => $contenthash,
            'texthash' => sha1($text),
            'metadata' => $metadata,
        ];
    }

    /**
     * Decides whether an extracted document should stay in the indexing pipeline.
     *
     * Empty text is accepted for supported files because the selected cloud
     * provider ingests the original binary file directly.
     *
     * @param array $document Extracted document payload.
     * @return bool
     */
    private function should_keep_document(array $document): bool {
        if (trim((string)($document['text'] ?? '')) !== '') {
            return true;
        }

        $metadata = $document['metadata'] ?? [];
        $filename = strtolower((string)($metadata['filename'] ?? ''));

        return $document['sourcetype'] === 'file' &&
            in_array(pathinfo($filename, PATHINFO_EXTENSION), $this->allowed_extensions(), true);
    }

    /**
     * Normalises extracted text.
     *
     * @param string $text Text.
     * @return string
     */
    private function normalise_extracted_text(string $text): string {
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\R{3,}/u', "\n\n", (string)$text);

        return trim((string)$text);
    }

    /**
     * Returns file areas worth scanning for each module type.
     *
     * @param string $modname Module name.
     * @return string[]
     */
    private function get_file_areas_for_module(string $modname): array {
        $moduleareas = [
            'resource' => ['content'],
            'folder' => ['content'],
            'page' => ['content'],
            'book' => ['chapter'],
            'label' => ['intro'],
            'lesson' => ['intro', 'mediafile', 'page_contents'],
        ];

        return $moduleareas[$modname] ?? [];
    }

    /**
     * Parses allowed file extensions.
     *
     * @return string[]
     */
    private function allowed_extensions(): array {
        $raw = (string)(get_config('local_studybuddy', 'allowedextensions') ?: 'pdf,docx,pptx,txt,html,htm,md');
        $parts = preg_split('/[\s,]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY);
        $parts = array_map(static fn(string $extension): string => ltrim(trim($extension), '.'), $parts ?: []);

        return array_values(array_unique($parts));
    }
}
