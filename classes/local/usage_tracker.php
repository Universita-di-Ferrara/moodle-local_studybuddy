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
 * Stores provider usage counts without retaining prompts or responses.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_tracker {
    /**
     * Returns the configured model, including the provider default.
     *
     * @param string $provider Provider key.
     * @return string
     */
    public static function configured_model(string $provider): string {
        $settings = [
            'openai' => ['openairesponsesmodel', 'gpt-4.1'],
            'google' => ['googlegenerationmodel', 'gemini-3.5-flash'],
            'vertexai' => ['vertexaigenerationmodel', 'gemini-2.5-flash'],
        ];
        if (!isset($settings[$provider])) {
            return '';
        }

        [$setting, $default] = $settings[$provider];
        return (string)(get_config('local_studybuddy', $setting) ?: $default);
    }

    /**
     * Extracts normalised input/output token counts from provider responses.
     *
     * @param array $response Raw provider response or normalized usage.
     * @return array{input:int, output:int, total:int}
     */
    public static function token_counts(array $response): array {
        $usage = $response['_usage'] ?? $response['usage'] ?? $response['usageMetadata'] ?? [];
        if (!is_array($usage)) {
            $usage = [];
        }
        $input = (int)($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? $usage['promptTokenCount'] ??
            $usage['total_input_tokens'] ?? 0);
        $output = (int)($usage['output_tokens'] ?? $usage['completion_tokens'] ?? $usage['candidatesTokenCount'] ??
            $usage['total_output_tokens'] ?? 0);
        $total = (int)($usage['total_tokens'] ?? $usage['totalTokenCount'] ?? ($input + $output));

        return ['input' => max(0, $input), 'output' => max(0, $output), 'total' => max(0, $total)];
    }

    /**
     * Records one provider operation, omitting prompt and response content.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @param string $feature Operation name.
     * @param string $activitytype Activity type, if any.
     * @param string $provider Provider key.
     * @param string $model Provider model.
     * @param array $response Raw provider response or normalized usage.
     * @return void
     */
    public static function record(
        int $courseid,
        int $userid,
        string $feature,
        string $activitytype,
        string $provider,
        string $model,
        array $response
    ): void {
        global $DB;

        $counts = self::token_counts($response);
        try {
            $DB->insert_record('local_studybuddy_usage', (object)[
                'courseid' => $courseid,
                'userid' => $userid,
                'provider' => $provider,
                'model' => $model ?: self::configured_model($provider),
                'feature' => $feature,
                'activitytype' => $activitytype,
                'prompttext' => '',
                'responsetext' => '',
                'inputtokens' => $counts['input'],
                'outputtokens' => $counts['output'],
                'totaltokens' => $counts['total'],
                'timecreated' => time(),
            ]);
        } catch (\Throwable $exception) {
            debugging('StudyBuddy usage tracking failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
