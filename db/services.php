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
 * Web service definitions for block_fragenfilter.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @author     Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    'block_fragenfilter_get_questiontypes' => [
        'classname' => 'block_fragenfilter_external',
        'methodname' => 'get_questiontypes',
        'description' => 'Loads all question types present in the question bank',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => false,
        'capabilities' => 'block/fragenfilter:view',
    ],

    'block_fragenfilter_search_questions' => [
        'classname' => 'block_fragenfilter_external',
        'methodname' => 'search_questions',
        'description' => 'Searches questions across multiple question banks',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => false,
        'capabilities' => 'block/fragenfilter:view',
    ],

    'block_fragenfilter_get_categories' => [
        'classname' => 'block_fragenfilter_external',
        'methodname' => 'get_categories',
        'description' => 'Loads all searchable question categories',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => false,
        'capabilities' => 'block/fragenfilter:view',
    ],

    'block_fragenfilter_export_questions' => [
        'classname' => 'block_fragenfilter_external',
        'methodname' => 'export_questions',
        'description' => 'Exports selected questions (XML, CSV, GIFT)',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'block/fragenfilter:export',
    ],

    'block_fragenfilter_check_capabilities' => [
        'classname'     => 'block_fragenfilter_external',
        'methodname'    => 'check_capabilities',
        'description'   => 'Checks the current user\'s capabilities (after pool login)',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => false,
        'capabilities'  => '',
    ],

    'block_fragenfilter_rate_question' => [
        'classname'     => 'block_fragenfilter_external',
        'methodname'    => 'rate_question',
        'description'   => 'Saves a star rating for a question',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => false,
        'capabilities'  => 'block/fragenfilter:view',
    ],

    'block_fragenfilter_get_ratings' => [
        'classname'     => 'block_fragenfilter_external',
        'methodname'    => 'get_ratings',
        'description'   => 'Loads ratings for a list of questions',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => false,
        'capabilities'  => 'block/fragenfilter:view',
    ],
];
