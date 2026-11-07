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
 * Moodle XML through the native \qformat_xml engine.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class xml_writer implements writer {
    #[\Override]
    public function extension(): string {
        return 'xml';
    }

    #[\Override]
    public function mimetype(): string {
        return 'application/xml';
    }

    #[\Override]
    public function write(array $ids): string {
        global $CFG, $DB;

        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        // Load questions as raw DB records (stdClass, not question_definition).
        $rows = [];
        if ($ids) {
            [$insql, $args] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'qid');
            $rows = $DB->get_records_sql(
                "SELECT q.*, qc.id AS category, qc.contextid
                   FROM {question} q
                   JOIN {question_versions} qv  ON qv.questionid        = q.id
                   JOIN {question_bank_entries} qbe ON qbe.id           = qv.questionbankentryid
                   JOIN {question_categories} qc    ON qc.id            = qbe.questioncategoryid
                  WHERE q.id $insql
                    AND q.parent = 0",
                $args
            );
        }

        if (empty($rows)) {
            return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n</quiz>\n";
        }

        // Populate each question with answers, hints,
        // and, for qtype_multianswer, subquestions as stdClass objects.
        // qtype must explicitly be a string because \qformat_xml internally
        // calls (string)$q->qtype, which throws a PHP exception for objects.
        $sysctx = \context_system::instance();
        $questions = [];
        foreach ($rows as $q) {
            $q->qtype = (string)$q->qtype;
            $q->category = (int)$q->category;
            get_question_options($q);
            if ($q->qtype === 'multianswer' && !empty($q->options->questions)) {
                foreach ($q->options->questions as $sub) {
                    if (is_object($sub)) {
                        $sub->qtype = (string)($sub->qtype ?? 'shortanswer');
                    }
                }
            }
            $questions[] = $q;
        }

        // One writequestion() call per question avoids exportprocess(), which
        // reads category contexts from the DB and throws for invalid context IDs.
        $qformat = new \qformat_xml();
        $qformat->setContexts([$sysctx]);
        $qformat->setCattofile(false);
        $qformat->setContexttofile(false);

        $xmlparts = [];
        foreach ($questions as $q) {
            $part = $qformat->writequestion($q);
            if ($part === false || $part === '') {
                throw new \moodle_exception('exportunsupportedquestion', 'block_fragenfilter');
            }
            $xmlparts[] = $part;
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n"
            . implode('', $xmlparts)
            . "</quiz>\n";
    }
}
