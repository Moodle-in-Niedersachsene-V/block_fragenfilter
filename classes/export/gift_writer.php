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

namespace block_fragenfilter\export;

/**
 * GIFT through Moodle's native \qformat_gift engine.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class gift_writer implements writer {
    #[\Override]
    public function extension(): string {
        return 'txt';
    }

    #[\Override]
    public function mimetype(): string {
        return 'text/plain';
    }

    #[\Override]
    public function write(array $ids): string {
        global $CFG, $DB;

        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/gift/format.php');

        $rows = [];
        if ($ids) {
            [$insql, $args] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'qid');
            $rows = $DB->get_records_sql(
                "SELECT q.*, qc.id AS category, qc.contextid
                   FROM {question} q
                   JOIN {question_versions} qv ON qv.questionid = q.id
                   JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                   JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                  WHERE q.id $insql AND q.parent = 0",
                $args
            );
        }

        $qformat = new \qformat_gift();
        $qformat->setContexts([\context_system::instance()]);
        $qformat->setCattofile(false);
        $qformat->setContexttofile(false);
        $output = '// GIFT Export – ' . date('Y-m-d H:i:s') . "\n\n";
        foreach ($rows as $q) {
            get_question_options($q);
            $line = $qformat->writequestion($q);
            if (!$line) {
                throw new \moodle_exception('exportunsupportedquestion', 'block_fragenfilter');
            }
            $output .= $line . "\n";
        }
        return $output;
    }
}
