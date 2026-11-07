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

/**
 * Authorises selected questions and stores one visitor's export.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_export {
    /**
     * Returns the enabled, supported export formats.
     *
     * @return string[] Formats.
     */
    public static function enabled_formats(): array {
        $config = get_config('block_fragenfilter', 'exportformats');
        if ($config === false) {
            return ['xml', 'csv', 'gift'];
        }
        $formats = is_array($config) ? array_keys(array_filter($config)) : explode(',', $config);
        return array_values(array_intersect(['xml', 'csv', 'gift'], $formats));
    }

    /**
     * Creates an export after the external API has checked login and export capability.
     *
     * @param string $questionids Selected question IDs.
     * @param string $format Requested format.
     * @param int $contextid Caller context.
     * @return array File URL and metadata.
     */
    public static function create(string $questionids, string $format, int $contextid): array {
        global $CFG, $DB, $USER, $SESSION;

        if (!preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $questionids)) {
            throw new \invalid_parameter_exception('Expected positive question IDs.');
        }
        $ids = array_unique(array_map('intval', explode(',', $questionids)));
        if (count($ids) > 200) {
            throw new \invalid_parameter_exception('At most 200 questions may be exported.');
        }
        if (!in_array($format, self::enabled_formats(), true)) {
            throw new \moodle_exception('exportformatdisabled', 'block_fragenfilter');
        }
        [$visibilitysql, $args] = question_visibility::sql('all', $contextid);
        [$insql, $idargs] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'exportid');
        $allowed = $DB->get_fieldset_sql(
            "SELECT q.id FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE q.id $insql AND q.parent = 0 AND qv.status = 'ready' AND $visibilitysql",
            $args + $idargs
        );
        if (count($allowed) !== count($ids)) {
            throw new \moodle_exception('invalidquestion', 'block_fragenfilter');
        }

        require_once($CFG->libdir . '/questionlib.php');

        switch ($format) {
            case 'csv':
                $content = self::export_csv($ids);
                $filename = 'questions_' . date('Ymd_His') . '.csv';
                $mimetype = 'text/csv';
                break;
            case 'gift':
                $content = self::export_gift($ids);
                $filename = 'questions_' . date('Ymd_His') . '.txt';
                $mimetype = 'text/plain';
                break;
            default:
                $content = self::export_moodle_xml($ids);
                $filename = 'questions_' . date('Ymd_His') . '.xml';
                $mimetype = 'application/xml';
                break;
        }

        // Store the file in Moodle's temporary area and return its URL.
        $fs = get_file_storage();
        $sysctx = \context_system::instance();
        $fs->delete_area_files($sysctx->id, 'block_fragenfilter', 'export', (int)$USER->id);

        // Pool accounts are reused; an export belongs to this browser session as well as its account.
        if (empty($SESSION->fragenfilter_export_token)) {
            $SESSION->fragenfilter_export_token = bin2hex(random_bytes(16));
        }
        $fileinfo = [
            'contextid' => $sysctx->id,
            'userid' => $USER->id,
            'source' => $SESSION->fragenfilter_export_token,
            'component' => 'block_fragenfilter',
            'filearea' => 'export',
            'itemid' => (int)$USER->id,
            'filepath' => '/',
            'filename' => $filename,
        ];
        $fs->create_file_from_string($fileinfo, $content);
        $url = \moodle_url::make_pluginfile_url(
            $sysctx->id,
            'block_fragenfilter',
            'export',
            (int)$USER->id,
            '/',
            $filename
        )->out(false);

        return [
            'success' => true,
            'url' => $url,
            'filename' => $filename,
            'mimetype' => $mimetype,
            'count' => count($ids),
        ];
    }

    /**
     * Exports Moodle XML through the native \qformat_xml engine.
     *
     * \qformat_xml::exportprocess() expects questions in the old stdClass format
     * (as read from the DB), not question_definition objects from load_question().
     * get_question_options() loads answers, hints and subquestions (multianswer)
     * directly into the stdClass, as required for export.
     *
     * @param array $ids Question IDs.
     * @return string Export file contents.
     */
    private static function export_moodle_xml(array $ids): string {
        global $CFG, $DB;

        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        // Load questions as raw DB records (stdClass, not question_definition).
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

        if (empty($rows)) {
            return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n</quiz>\n";
        }

        // Get_question_options() populates each question with answers, hints,
        // and, for qtype_multianswer, subquestions as stdClass objects.
        // qtype must explicitly be a string because \qformat_xml internally
        // calls (string)$q->qtype, which throws a PHP exception for objects.
        $sysctx = \context_system::instance();
        $questions = [];
        foreach ($rows as $q) {
            $q->qtype = (string)$q->qtype; // Ensure qtype is a string.
            $q->category = (int)$q->category;
            get_question_options($q); // Populate $q with answers and hints.
            // Also normalise multianswer subquestions.
            if ($q->qtype === 'multianswer' && !empty($q->options->questions)) {
                foreach ($q->options->questions as $sub) {
                    if (is_object($sub)) {
                        $sub->qtype = (string)($sub->qtype ?? 'shortanswer');
                    }
                }
            }
            $questions[] = $q;
        }

        // Call writequestion() for each question instead of exportprocess().
        // exportprocess() reads category contexts from the DB and internally calls
        // context::instance_by_id(), which throws an exception for invalid context IDs.
        // Calling writequestion() per question avoids that code path
        // entirely.
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

        $content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n"
            . implode('', $xmlparts)
            . "</quiz>\n";

        return $content;
    }

    /**
     * Exports CSV: ID, name, type, category, tags.
     * RFC 4180-compliant quoting (escape double quotes).
     *
     * @param array $ids Question IDs.
     * @return string Export file contents.
     */
    private static function export_csv(array $ids): string {
        global $DB;

        [$insql, $args] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'q');
        $questions = $DB->get_records_sql(
            "SELECT q.id, q.name, q.qtype, qc.name AS categoryname
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE q.id $insql",
            $args
        );

        $csvquote = function (string $s): string {
            return '"' . str_replace('"', '""', $s) . '"';
        };

        $lines = [implode(',', array_map($csvquote, ['ID', 'Name', 'Typ', 'Kategorie', 'Tags']))];
        foreach ($questions as $q) {
            $tagstr = implode('; ', \core_tag_tag::get_item_tags_array('core_question', 'question', $q->id));
            $lines[] = implode(',', [
                $csvquote((string)$q->id),
                $csvquote($q->name),
                $csvquote($q->qtype),
                $csvquote($q->categoryname),
                $csvquote($tagstr),
            ]);
        }
        return implode("\r\n", $lines);
    }

    /**
     * Exports GIFT through Moodle's native \qformat_gift engine.
     * Uses raw DB records (stdClass), like the XML export,
     * because \qformat_gift::writequestion() expects stdClass, not question_definition.
     *
     * @param array $ids Question IDs.
     * @return string Export file contents.
     */
    private static function export_gift(array $ids): string {
        global $CFG, $DB;

        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/gift/format.php');
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
