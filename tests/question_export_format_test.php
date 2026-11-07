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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

/**
 * Characterisation of the export file contents per format (byte-compared to fixtures).
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(question_export::class)]
final class question_export_format_test extends \advanced_testcase {
    /**
     * Each format produces the stored golden output, with IDs and timestamps normalised.
     */
    public function test_each_format_matches_its_golden_output(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['name' => 'Fixture']);
        $questions = [
            $generator->create_question('shortanswer', null, [
                'category' => $category->id, 'name' => 'Kurzantwort "Hauptstadt"',
                'questiontext' => 'Hauptstadt von Niedersachsen?',
            ]),
            $generator->create_question('multichoice', null, [
                'category' => $category->id, 'name' => 'Auswahl', 'questiontext' => 'Welche Zahl ist gerade?',
            ]),
            $generator->create_question('truefalse', null, [
                'category' => $category->id, 'name' => 'Wahr', 'questiontext' => 'Die Erde ist rund.',
            ]),
        ];
        \core_tag_tag::set_item_tags(
            'core_question',
            'question',
            $questions[0]->id,
            \context_system::instance(),
            ['Alpha', 'Beta']
        );
        $ids = array_map(fn($q) => (int)$q->id, $questions);
        $idlist = implode(',', $ids);
        $normalise = function (string $content) use ($ids): string {
            $content = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', '{time}', $content);
            foreach ($ids as $n => $id) {
                $content = preg_replace('/(?<![0-9])' . $id . '(?![0-9])/', '{id' . $n . '}', $content);
            }
            return $content;
        };
        $fs = get_file_storage();
        $sysctx = \context_system::instance();
        $actuals = [];
        foreach (['xml', 'csv', 'gift'] as $format) {
            $result = question_export::create($idlist, $format, $sysctx->id);
            $file = $fs->get_file($sysctx->id, 'block_fragenfilter', 'export', (int)$USER->id, '/', $result['filename']);
            $this->assertNotFalse($file);
            $actuals[$format] = $normalise($file->get_content());
        }
        foreach ($actuals as $format => $content) {
            $golden = file_get_contents(__DIR__ . '/fixtures/export/golden.' . $format);
            $this->assertSame($golden, $content, "Format $format differs from its golden output.");
        }
    }
}
