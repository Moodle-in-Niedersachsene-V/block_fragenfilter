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

namespace block_fragenfilter\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Privacy metadata, export and deletion of account-bound export files.
 *
 * Ratings carry only the pseudonymous cookie hash (ADR 0003) and are therefore
 * neither exported nor deleted per account.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Collects the metadata items by name.
     *
     * @return \core_privacy\local\metadata\types\type[] Items keyed by name.
     */
    private function metadata_items(): array {
        $items = [];
        foreach (provider::get_metadata(new collection('block_fragenfilter'))->get_collection() as $item) {
            $items[$item->get_name()] = $item;
        }
        return $items;
    }

    /**
     * Creates a CSV export stored for the given account through the real export code.
     *
     * @param \stdClass $user Account that exports.
     * @param int $questionid Question to export.
     * @param string $format Export format.
     * @return \stored_file The stored export file.
     */
    private function export_for(\stdClass $user, int $questionid, string $format = 'csv'): \stored_file {
        $this->setUser($user);
        \block_fragenfilter\question_export::create((string)$questionid, $format, \context_system::instance()->id);
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'block_fragenfilter',
            'export',
            $user->id,
            'id',
            false
        );
        $this->assertCount(1, $files);
        return reset($files);
    }

    /**
     * Creates two exporting accounts, each with an export file, and one rating.
     *
     * @return array Users and the rating row id.
     */
    private function scenario(): array {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $question = $generator->get_plugin_generator('core_question')->create_question('truefalse', null, [
            'category' => $generator->get_plugin_generator('core_question')->create_question_category()->id,
        ]);
        $first = $generator->create_user();
        $second = $generator->create_user();
        $bystander = $generator->create_user();
        set_config('siteadmins', implode(',', [get_config('moodle', 'siteadmins'), $first->id, $second->id]));
        $this->export_for($first, $question->id);
        $this->export_for($second, $question->id, 'gift');
        $ratingid = $DB->insert_record('block_fragenfilter_ratings', [
            'questionid' => $question->id,
            'cookiehash' => hash('sha256', 'visitor'),
            'stars' => 4,
            'timecreated' => time(),
        ]);
        return [$first, $second, $bystander, $ratingid];
    }

    public function test_metadata_declares_ratings_cookie_files_cache_logs_and_ai_flows(): void {
        $items = $this->metadata_items();

        $this->assertInstanceOf(types\database_table::class, $items['block_fragenfilter_ratings']);
        $this->assertSame(
            ['questionid', 'cookiehash', 'stars', 'timecreated'],
            array_keys($items['block_fragenfilter_ratings']->get_privacy_fields())
        );
        $this->assertInstanceOf(types\external_location::class, $items['kf_rater_id']);
        $this->assertSame(['cookiehash'], array_keys($items['kf_rater_id']->get_privacy_fields()));
        $this->assertInstanceOf(types\subsystem_link::class, $items['core_files']);
        $this->assertInstanceOf(types\subsystem_link::class, $items['core_cache']);
        $this->assertSame(['userid'], array_keys($items['core_cache']->get_privacy_fields()));
        $this->assertInstanceOf(types\plugintype_link::class, $items['logstore']);
        $this->assertSame(['userid', 'ip', 'objectid', 'other'], array_keys($items['logstore']->get_privacy_fields()));
        foreach (['anthropic', 'ollama'] as $backend) {
            $this->assertInstanceOf(types\external_location::class, $items[$backend]);
            $this->assertSame(
                ['questiontype', 'questiontitle', 'questiontext', 'tagcategories'],
                array_keys($items[$backend]->get_privacy_fields())
            );
        }
        $this->assertCount(7, $items);
    }

    public function test_metadata_strings_exist_in_english_and_german(): void {
        global $CFG;
        $strings = [];
        foreach (['en', 'de'] as $lang) {
            $string = [];
            include($CFG->dirroot . "/blocks/fragenfilter/lang/$lang/block_fragenfilter.php");
            $strings[$lang] = array_values(array_filter(
                array_keys($string),
                static fn($key) => str_starts_with($key, 'privacy:')
            ));
        }
        $this->assertEqualsCanonicalizing($strings['en'], $strings['de']);

        $keys = [];
        foreach ($this->metadata_items() as $item) {
            $keys[] = $item->get_summary();
            array_push($keys, ...array_values($item->get_privacy_fields()));
        }
        $keys[] = 'privacy:path:export';
        foreach ($keys as $key) {
            $this->assertContains($key, $strings['en'], $key);
        }
        $this->assertStringNotContainsString(
            'does not store any personal data',
            get_string('privacy:metadata', 'block_fragenfilter')
        );
    }

    public function test_contexts_for_user_are_the_system_context_only_for_exporting_accounts(): void {
        [$first, , $bystander] = $this->scenario();

        $contextids = $this->get_contexts_for_userid($first->id, 'block_fragenfilter')->get_contextids();
        $this->assertSame([(int)\context_system::instance()->id], array_map('intval', $contextids));
        $this->assertSame([], $this->get_contexts_for_userid($bystander->id, 'block_fragenfilter')->get_contextids());
    }

    public function test_export_contains_only_the_files_of_the_requesting_account(): void {
        [$first] = $this->scenario();
        $context = \context_system::instance();

        $this->export_context_data_for_user($first->id, $context, 'block_fragenfilter');

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
        $files = $writer->get_files([get_string('privacy:path:export', 'block_fragenfilter')]);
        $this->assertCount(1, $files);
        $own = get_file_storage()->get_area_files($context->id, 'block_fragenfilter', 'export', $first->id, 'id', false);
        $this->assertStringEndsWith('.csv', reset($files)->get_filename());
        $this->assertSame(reset($own)->get_filename(), reset($files)->get_filename());
        $this->assertSame(reset($own)->get_contenthash(), reset($files)->get_contenthash());
    }

    public function test_export_for_an_account_without_files_writes_nothing(): void {
        [, , $bystander] = $this->scenario();

        $this->export_all_data_for_user($bystander->id, 'block_fragenfilter');

        $this->assertFalse(writer::with_context(\context_system::instance())->has_any_data());
    }

    public function test_delete_for_user_removes_only_that_accounts_files_and_keeps_ratings(): void {
        global $DB;
        [$first, $second, , $ratingid] = $this->scenario();
        $context = \context_system::instance();

        provider::delete_data_for_user(new approved_contextlist($first, 'block_fragenfilter', [$context->id]));

        $fs = get_file_storage();
        $this->assertCount(0, $fs->get_area_files($context->id, 'block_fragenfilter', 'export', $first->id, 'id', false));
        $this->assertCount(1, $fs->get_area_files($context->id, 'block_fragenfilter', 'export', $second->id, 'id', false));
        $this->assertTrue($DB->record_exists('block_fragenfilter_ratings', ['id' => $ratingid]));
    }

    public function test_delete_for_user_ignores_other_contexts(): void {
        [$first] = $this->scenario();
        $course = $this->getDataGenerator()->create_course();

        provider::delete_data_for_user(
            new approved_contextlist($first, 'block_fragenfilter', [\context_course::instance($course->id)->id])
        );

        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'block_fragenfilter',
            'export',
            $first->id,
            'id',
            false
        );
        $this->assertCount(1, $files);
    }

    public function test_delete_for_all_users_in_context_removes_every_export_file(): void {
        global $DB;
        $this->scenario();
        $context = \context_system::instance();

        provider::delete_data_for_all_users_in_context($context);

        $files = get_file_storage()->get_area_files($context->id, 'block_fragenfilter', 'export', false, 'id', false);
        $this->assertCount(0, $files);
        $this->assertSame(1, $DB->count_records('block_fragenfilter_ratings'));
    }

    public function test_userlist_and_delete_for_users_cover_only_listed_accounts(): void {
        [$first, $second, $bystander] = $this->scenario();
        $context = \context_system::instance();

        $userlist = new userlist($context, 'block_fragenfilter');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $userlist->get_userids());
        $this->assertNotContains((int)$bystander->id, array_map('intval', $userlist->get_userids()));

        provider::delete_data_for_users(new approved_userlist($context, 'block_fragenfilter', [$first->id, $bystander->id]));

        $fs = get_file_storage();
        $this->assertCount(0, $fs->get_area_files($context->id, 'block_fragenfilter', 'export', $first->id, 'id', false));
        $this->assertCount(1, $fs->get_area_files($context->id, 'block_fragenfilter', 'export', $second->id, 'id', false));
    }

    public function test_userlist_is_empty_outside_the_system_context(): void {
        $this->scenario();
        $course = $this->getDataGenerator()->create_course();

        $userlist = new userlist(\context_course::instance($course->id), 'block_fragenfilter');
        provider::get_users_in_context($userlist);

        $this->assertSame([], $userlist->get_userids());
    }
}
