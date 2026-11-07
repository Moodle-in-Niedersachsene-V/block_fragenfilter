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
 * Privacy provider for block_fragenfilter.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @author     Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_fragenfilter\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for block_fragenfilter.
 *
 * Ratings are stored under the pseudonymous cookie hash `kf_rater_id` only (ADR 0003). No account is
 * recorded, so they cannot be attributed to a user and are neither exported nor deleted per account.
 * Account-bound data are the export files in the file store, which this provider exports and deletes.
 * Event logs and the rate-limit cache are declared; the logstore handles its own export and deletion.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string File area of the temporary question exports. */
    private const FILEAREA = 'export';

    /**
     * Describes stored ratings, the cookie, export files, the rate limit, event logs and AI requests.
     *
     * @param collection $collection Metadata collection.
     * @return collection Completed collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('block_fragenfilter_ratings', [
            'questionid' => 'privacy:metadata:ratings:questionid',
            'cookiehash' => 'privacy:metadata:ratings:cookiehash',
            'stars' => 'privacy:metadata:ratings:stars',
            'timecreated' => 'privacy:metadata:ratings:timecreated',
        ], 'privacy:metadata:ratings');
        $collection->add_external_location_link('kf_rater_id', [
            'cookiehash' => 'privacy:metadata:ratings:cookiehash',
        ], 'privacy:metadata:cookie');
        $collection->add_subsystem_link('core_files', [
            'userid' => 'privacy:metadata:files:userid',
            'filename' => 'privacy:metadata:files:filename',
        ], 'privacy:metadata:files');
        $collection->add_subsystem_link('core_cache', [
            'userid' => 'privacy:metadata:cache:userid',
        ], 'privacy:metadata:cache');
        $collection->add_plugintype_link('logstore', [
            'userid' => 'privacy:metadata:logs:userid',
            'ip' => 'privacy:metadata:logs:ip',
            'objectid' => 'privacy:metadata:logs:objectid',
            'other' => 'privacy:metadata:logs:other',
        ], 'privacy:metadata:logs');
        $aifields = [
            'questiontype' => 'privacy:metadata:ai:questiontype',
            'questiontitle' => 'privacy:metadata:ai:questiontitle',
            'questiontext' => 'privacy:metadata:ai:questiontext',
            'tagcategories' => 'privacy:metadata:ai:tagcategories',
        ];
        $collection->add_external_location_link('anthropic', $aifields, 'privacy:metadata:anthropic');
        $collection->add_external_location_link('ollama', $aifields, 'privacy:metadata:ollama');
        return $collection;
    }

    /**
     * Finds the contexts that hold export files of the account.
     *
     * @param int $userid Account ID.
     * @return contextlist Contexts with data of the account.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT DISTINCT f.contextid
               FROM {files} f
              WHERE f.component = :component AND f.filearea = :filearea AND f.userid = :userid
                AND f.filename <> '.'",
            ['component' => 'block_fragenfilter', 'filearea' => self::FILEAREA, 'userid' => $userid]
        );
        return $contextlist;
    }

    /**
     * Lists the accounts with export files in a context.
     *
     * @param userlist $userlist Userlist to fill.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $userlist->add_from_sql(
            'userid',
            "SELECT f.userid
               FROM {files} f
              WHERE f.contextid = :contextid AND f.component = :component AND f.filearea = :filearea
                AND f.filename <> '.'",
            [
                'contextid' => $userlist->get_context()->id,
                'component' => 'block_fragenfilter',
                'filearea' => self::FILEAREA,
            ]
        );
    }

    /**
     * Exports the export files of the account.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            writer::with_context($context)->export_area_files(
                [get_string('privacy:path:export', 'block_fragenfilter')],
                'block_fragenfilter',
                self::FILEAREA,
                $userid
            );
        }
    }

    /**
     * Deletes all export files in a context.
     *
     * @param \context $context Context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        get_file_storage()->delete_area_files($context->id, 'block_fragenfilter', self::FILEAREA);
    }

    /**
     * Deletes the export files of the account.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::delete_user_files($context->id, $userid);
        }
    }

    /**
     * Deletes the export files of several accounts in one context.
     *
     * @param approved_userlist $userlist Approved accounts.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_files($userlist->get_context()->id, (int)$userid);
        }
    }

    /**
     * Deletes the export area of one account; the item ID is the account ID.
     *
     * @param int $contextid Context ID.
     * @param int $userid Account ID.
     */
    private static function delete_user_files(int $contextid, int $userid): void {
        get_file_storage()->delete_area_files($contextid, 'block_fragenfilter', self::FILEAREA, $userid);
    }
}
