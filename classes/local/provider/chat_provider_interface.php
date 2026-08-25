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

/**
 * Provider-specific chat contract.
 *
 * This mirrors the block_aichat split between chat and course sync providers:
 * chat_service owns Moodle records, while each provider owns its RAG/chat API.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface chat_provider_interface {
    /**
     * Asks the provider-backed course knowledge base.
     *
     * @param string|null $knowledgebaseid Provider vector store, file store or corpus id.
     * @param array $conversation Conversation history.
     * @param string $systemprompt System prompt.
     * @param array $options Provider-specific retrieval options.
     * @return array Provider response.
     */
    public function ask_course_kb(?string $knowledgebaseid, array $conversation, string $systemprompt, array $options = []): array;

    /**
     * Extracts assistant text from the provider response.
     *
     * @param array $response Provider response.
     * @return string Text.
     */
    public function extract_text(array $response): string;

    /**
     * Extracts provider source references when available.
     *
     * @param array $response Provider response.
     * @return array Sources.
     */
    public function extract_sources(array $response): array;
}
