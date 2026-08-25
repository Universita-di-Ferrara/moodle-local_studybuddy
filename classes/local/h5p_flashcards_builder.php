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
 * Builds H5P Dialog Cards packages from StudyBuddy flashcards.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5p_flashcards_builder {
    /** @var string H5P main library. */
    private const MAIN_LIBRARY = 'H5P.Dialogcards';

    /**
     * Build a temporary .h5p package.
     *
     * @param array $data Reviewed StudyBuddy flashcards data.
     * @return string Path to the generated .h5p file.
     */
    public function build_package(array $data): string {
        global $CFG, $DB;

        $libraries = $DB->get_records('h5p_libraries', [
            'machinename' => self::MAIN_LIBRARY,
            'runnable' => 1,
            'enabled' => 1,
        ], 'majorversion DESC, minorversion DESC, patchversion DESC', '*', 0, 1);
        $library = reset($libraries);

        if (!$library) {
            throw new \moodle_exception('h5pdialogcardsmissing', 'local_studybuddy');
        }

        $cards = array_values($data['flashcards'] ?? []);
        if (empty($cards)) {
            throw new \moodle_exception('invalidjson', 'local_studybuddy');
        }

        $tempdir = make_temp_directory('local_studybuddy/h5p_' . uniqid('', true));
        $contentdir = $tempdir . '/content';
        mkdir($contentdir, $CFG->directorypermissions ?? 0777, true);

        $this->write_json($tempdir . '/h5p.json', $this->build_h5p_metadata($data, $library));
        $this->write_json($contentdir . '/content.json', $this->build_content($data, $cards));

        $packagepath = $tempdir . '/' . clean_filename($this->get_title($data)) . '.h5p';
        $zip = new \ZipArchive();
        if ($zip->open($packagepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \moodle_exception('h5ppackagecreatefailed', 'local_studybuddy');
        }

        $zip->addFile($tempdir . '/h5p.json', 'h5p.json');
        $zip->addFile($contentdir . '/content.json', 'content/content.json');
        $zip->close();

        return $packagepath;
    }

    /**
     * Build h5p.json metadata.
     *
     * @param array $data Flashcards data.
     * @param \stdClass $library H5P library record.
     * @return array
     */
    private function build_h5p_metadata(array $data, \stdClass $library): array {
        return [
            'title' => $this->get_title($data),
            'language' => 'en',
            'mainLibrary' => self::MAIN_LIBRARY,
            'embedTypes' => ['div'],
            'license' => 'U',
            'preloadedDependencies' => [[
                'machineName' => self::MAIN_LIBRARY,
                'majorVersion' => (int)$library->majorversion,
                'minorVersion' => (int)$library->minorversion,
            ], ],
        ];
    }

    /**
     * Build Dialog Cards content.json.
     *
     * @param array $data Flashcards data.
     * @param array $cards Flashcards.
     * @return array
     */
    private function build_content(array $data, array $cards): array {
        $dialogs = [];
        foreach ($cards as $card) {
            $front = trim((string)($card['front'] ?? ''));
            $back = trim((string)($card['back'] ?? ''));
            if ($front === '' || $back === '') {
                continue;
            }

            $hint = trim((string)($card['hint'] ?? ''));
            $dialogs[] = [
                'text' => $this->paragraph($front),
                'answer' => $this->paragraph($back),
                'tips' => [
                    'front' => $hint,
                    'back' => '',
                ],
            ];
        }

        if (empty($dialogs)) {
            throw new \moodle_exception('invalidjson', 'local_studybuddy');
        }

        return [
            'title' => $this->paragraph($this->get_title($data)),
            'mode' => 'normal',
            'description' => '',
            'dialogs' => $dialogs,
            'behaviour' => [
                'enableRetry' => true,
                'disableBackwardsNavigation' => false,
                'scaleTextNotCard' => false,
                'randomCards' => false,
            ],
        ];
    }

    /**
     * Write pretty JSON to disk.
     *
     * @param string $path File path.
     * @param array $data Data.
     * @return void
     */
    private function write_json(string $path, array $data): void {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json) === false) {
            throw new \moodle_exception('h5ppackagecreatefailed', 'local_studybuddy');
        }
    }

    /**
     * Get a safe title.
     *
     * @param array $data Data.
     * @return string
     */
    private function get_title(array $data): string {
        $title = trim((string)($data['title'] ?? get_string('activitytype:h5p_flashcards', 'local_studybuddy')));
        return shorten_text($title !== '' ? $title : get_string('activitytype:h5p_flashcards', 'local_studybuddy'), 120);
    }

    /**
     * Prepare centered HTML paragraph accepted by Dialog Cards.
     *
     * @param string $text Plain text.
     * @return string
     */
    private function paragraph(string $text): string {
        return '<p style="text-align: center;">' . s($text) . '</p>';
    }
}
