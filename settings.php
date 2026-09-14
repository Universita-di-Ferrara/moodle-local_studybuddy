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

/**
 * Admin settings for local_studybuddy.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if (!class_exists('local_studybuddy_admin_setting_heading')) {
    /**
     * Heading setting compatible with Moodle hide_if() dependencies.
     */
    class local_studybuddy_admin_setting_heading extends admin_setting_heading {
        /**
         * Returns an HTML string wrapped like a regular setting row.
         *
         * @param string $data
         * @param string $query
         * @return string
         */
        public function output_html($data, $query = '') {
            global $OUTPUT;

            $context = new stdClass();
            $context->title = $this->visiblename;
            $context->description = $this->description;
            $context->descriptionformatted = highlight($query, markdown_to_html($this->description));

            $hidden = \html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => $this->get_full_name(),
                'value' => $data ?? 1,
            ]);

            return \html_writer::start_div('form-item row', ['id' => 'admin-' . $this->name]) .
                $hidden .
                \html_writer::div($OUTPUT->render_from_template('core_admin/setting_heading', $context), 'col-sm-12') .
                \html_writer::end_div();
        }
    }
}

if ($hassiteconfig) {
    $settings = $ADMIN->locate('local_studybuddy');
    if (!$settings) {
        $settings = new admin_settingpage('local_studybuddy', get_string('pluginname', 'local_studybuddy'));
        $ADMIN->add('localplugins', $settings);
    }
}

