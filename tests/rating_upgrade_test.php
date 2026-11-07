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

namespace block_fragenfilter;

use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Rating schema upgrades preserve stars while discarding old identities.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('xmldb_block_fragenfilter_upgrade')]
final class rating_upgrade_test extends \advanced_testcase {
    public function test_upgrade_preserves_legacy_stars_and_discards_account_and_client_identifiers(): void {
        $this->check_upgrade(true);
    }

    public function test_upgrade_of_an_empty_rating_table(): void {
        $this->check_upgrade(false);
    }

    /**
     * Upgrades an old table in this test database and restores the installed schema even on failure.
     *
     * @param bool $withratings Whether to seed account and guest ratings.
     */
    private function check_upgrade(bool $withratings): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/blocks/fragenfilter/db/upgrade.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question('truefalse', null, ['category' => $category->id]);
        $user = $this->getDataGenerator()->create_user();
        $pendingid = $DB->insert_record('block_fragenfilter_tag_pending', [
            'questionid' => $question->id, 'tags' => 'Mathematics', 'status' => 'pending',
            'timecreated' => 100, 'timemodified' => 200,
        ]);
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('block_fragenfilter_ratings');
        $dbman->drop_table($table);
        try {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('sessionhash', XMLDB_TYPE_CHAR, '64');
            $table->add_field('rating', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_question', XMLDB_KEY_FOREIGN, ['questionid'], 'question', ['id']);
            $table->add_key('fk_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('idx_userid_question', XMLDB_INDEX_NOTUNIQUE, ['userid', 'questionid']);
            $table->add_index('idx_session_question', XMLDB_INDEX_NOTUNIQUE, ['sessionhash', 'questionid']);
            $dbman->create_table($table);
            if ($withratings) {
                // Duplicate legacy visitor keys must not collapse independent historical stars.
                foreach ([5, 2, 4] as $i => $stars) {
                    $DB->insert_record('block_fragenfilter_ratings', [
                        'questionid' => $question->id, 'userid' => $i === 0 ? $user->id : null,
                        'sessionhash' => $i === 0 ? null : str_repeat('a', 64), 'rating' => $stars,
                        'timecreated' => 100 + $i, 'timemodified' => 200 + $i,
                    ]);
                }
            }
            set_config('version', 2026101604, 'block_fragenfilter');

            xmldb_block_fragenfilter_upgrade(2026101604);

            $columns = array_keys($DB->get_columns('block_fragenfilter_ratings'));
            sort($columns);
            $this->assertSame(['cookiehash', 'id', 'questionid', 'stars', 'timecreated'], $columns);
            $records = array_values($DB->get_records('block_fragenfilter_ratings', null, 'id'));
            $this->assertCount($withratings ? 3 : 0, $records);
            foreach ($records as $i => $record) {
                $this->assertSame([5, 2, 4][$i], (int)$record->stars);
                $this->assertSame(100 + $i, (int)$record->timecreated);
                $this->assertSame((int)$question->id, (int)$record->questionid);
                $this->assertNull($record->cookiehash);
            }
            $this->assertTrue($dbman->index_exists(
                $table,
                new \xmldb_index('question_cookie', XMLDB_INDEX_UNIQUE, ['questionid', 'cookiehash'])
            ));
            $this->assertSame('Mathematics', $DB->get_field('block_fragenfilter_tag_pending', 'tags', ['id' => $pendingid]));
            $this->assertTrue($DB->record_exists('question', ['id' => $question->id]));
            $this->assertTrue($DB->record_exists('user', ['id' => $user->id]));
            $this->assertSame('2026101605', get_config('block_fragenfilter', 'version'));
            // A retried upgrade leaves the schema and historical votes unchanged.
            set_config('version', 2026101604, 'block_fragenfilter');
            xmldb_block_fragenfilter_upgrade(2026101604);
            $this->assertEquals($records, array_values($DB->get_records('block_fragenfilter_ratings', null, 'id')));
        } finally {
            $dbman->drop_table($table);
            $file = new \xmldb_file($CFG->dirroot . '/blocks/fragenfilter/db/install.xml');
            $file->loadXMLStructure();
            $dbman->create_table($file->getStructure()->getTable('block_fragenfilter_ratings'));
        }
    }
}
