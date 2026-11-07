<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Administration settings for block_fragenfilter.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @author     Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Search scope.
    $settings->add(new admin_setting_heading(
        'block_fragenfilter/searchscope_heading',
        get_string('settings_searchscope_heading', 'block_fragenfilter'),
        get_string('settings_searchscope_desc', 'block_fragenfilter')
    ));

    $settings->add(new admin_setting_configselect(
        'block_fragenfilter/searchscope',
        get_string('settings_searchscope', 'block_fragenfilter'),
        get_string('settings_searchscope_help', 'block_fragenfilter'),
        'all',
        [
            'all' => get_string('scope_all', 'block_fragenfilter'),
            'course' => get_string('scope_course', 'block_fragenfilter'),
            'system' => get_string('scope_system', 'block_fragenfilter'),
        ]
    ));

    // Question type display.
    $settings->add(new admin_setting_heading(
        'block_fragenfilter/questiontypes_heading',
        get_string('settings_questiontypes_heading', 'block_fragenfilter'),
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'block_fragenfilter/questiontypes_source',
        get_string('settings_questiontypes_source', 'block_fragenfilter'),
        get_string('settings_questiontypes_source_help', 'block_fragenfilter'),
        'installed',
        [
            'installed' => get_string('qtypes_installed', 'block_fragenfilter'),
            'existing' => get_string('qtypes_existing', 'block_fragenfilter'),
        ]
    ));

    // Difficulty levels (extendable).
    $settings->add(new admin_setting_heading(
        'block_fragenfilter/difficulty_heading',
        get_string('settings_difficulty_heading', 'block_fragenfilter'),
        get_string('settings_difficulty_desc', 'block_fragenfilter')
    ));

    $settings->add(new admin_setting_configtextarea(
        'block_fragenfilter/difficulty_levels',
        get_string('settings_difficulty_levels', 'block_fragenfilter'),
        get_string('settings_difficulty_levels_help', 'block_fragenfilter'),
        "Leicht\nMittel\nSchwer"
    ));

    // Custom filter fields.
    $settings->add(new admin_setting_heading(
        'block_fragenfilter/customfields_heading',
        get_string('settings_customfields_heading', 'block_fragenfilter'),
        ''
    ));

    $settings->add(new admin_setting_configtextarea(
        'block_fragenfilter/custom_tags',
        get_string('settings_custom_tags', 'block_fragenfilter'),
        get_string('settings_custom_tags_help', 'block_fragenfilter'),
        ''
    ));

    // Export formats.
    $settings->add(new admin_setting_heading(
        'block_fragenfilter/export_heading',
        get_string('settings_export_heading', 'block_fragenfilter'),
        ''
    ));

    $settings->add(new admin_setting_configmulticheckbox(
        'block_fragenfilter/exportformats',
        get_string('settings_exportformats', 'block_fragenfilter'),
        get_string('settings_exportformats_help', 'block_fragenfilter'),
        ['xml' => 1, 'csv' => 1, 'gift' => 1],
        [
            'xml' => get_string('export_xml', 'block_fragenfilter'),
            'csv' => get_string('export_csv', 'block_fragenfilter'),
            'gift' => get_string('export_gift', 'block_fragenfilter'),
        ]
    ));

    // Result limit.
    $settings->add(new admin_setting_configtext(
        'block_fragenfilter/resultlimit',
        get_string('settings_resultlimit', 'block_fragenfilter'),
        get_string('settings_resultlimit_help', 'block_fragenfilter'),
        '200',
        PARAM_INT
    ));

    // AI tagging: main switch and review link.
    $settings->add(new admin_setting_heading(
        'block_fragenfilter/ai_heading',
        get_string('settings_ai_heading', 'block_fragenfilter'),
        get_string('settings_ai_desc', 'block_fragenfilter')
    ));

    $reviewurl = new moodle_url('/blocks/fragenfilter/review.php');
    $settings->add(new admin_setting_heading(
        'block_fragenfilter/ai_review_link',
        get_string('settings_review_heading', 'block_fragenfilter'),
        html_writer::link(
            $reviewurl,
            get_string('settings_review_link', 'block_fragenfilter'),
            ['class' => 'btn btn-primary']
        )
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_fragenfilter/ai_enabled',
        get_string('settings_ai_enabled', 'block_fragenfilter'),
        get_string('settings_ai_enabled_help', 'block_fragenfilter'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_fragenfilter/ai_autoapply',
        get_string('settings_ai_autoapply', 'block_fragenfilter'),
        get_string('settings_ai_autoapply_help', 'block_fragenfilter'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'block_fragenfilter/ai_batch_size',
        get_string('settings_ai_batch_size', 'block_fragenfilter'),
        get_string('settings_ai_batch_size_help', 'block_fragenfilter'),
        '20',
        PARAM_INT
    ));

    // AI backend selection.
    $settings->add(new admin_setting_heading(
        'block_fragenfilter/ai_backend_heading',
        get_string('settings_ai_backend_heading', 'block_fragenfilter'),
        get_string('settings_ai_backend_desc', 'block_fragenfilter')
    ));

    $settings->add(new admin_setting_configselect(
        'block_fragenfilter/ai_backend',
        get_string('settings_ai_backend', 'block_fragenfilter'),
        get_string('settings_ai_backend_help', 'block_fragenfilter'),
        'ollama',
        [
            'ollama' => get_string('ai_backend_ollama', 'block_fragenfilter'),
            'claude' => get_string('ai_backend_claude', 'block_fragenfilter'),
        ]
    ));

    // Ollama settings.
    $settings->add(new admin_setting_heading(
        'block_fragenfilter/ai_ollama_heading',
        get_string('settings_ai_ollama_heading', 'block_fragenfilter'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'block_fragenfilter/ai_ollama_url',
        get_string('settings_ai_ollama_url', 'block_fragenfilter'),
        get_string('settings_ai_ollama_url_help', 'block_fragenfilter'),
        'http://localhost:11434'
    ));

    $settings->add(new admin_setting_configtext(
        'block_fragenfilter/ai_ollama_model',
        get_string('settings_ai_ollama_model', 'block_fragenfilter'),
        get_string('settings_ai_ollama_model_help', 'block_fragenfilter'),
        'gemma3:4b'
    ));

    // Claude API settings.
    $settings->add(new admin_setting_heading(
        'block_fragenfilter/ai_claude_heading',
        get_string('settings_ai_claude_heading', 'block_fragenfilter'),
        get_string('settings_ai_claude_desc', 'block_fragenfilter')
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'block_fragenfilter/ai_claude_apikey',
        get_string('settings_ai_claude_apikey', 'block_fragenfilter'),
        get_string('settings_ai_claude_apikey_help', 'block_fragenfilter'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'block_fragenfilter/ai_claude_model',
        get_string('settings_ai_claude_model', 'block_fragenfilter'),
        get_string('settings_ai_claude_model_help', 'block_fragenfilter'),
        'claude-haiku-4-5-20251001'
    ));

    // Tagging options.
    $settings->add(new admin_setting_heading(
        'block_fragenfilter/ai_tagging_heading',
        get_string('settings_ai_tagging_heading', 'block_fragenfilter'),
        ''
    ));

    $settings->add(new admin_setting_configtextarea(
        'block_fragenfilter/ai_tag_categories',
        get_string('settings_ai_tag_categories', 'block_fragenfilter'),
        get_string('settings_ai_tag_categories_help', 'block_fragenfilter'),
        "Mathematik\nPhysik\nChemie\nBiologie\nInformatik\nDeutsch\nEnglisch"
    ));

    $settings->add(new admin_setting_configtext(
        'block_fragenfilter/ai_max_tags',
        get_string('settings_ai_max_tags', 'block_fragenfilter'),
        get_string('settings_ai_max_tags_help', 'block_fragenfilter'),
        '5',
        PARAM_INT
    ));
}