if ($hassiteconfig && $ADMIN->fulltree && $settings instanceof admin_settingpage) {
    $settings->add(new admin_setting_configselect(
        'local_studybuddy/provider',
        get_string('settings:provider', 'local_studybuddy'),
        get_string('settings:provider_desc', 'local_studybuddy'),
        'openai',
        [
            'openai' => get_string('provider:openai', 'local_studybuddy'),
            'google' => get_string('provider:google', 'local_studybuddy'),
            'vertexai' => get_string('provider:vertexai', 'local_studybuddy'),
        ]
    ));

    $settings->add(new local_studybuddy_admin_setting_heading(
        'local_studybuddy/openai',
        get_string('settings:openai', 'local_studybuddy'),
        get_string('settings:openai_desc', 'local_studybuddy')
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_studybuddy/openaiapikey',
        get_string('settings:openaiapikey', 'local_studybuddy'),
        get_string('settings:openaiapikey_desc', 'local_studybuddy'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_studybuddy/openaibaseurl',
        get_string('settings:openaibaseurl', 'local_studybuddy'),
        get_string('settings:openaibaseurl_desc', 'local_studybuddy'),
        'https://api.openai.com/v1',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_studybuddy/openairesponsesmodel',
        get_string('settings:openairesponsesmodel', 'local_studybuddy'),
        get_string('settings:openairesponsesmodel_desc', 'local_studybuddy'),
        'gpt-4.1',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_studybuddy/openaiorganization',
        get_string('settings:openaiorganization', 'local_studybuddy'),
        get_string('settings:openaiorganization_desc', 'local_studybuddy'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_studybuddy/openaiproject',
        get_string('settings:openaiproject', 'local_studybuddy'),
        get_string('settings:openaiproject_desc', 'local_studybuddy'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new local_studybuddy_admin_setting_heading(
        'local_studybuddy/google',
        get_string('settings:google', 'local_studybuddy'),
        get_string('settings:google_desc', 'local_studybuddy')
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_studybuddy/googleapikey',
        get_string('settings:googleapikey', 'local_studybuddy'),
        get_string('settings:googleapikey_desc', 'local_studybuddy'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_studybuddy/googlebaseurl',
        get_string('settings:googlebaseurl', 'local_studybuddy'),
        get_string('settings:googlebaseurl_desc', 'local_studybuddy'),
        'https://generativelanguage.googleapis.com',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_studybuddy/googlegenerationmodel',
        get_string('settings:googlegenerationmodel', 'local_studybuddy'),
        get_string('settings:googlegenerationmodel_desc', 'local_studybuddy'),
        'gemini-3.5-flash',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_studybuddy/googlefilestoreembeddingmodel',
        get_string('settings:googlefilestoreembeddingmodel', 'local_studybuddy'),
        get_string('settings:googlefilestoreembeddingmodel_desc', 'local_studybuddy'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new local_studybuddy_admin_setting_heading(
        'local_studybuddy/vertexai',
        get_string('settings:vertexai', 'local_studybuddy'),
        get_string('settings:vertexai_desc', 'local_studybuddy')
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_studybuddy/vertexaiserviceaccountjson',
        get_string('settings:vertexaiserviceaccountjson', 'local_studybuddy'),
        get_string('settings:vertexaiserviceaccountjson_desc', 'local_studybuddy'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_studybuddy/vertexaiapikey',
        get_string('settings:vertexaiapikey', 'local_studybuddy'),
        get_string('settings:vertexaiapikey_desc', 'local_studybuddy'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_studybuddy/vertexaiaccesstoken',
        get_string('settings:vertexaiaccesstoken', 'local_studybuddy'),
        get_string('settings:vertexaiaccesstoken_desc', 'local_studybuddy'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_studybuddy/vertexaiprojectid',
        get_string('settings:vertexaiprojectid', 'local_studybuddy'),
        get_string('settings:vertexaiprojectid_desc', 'local_studybuddy'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configselect(
        'local_studybuddy/vertexailocation',
        get_string('settings:vertexailocation', 'local_studybuddy'),
        get_string('settings:vertexailocation_desc', 'local_studybuddy'),
        'europe-west3',
        \local_studybuddy\local\provider\vertexai\vertexai_client::get_supported_rag_locations()
    ));

    $vertexaibaseurl = \local_studybuddy\local\provider\vertexai\vertexai_client::get_configured_baseurl();
    $settings->add(new admin_setting_configtext(
        'local_studybuddy/vertexaibaseurl',
        get_string('settings:vertexaibaseurl', 'local_studybuddy'),
        get_string('settings:vertexaibaseurl_desc', 'local_studybuddy', $vertexaibaseurl),
        $vertexaibaseurl,
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_studybuddy/vertexaigenerationmodel',
        get_string('settings:vertexaigenerationmodel', 'local_studybuddy'),
        get_string('settings:vertexaigenerationmodel_desc', 'local_studybuddy'),
        'gemini-2.5-flash',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_studybuddy/vertexairagembeddingmodel',
        get_string('settings:vertexairagembeddingmodel', 'local_studybuddy'),
        get_string('settings:vertexairagembeddingmodel_desc', 'local_studybuddy'),
        'publishers/google/models/text-embedding-004',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_studybuddy/vertexairagtopk',
        get_string('settings:vertexairagtopk', 'local_studybuddy'),
        get_string('settings:vertexairagtopk_desc', 'local_studybuddy'),
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_studybuddy/practiceretention',
        get_string('settings:practiceretention', 'local_studybuddy'),
        get_string('settings:practiceretention_desc', 'local_studybuddy'),
        7,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_studybuddy/maxchatmessages',
        get_string('settings:maxchatmessages', 'local_studybuddy'),
        get_string('settings:maxchatmessages_desc', 'local_studybuddy'),
        24,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_studybuddy/allowedextensions',
        get_string('settings:allowedextensions', 'local_studybuddy'),
        get_string('settings:allowedextensions_desc', 'local_studybuddy'),
        "pdf\ndocx\npptx\ntxt\nhtml\nhtm\nmd"
    ));

    $settings->add(new admin_setting_heading(
        'local_studybuddy/prompts',
        get_string('settings:prompts', 'local_studybuddy'),
        get_string('settings:prompts_desc', 'local_studybuddy')
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_studybuddy/chatinstructions',
        get_string('settings:chatinstructions', 'local_studybuddy'),
        get_string('settings:chatinstructions_desc', 'local_studybuddy'),
        get_string('default:chatinstructions', 'local_studybuddy'),
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_studybuddy/activityinstructions',
        get_string('settings:activityinstructions', 'local_studybuddy'),
        get_string('settings:activityinstructions_desc', 'local_studybuddy'),
        get_string('default:activityinstructions', 'local_studybuddy'),
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_studybuddy/activityformatinstructions',
        get_string('settings:activityformatinstructions', 'local_studybuddy'),
        get_string('settings:activityformatinstructions_desc', 'local_studybuddy'),
        get_string('default:activityformatinstructions', 'local_studybuddy'),
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_studybuddy/activityjsonschema',
        get_string('settings:activityjsonschema', 'local_studybuddy'),
        get_string('settings:activityjsonschema_desc', 'local_studybuddy'),
        get_string('default:activityjsonschema', 'local_studybuddy'),
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_studybuddy/providerjsoninstructions',
        get_string('settings:providerjsoninstructions', 'local_studybuddy'),
        get_string('settings:providerjsoninstructions_desc', 'local_studybuddy'),
        get_string('default:providerjsoninstructions', 'local_studybuddy'),
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_studybuddy/providerfileinstructions',
        get_string('settings:providerfileinstructions', 'local_studybuddy'),
        get_string('settings:providerfileinstructions_desc', 'local_studybuddy'),
        get_string('default:providerfileinstructions', 'local_studybuddy'),
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_studybuddy/provideractivityinput',
        get_string('settings:provideractivityinput', 'local_studybuddy'),
        get_string('settings:provideractivityinput_desc', 'local_studybuddy'),
        get_string('default:provideractivityinput', 'local_studybuddy'),
        PARAM_RAW
    ));


    // Show only the settings belonging to the selected cloud provider.
    foreach (
        [
        'local_studybuddy/openai' => 'openai',
        'local_studybuddy/google' => 'google',
        'local_studybuddy/vertexai' => 'vertexai',
        ] as $heading => $provider
    ) {
        $settings->hide_if($heading, 'local_studybuddy/provider', 'neq', $provider);
    }

    foreach (
        [
        'openaiapikey', 'openaibaseurl', 'openairesponsesmodel', 'openaiorganization', 'openaiproject',
        ] as $settingname
    ) {
        $settings->hide_if('local_studybuddy/' . $settingname, 'local_studybuddy/provider', 'neq', 'openai');
    }

    foreach (
        [
        'googleapikey', 'googlebaseurl', 'googlegenerationmodel', 'googlefilestoreembeddingmodel',
        ] as $settingname
    ) {
        $settings->hide_if('local_studybuddy/' . $settingname, 'local_studybuddy/provider', 'neq', 'google');
    }

    foreach (
        [
        'vertexaiserviceaccountjson', 'vertexaiapikey', 'vertexaiaccesstoken', 'vertexaiprojectid',
        'vertexailocation', 'vertexaibaseurl', 'vertexaigenerationmodel', 'vertexairagembeddingmodel',
        'vertexairagtopk',
        ] as $settingname
    ) {
        $settings->hide_if('local_studybuddy/' . $settingname, 'local_studybuddy/provider', 'neq', 'vertexai');
    }

    global $PAGE;
    $PAGE->requires->js_call_amd('local_studybuddy/settings', 'init');
}
