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

/**
 * Export writers: one per format, reached through question_export::writer().
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(question_export::class)]
final class export_writer_test extends \advanced_testcase {
    /**
     * Every format names its file extension and MIME type.
     */
    public function test_each_format_declares_extension_and_mimetype(): void {
        $this->resetAfterTest();
        $expected = [
            'xml' => ['xml', 'application/xml'],
            'csv' => ['csv', 'text/csv'],
            'gift' => ['txt', 'text/plain'],
        ];
        foreach ($expected as $format => [$extension, $mimetype]) {
            $writer = question_export::writer($format);
            $this->assertInstanceOf(export\writer::class, $writer);
            $this->assertSame($extension, $writer->extension());
            $this->assertSame($mimetype, $writer->mimetype());
        }
    }

    /**
     * Writers without questions produce just their file frame.
     */
    public function test_writers_without_questions_produce_their_frame(): void {
        $this->resetAfterTest();
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n</quiz>\n",
            question_export::writer('xml')->write([])
        );
        $this->assertSame(
            '"ID","Name","Typ","Kategorie","Tags"',
            question_export::writer('csv')->write([])
        );
    }

    /**
     * Unknown formats are rejected before any writer runs.
     */
    public function test_unknown_format_has_no_writer(): void {
        $this->resetAfterTest();
        $this->expectException(\invalid_parameter_exception::class);
        question_export::writer('pdf');
    }
}
