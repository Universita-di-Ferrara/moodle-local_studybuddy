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

namespace local_studybuddy\local\provider;

use local_studybuddy\local\provider\google\google_chat_provider;
use local_studybuddy\local\provider\google\google_provider;
use local_studybuddy\local\provider\openai\openai_chat_provider;
use local_studybuddy\local\provider\openai\openai_provider;
use local_studybuddy\local\provider\vertexai\vertexai_chat_provider;
use local_studybuddy\local\provider\vertexai\vertexai_provider;

/**
 * Creates the configured AI provider.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_factory {
    /**
     * Returns the configured provider.
     *
     * @return ai_provider
     */
    public static function create(): ai_provider {
        $provider = (string)(get_config('local_studybuddy', 'provider') ?: 'openai');

        switch ($provider) {
            case 'openai':
                return new openai_provider();

            case 'google':
                return new google_provider();

            case 'vertexai':
                return new vertexai_provider();

            default:
                throw new \moodle_exception('invalidprovider', 'local_studybuddy', '', $provider);
        }
    }

    /**
     * Returns the configured chat provider.
     *
     * @return chat_provider_interface
     */
    public static function create_chat_provider(): chat_provider_interface {
        $provider = (string)(get_config('local_studybuddy', 'provider') ?: 'openai');

        switch ($provider) {
            case 'openai':
                return new openai_chat_provider();

            case 'google':
                return new google_chat_provider();

            case 'vertexai':
                return new vertexai_chat_provider();

            default:
                throw new \moodle_exception('invalidprovider', 'local_studybuddy', '', $provider);
        }
    }
}
