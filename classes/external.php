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
 * Web service functions for block_fragenfilter.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @author     Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Web service functions for the question bank filter.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_fragenfilter_external extends external_api {
    /** Maximum requests per user within the rate limit window. */
    const RATE_LIMIT_REQUESTS = 30;

    /** Rate limit window length in seconds. */
    const RATE_LIMIT_WINDOW = 60;

    /** Absolute maximum number of returned results (DoS protection). */
    const MAX_RESULT_LIMIT = 200;

    /** Default result limit when the caller does not provide one. */
    const DEFAULT_RESULT_LIMIT = 200;

    /** Maximum number of distinct question IDs per get_ratings call, matching the search limit. */
    const MAX_RATING_IDS = self::MAX_RESULT_LIMIT;

    /** @var string|null Rating cookie shared by all calls in the current AJAX batch. */
    private static ?string $ratingcookie = null;

    /**
     * Checks the sliding rate limit for the given user at the caller's network address.
     *
     * Throws a moodle_exception if the user has made more than RATE_LIMIT_REQUESTS
     * requests within RATE_LIMIT_WINDOW seconds. The bucket belongs to the account and the
     * address together, so anonymous visitors (user 0) and reused pool accounts do not share one.
     *
     * @param int $userid Moodle user ID.
     * @throws \moodle_exception If the rate limit is exceeded.
     */
    private static function check_rate_limit(int $userid): void {
        $cache = \cache::make('block_fragenfilter', 'ratelimit');
        $key   = 'rl_' . hash('sha256', $userid . '|' . getremoteaddr());
        $now   = time();
        $data  = $cache->get($key);

        if ($data === false || ($now - $data['window_start']) >= self::RATE_LIMIT_WINDOW) {
            $cache->set($key, ['count' => 1, 'window_start' => $now]);
            return;
        }

        $data['count']++;
        $cache->set($key, $data);

        if ($data['count'] > self::RATE_LIMIT_REQUESTS) {
            $remaining = self::RATE_LIMIT_WINDOW - ($now - $data['window_start']);
            throw new \moodle_exception('ratelimitexceeded', 'block_fragenfilter', '', (object)[
                'seconds' => max(1, (int)$remaining),
            ]);
        }
    }

    /**
     * Return a SQL fragment and parameter array that restrict a question query to
     * mod_qbank activities in courses belonging to the Aufgabenpool category tree.
     *
     * The fragment must be added to the WHERE clause of a query that already joins
     * {question_categories} as "qc". It adds the necessary extra JOINs via a
     * sub-select so it can be safely appended with AND.
     *
     * Uses the category's stored path column (e.g. "/1/17") to match the category
     * itself and all its descendants with a single LIKE, which handles arbitrary
     * nesting depth and avoids false positives from identically-named sibling
     * categories.
     *
     * @return array{string, array<string,mixed>} [sql_fragment, params]
     */
    private static function pool_category_sql(): array {
        global $DB;

        $poolcatname = \block_fragenfilter\pool_manager::POOL_ENROL_CATEGORY;
        $poolcat     = $DB->get_record('course_categories', ['name' => $poolcatname], 'id,path', IGNORE_MISSING);

        if (!$poolcat) {
            // No Aufgabenpool category found — return a fragment that matches nothing
            // so the filter is safely restrictive rather than leaking all questions.
            return ['1 = 0', []];
        }

        $pathprefix = $DB->sql_like_escape($poolcat->path);

        $sql = "EXISTS (
            SELECT 1
              FROM {course_modules} cm2
              JOIN {modules} m2 ON m2.id = cm2.module AND m2.name = 'qbank'
              JOIN {context} ctx2 ON ctx2.instanceid = cm2.id AND ctx2.contextlevel = :poolctxlvl
              JOIN {course} c2 ON c2.id = cm2.course
              JOIN {course_categories} cat2 ON cat2.id = c2.category
             WHERE ctx2.id = qc.contextid
               AND (" . $DB->sql_like('cat2.path', ':poolexact') . "
                OR " . $DB->sql_like('cat2.path', ':poolsub') . ")
        )";

        $params = [
            'poolctxlvl' => CONTEXT_MODULE,
            'poolexact'  => $pathprefix,
            'poolsub'    => $pathprefix . '/%',
        ];

        return [$sql, $params];
    }

    /**
     * Validates the caller context without retaining an anonymous guest login.
     *
     * @param int $contextid Caller context.
     * @return context Validated context.
     */
    private static function validate_search_context(int $contextid): context {
        global $USER;

        $context = context::instance_by_id($contextid, MUST_EXIST);
        $anonymous = !isloggedin();
        $originaluser = clone $USER;
        if ($anonymous) {
            \core\session\manager::set_user(guest_user());
        }
        try {
            self::validate_context($context);
            if (!has_capability('block/fragenfilter:view', context_system::instance())) {
                throw new moodle_exception('nopermission', 'block_fragenfilter');
            }
        } finally {
            if ($anonymous) {
                \core\session\manager::set_user($originaluser);
            }
        }
        return $context;
    }

    /**
     * Describes the search parameters.
     *
     * @return external_function_parameters Parameter definition.
     */
    public static function search_questions_parameters(): external_function_parameters {
        return new external_function_parameters([
            'search' => new external_value(PARAM_TEXT, 'Search term', VALUE_DEFAULT, ''),
            'categories' => new external_value(PARAM_TEXT, 'Comma-separated category IDs (empty = all)', VALUE_DEFAULT, ''),
            'types' => new external_value(PARAM_TEXT, 'Comma-separated question types', VALUE_DEFAULT, ''),
            'difficulty' => new external_value(PARAM_TEXT, 'Difficulty level (tag)', VALUE_DEFAULT, ''),
            'tags' => new external_value(PARAM_TEXT, 'Comma-separated tags', VALUE_DEFAULT, ''),
            'scope' => new external_value(PARAM_ALPHA, 'all|course|system', VALUE_DEFAULT, 'all'),
            'contextid' => new external_value(PARAM_INT, 'Current context', VALUE_DEFAULT, 1),
            'limit' => new external_value(PARAM_INT, 'Maximum results', VALUE_DEFAULT, 200),
        ]);
    }

    /**
     * Searches questions using the given filters.
     *
     * Context and block access are checked by validate_search_context().
     * @param string $search Free-text search term.
     * @param string $categories Comma-separated category IDs; empty means all.
     * @param string $types Comma-separated question types.
     * @param string $difficulty Difficulty level as a tag.
     * @param string $tags Comma-separated tags.
     * @param string $scope Search scope: all, course or system.
     * @param int $contextid Current context ID.
     * @param int $limit Maximum number of results.
     * @return array Matching questions.
     * nosemgrep: moodle-external-ohne-kontextpruefung */
    public static function search_questions(
        string $search,
        string $categories,
        string $types,
        string $difficulty,
        string $tags,
        string $scope,
        int $contextid,
        int $limit
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::search_questions_parameters(), [
            'search' => $search,
            'categories' => $categories,
            'types' => $types,
            'difficulty' => $difficulty,
            'tags' => $tags,
            'scope' => $scope,
            'contextid' => $contextid,
            'limit' => $limit,
        ]);

        // Cap the limit at the configured maximum (DoS protection).
        $maxlimit = max(1, min(self::MAX_RESULT_LIMIT, (int)(get_config('block_fragenfilter', 'resultlimit') ?: 200)));
        if ($params['limit'] < 1 || $params['limit'] > $maxlimit) {
            $params['limit'] = $maxlimit;
        }

        $context = self::validate_search_context($params['contextid']);

        // Rate limit: at most RATE_LIMIT_REQUESTS per user within RATE_LIMIT_WINDOW seconds.
        self::check_rate_limit((int)$USER->id);

        [$visibilitysql, $ctxargs] = \block_fragenfilter\question_visibility::sql($params['scope'], $context->id);
        [$poolsql, $poolargs]     = self::pool_category_sql();

        // Base query joining question and question_categories.
        // Results are restricted to mod_qbank activities in Aufgabenpool courses so that
        // questions stored in download courses (under the "Kurse" category tree, managed
        // by block_kursfilter) are never surfaced here.
        $sql = "SELECT DISTINCT q.id, q.name, q.qtype, q.createdby,
                       qc.id AS categoryid, qc.name AS categoryname,
                       qc.contextid AS categorycontextid
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE q.parent = 0
                   AND qv.status = 'ready'
                   AND $visibilitysql
                   AND $poolsql";

        $queryargs = array_merge($ctxargs, $poolargs);

        // Free-text search: question title OR tags.
        if (!empty($params['search'])) {
            $searchparam = '%' . $DB->sql_like_escape(str_replace('!', '!!', $params['search']), '!') . '%';
            $sql .= " AND (
                " . $DB->sql_like('q.name', ':searchname', false, true, false, '!') . "
                OR EXISTS (
                    SELECT 1
                      FROM {tag_instance} ti
                      JOIN {tag} t ON t.id = ti.tagid
                     WHERE ti.itemid = q.id
                       AND ti.itemtype = 'question'
                       AND ti.component = 'core_question'
                       AND " . $DB->sql_like('t.name', ':searchtag', false, true, false, '!') . "
                )
            )";
            $queryargs['searchname'] = $searchparam;
            $queryargs['searchtag']  = $searchparam;
        }

        // Question type filter.
        if (!empty($params['types'])) {
            $typelist = array_filter(array_map('trim', explode(',', $params['types'])));
            if ($typelist) {
                [$typesql, $typeargs] = $DB->get_in_or_equal($typelist, SQL_PARAMS_NAMED, 'type');
                $sql .= " AND q.qtype $typesql";
                $queryargs = array_merge($queryargs, $typeargs);
            }
        }

        // Category filter.
        if (!empty($params['categories'])) {
            if (!preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $params['categories'])) {
                throw new invalid_parameter_exception('Expected positive category IDs.');
            }
            $catids = array_map('intval', explode(',', $params['categories']));
            if ($catids) {
                [$catsql, $catargs] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'cat');
                $sql .= " AND qbe.questioncategoryid $catsql";
                $queryargs = array_merge($queryargs, $catargs);
            }
        }

        $alltags = array_filter(array_map('trim', explode(',', $params['tags'])));
        if (!empty($params['difficulty'])) {
            $alltags[] = trim($params['difficulty']);
        }
        foreach (array_values($alltags) as $index => $tag) {
            $placeholder = 'filtertag' . $index;
            $sql .= " AND EXISTS (
                SELECT 1 FROM {tag_instance} ti JOIN {tag} t ON t.id = ti.tagid
                 WHERE ti.itemid = q.id AND ti.itemtype = 'question' AND ti.component = 'core_question'
                   AND " . $DB->sql_like('t.name', ':' . $placeholder, false, true, false, '!') . ")";
            $queryargs[$placeholder] = '%' . $DB->sql_like_escape(str_replace('!', '!!', $tag), '!') . '%';
        }
        $sql .= " ORDER BY qc.name, q.name";
        $rows = $DB->get_records_sql($sql, $queryargs, 0, $params['limit']);
        $questions = [];
        foreach ($rows as $row) {
            $qtags = self::get_question_tags((int)$row->id);

            $questions[] = [
                'id' => (int)$row->id,
                'name' => $row->name,
                'qtype' => $row->qtype,
                'categoryid' => (int)$row->categoryid,
                'categoryname' => $row->categoryname,
                'contextid' => (int)$row->categorycontextid,
                'tags' => $qtags,
            ];
        }

        return [
            'questions' => $questions,
            'total' => count($questions),
        ];
    }

    /**
     * Describes the search return values.
     *
     * @return external_single_structure Return definition.
     */
    public static function search_questions_returns(): external_single_structure {
        return new external_single_structure([
            'questions' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT),
                    'name' => new external_value(PARAM_TEXT),
                    'qtype' => new external_value(PARAM_ALPHA),
                    'categoryid' => new external_value(PARAM_INT),
                    'categoryname' => new external_value(PARAM_TEXT),
                    'contextid' => new external_value(PARAM_INT),
                    'tags' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT),
                            'name' => new external_value(PARAM_TEXT),
                        ])
                    ),
                ])
            ),
            'total' => new external_value(PARAM_INT),
        ]);
    }

    /**
     * Describes the parameters for loading categories.
     *
     * @return external_function_parameters Parameter definition.
     */
    public static function get_categories_parameters(): external_function_parameters {
        return new external_function_parameters([
            'scope' => new external_value(PARAM_ALPHA, 'all|course|system', VALUE_DEFAULT, 'all'),
            'contextid' => new external_value(PARAM_INT, 'Current context', VALUE_DEFAULT, 1),
        ]);
    }

    /**
     * Loads all searchable question categories.
     *
     * Context and block access are checked by validate_search_context().
     * @param string $scope Search scope: all, course or system.
     * @param int $contextid Current context ID.
     * @return array Categories.
     * nosemgrep: moodle-external-ohne-kontextpruefung */
    public static function get_categories(string $scope, int $contextid): array {
        global $DB;

        $params = self::validate_parameters(
            self::get_categories_parameters(),
            ['scope' => $scope, 'contextid' => $contextid]
        );
        $context = self::validate_search_context($params['contextid']);

        [$visibilitysql, $ctxargs] = \block_fragenfilter\question_visibility::sql($params['scope'], $context->id);
        [$poolsql, $poolargs]     = self::pool_category_sql();
        $rows = $DB->get_records_sql(
            "SELECT qc.id, qc.name, qc.contextid, ctx.contextlevel,
                    ctx.instanceid, COUNT(q.id) AS questioncount
               FROM {question_categories} qc
               JOIN {context} ctx ON ctx.id = qc.contextid
               JOIN {question_bank_entries} qbe ON qbe.questioncategoryid = qc.id
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {question} q ON q.id = qv.questionid
              WHERE $visibilitysql AND q.parent = 0 AND qv.status = 'ready'
                AND $poolsql
           GROUP BY qc.id, qc.name, qc.contextid, ctx.contextlevel, ctx.instanceid
           ORDER BY qc.name",
            array_merge($ctxargs, $poolargs)
        );

        $cats = [];
        foreach ($rows as $r) {
            $label = self::context_label((int)$r->contextlevel, (int)$r->instanceid);
            $cats[] = [
                'id' => (int)$r->id,
                'name' => $r->name,
                'contextlabel' => $label,
                'questioncount' => (int)$r->questioncount,
            ];
        }
        return ['categories' => $cats];
    }

    /**
     * Describes the return values for loading categories.
     *
     * @return external_single_structure Return definition.
     */
    public static function get_categories_returns(): external_single_structure {
        return new external_single_structure([
            'categories' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT),
                    'name' => new external_value(PARAM_TEXT),
                    'contextlabel' => new external_value(PARAM_TEXT),
                    'questioncount' => new external_value(PARAM_INT),
                ])
            ),
        ]);
    }

    /**
     * Describes the parameters for loading question types.
     *
     * @return external_function_parameters Parameter definition.
     */
    public static function get_questiontypes_parameters(): external_function_parameters {
        return new external_function_parameters([
            'scope' => new external_value(PARAM_ALPHA, 'all|course|system', VALUE_DEFAULT, 'all'),
            'contextid' => new external_value(PARAM_INT, 'Current context', VALUE_DEFAULT, 1),
            'source' => new external_value(PARAM_ALPHA, 'existing|installed', VALUE_DEFAULT, 'installed'),
        ]);
    }

    /**
     * Loads the available question types.
     *
     * Context and block access are checked by validate_search_context().
     * @param string $scope Search scope: all, course or system.
     * @param int $contextid Current context ID.
     * @param string $source Source: installed or existing.
     * @return array Question types.
     * nosemgrep: moodle-external-ohne-kontextpruefung */
    public static function get_questiontypes(string $scope, int $contextid, string $source = 'installed'): array {
        global $DB, $CFG;

        $params = self::validate_parameters(
            self::get_questiontypes_parameters(),
            ['scope' => $scope, 'contextid' => $contextid, 'source' => $source]
        );
        $context = self::validate_search_context($params['contextid']);

        if (
            !in_array($params['scope'], ['all', 'course', 'system'], true) ||
                !in_array($params['source'], ['installed', 'existing'], true)
        ) {
            throw new invalid_parameter_exception('Unknown search scope or question type source.');
        }

        $qtypes = [];

        if ($params['source'] === 'installed') {
            // Load all installed qtype plugins.
            $plugintypes = core_component::get_plugin_list('qtype');
            foreach ($plugintypes as $key => $path) {
                // Skip system types.
                if (in_array($key, ['missingtype', 'unknowntype'])) {
                    continue;
                }
                $plugin = 'qtype_' . $key;
                if (get_string_manager()->string_exists('pluginname', $plugin)) {
                    $label = get_string('pluginname', $plugin);
                } else {
                    $label = ucfirst($key);
                }
                $qtypes[$key] = $label;
            }
        } else {
            // Only types actually present in the question bank.
            [$visibilitysql, $ctxargs] = \block_fragenfilter\question_visibility::sql($params['scope'], $context->id);
            $rows = $DB->get_records_sql(
                "SELECT DISTINCT q.qtype
                   FROM {question} q
                   JOIN {question_versions} qv       ON qv.questionid        = q.id
                   JOIN {question_bank_entries} qbe   ON qbe.id              = qv.questionbankentryid
                   JOIN {question_categories} qc      ON qc.id               = qbe.questioncategoryid
                  WHERE $visibilitysql
                    AND q.parent = 0
                    AND qv.status = 'ready'
               ORDER BY q.qtype",
                $ctxargs
            );
            foreach ($rows as $r) {
                $plugin = 'qtype_' . $r->qtype;
                $label = get_string_manager()->string_exists('pluginname', $plugin)
                    ? get_string('pluginname', $plugin) : ucfirst($r->qtype);
                $qtypes[$r->qtype] = $label;
            }
        }

        // Sort alphabetically by display name.
        asort($qtypes);

        $result = [];
        foreach ($qtypes as $key => $label) {
            $result[] = ['key' => $key, 'label' => $label];
        }
        return ['qtypes' => $result];
    }

    /**
     * Describes the return values for loading question types.
     *
     * @return external_single_structure Return definition.
     */
    public static function get_questiontypes_returns(): external_single_structure {
        return new external_single_structure([
            'qtypes' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_ALPHANUMEXT),
                    'label' => new external_value(PARAM_TEXT),
                ])
            ),
        ]);
    }
    /**
     * Describes the export parameters.
     *
     * @return external_function_parameters Parameter definition.
     */
    public static function export_questions_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionids' => new external_value(PARAM_TEXT, 'Comma-separated question IDs'),
            'format' => new external_value(PARAM_ALPHA, 'xml|csv|gift', VALUE_DEFAULT, 'xml'),
            'contextid' => new external_value(PARAM_INT, 'Context', VALUE_DEFAULT, 1),
        ]);
    }

    /**
     * Exports the selected questions in the requested format.
     *
     * @param string $questionids Comma-separated question IDs.
     * @param string $format Target format: xml, csv or gift.
     * @param int $contextid Current context ID.
     * @return array Export file information.
     */
    public static function export_questions(string $questionids, string $format, int $contextid): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::export_questions_parameters(), [
            'questionids' => $questionids,
            'format' => $format,
            'contextid' => $contextid,
        ]);

        // Use the system context directly because exports are always site-wide.
        // context::instance_by_id() would throw an exception for contextid=0.
        $context = context_system::instance();
        self::validate_context($context);

        // Export requires a dedicated capability, unavailable to guests.
        if (!isloggedin() || isguestuser() || !has_capability('block/fragenfilter:export', context_system::instance())) {
            throw new moodle_exception('nopermission', 'block_fragenfilter');
        }

        return \block_fragenfilter\question_export::create($params['questionids'], $params['format'], $params['contextid']);
    }

    /**
     * Describes the export return values.
     *
     * @return external_single_structure Return definition.
     */
    public static function export_questions_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL),
            'url' => new external_value(PARAM_URL),
            'filename' => new external_value(PARAM_FILE),
            'mimetype' => new external_value(PARAM_TEXT),
            'count' => new external_value(PARAM_INT),
        ]);
    }

    /**
     * Loads a question's tags.
     *
     * @param int $questionid Question ID.
     * @return array Tags.
     */
    private static function get_question_tags(int $questionid): array {
        global $DB;

        $sql = "SELECT t.id, t.name
                  FROM {tag} t
                  JOIN {tag_instance} ti ON ti.tagid = t.id
                 WHERE ti.itemtype = 'question'
                   AND ti.itemid = :qid
                   AND ti.component = 'core_question'";
        $rows = $DB->get_records_sql($sql, ['qid' => $questionid]);
        $result = [];
        foreach ($rows as $r) {
            $result[] = ['id' => (int)$r->id, 'name' => $r->name];
        }
        return $result;
    }

    /**
     * Returns a readable label for a category's context.
     * For mod_qbank instances (Moodle 5), the actual question bank
     * name is read from the qbank instance table.
     *
     * @param int $contextlevel Context level.
     * @param int $instanceid Context instance ID.
     * @return string Readable label.
     */
    private static function context_label(int $contextlevel, int $instanceid): string {
        global $DB;
        switch ($contextlevel) {
            case CONTEXT_SYSTEM:
                return get_string('coresystem', 'block_fragenfilter');

            case CONTEXT_COURSECAT:
                $name = $DB->get_field('course_categories', 'name', ['id' => $instanceid]);
                return $name ? 'Kategorie: ' . $name : 'Kategorie #' . $instanceid;

            case CONTEXT_COURSE:
                $name = $DB->get_field('course', 'fullname', ['id' => $instanceid]);
                return $name ?: 'Kurs #' . $instanceid;

            case CONTEXT_MODULE:
                // Load the course module.
                $cm = $DB->get_record('course_modules', ['id' => $instanceid], 'id,course,module,instance');
                if (!$cm) {
                    return 'Modul #' . $instanceid;
                }

                $coursename = $DB->get_field('course', 'shortname', ['id' => $cm->course]) ?: 'Kurs';

                // Determine the module type.
                $modname = $DB->get_field('modules', 'name', ['id' => $cm->module]);

                if ($modname === 'qbank') {
                    // Read the question bank instance name from mod_qbank.
                    $bankname = $DB->get_field('qbank', 'name', ['id' => $cm->instance]);
                    if ($bankname) {
                        return $coursename . ' › ' . $bankname;
                    }
                } else if ($modname === 'quiz') {
                    $quizname = $DB->get_field('quiz', 'name', ['id' => $cm->instance]);
                    if ($quizname) {
                        return $coursename . ' › Quiz: ' . $quizname;
                    }
                }

                // Fallback: the course name is sufficient.
                return $coursename;

            default:
                return 'Kontext #' . $instanceid;
        }
    }

    // ---------------------------------------------------------------
    // Check_capabilities: check the current user's capabilities.

    /**
     * Parameters for check_capabilities.
     */
    public static function check_capabilities_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Context', VALUE_DEFAULT, 1),
        ]);
    }

    /**
     * Returns the logged-in user's current capabilities.
     * Called via AJAX after pool login so JS can show or hide
     * the export buttons accordingly.
     *
     * Context and block access are checked by validate_search_context().
     * @param int $contextid Context ID.
     * @return array Capabilities as a boolean map.
     * nosemgrep: moodle-external-ohne-kontextpruefung */
    public static function check_capabilities(int $contextid): array {
        $params  = self::validate_parameters(
            self::check_capabilities_parameters(),
            ['contextid' => $contextid]
        );

        self::validate_search_context($params['contextid']);
        $sysctx = context_system::instance();

        $formats = implode(',', \block_fragenfilter\question_export::enabled_formats());

        return [
            'canexport'     => isloggedin() && !isguestuser() && has_capability('block/fragenfilter:export', $sysctx),
            'canpreview'    => isloggedin() && !isguestuser(),
            'isloggedin'    => isloggedin() && !isguestuser(),
            'exportformats' => $formats,
        ];
    }

    /**
     * Return structure for check_capabilities.
     */
    public static function check_capabilities_returns(): external_single_structure {
        return new external_single_structure([
            'canexport'     => new external_value(PARAM_BOOL),
            'canpreview'    => new external_value(PARAM_BOOL),
            'isloggedin'    => new external_value(PARAM_BOOL),
            'exportformats' => new external_value(PARAM_TEXT, 'Comma-separated export formats', VALUE_DEFAULT, 'xml,csv,gift'),
        ]);
    }

    // ---------------------------------------------------------------
    // Rate_question: save a star rating (logged-in users and guests).

    /**
     * Parameters for rate_question.
     */
    public static function rate_question_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionid'  => new external_value(PARAM_INT, 'Question ID'),
            'rating'      => new external_value(PARAM_INT, 'Rating 1-5'),
            'contextid'   => new external_value(PARAM_INT, 'Context', VALUE_DEFAULT, 1),
        ]);
    }

    /**
     * Saves at most one rating per question and Kursfilter cookie (ADR 0003).
     *
     * Context and block access are checked by validate_search_context().
     *
     * @param int $questionid Question ID.
     * @param int $rating Rating from 1 to 5.
     * @param int $contextid Current context.
     * @return array Current average, count and own rating.
     * nosemgrep: moodle-external-ohne-kontextpruefung */
    public static function rate_question(int $questionid, int $rating, int $contextid): array {
        global $DB;

        $params = self::validate_parameters(self::rate_question_parameters(), [
            'questionid' => $questionid,
            'rating' => $rating,
            'contextid' => $contextid,
        ]);
        $context = self::validate_search_context($params['contextid']);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new invalid_parameter_exception('Ratings require POST.');
        }
        require_sesskey();
        if ($params['rating'] < 1 || $params['rating'] > 5) {
            throw new invalid_parameter_exception('Rating must be between 1 and 5.');
        }
        $qid = (int)$params['questionid'];
        if (!\block_fragenfilter\question_visibility::is_visible($qid)) {
            throw new moodle_exception('invalidquestion', 'block_fragenfilter');
        }
        $hash = self::$ratingcookie ??= \block_kursfilter\rating_helper::renew_cookie_hash()
            ?? \block_kursfilter\rating_helper::create_cookie_hash();
        $record = (object)[
            'questionid' => $qid, 'cookiehash' => $hash,
            'stars' => $params['rating'], 'timecreated' => time(),
        ];
        // The unique index also protects simultaneous requests from the same browser.
        try {
            $record->id = $DB->insert_record('block_fragenfilter_ratings', $record);
        } catch (dml_write_exception $e) {
            if (!$DB->record_exists('block_fragenfilter_ratings', ['questionid' => $qid, 'cookiehash' => $hash])) {
                throw $e;
            }
            return self::compute_rating($qid, $hash);
        }
        \block_fragenfilter\event\question_rated::create([
            'context' => $context, 'objectid' => $record->id,
            'other' => ['questionid' => $qid, 'stars' => $params['rating']],
        ])->trigger();
        return self::compute_rating($qid, $hash);
    }

    /**
     * Return structure for rate_question.
     */
    public static function rate_question_returns(): external_single_structure {
        return new external_single_structure([
            'average'  => new external_value(PARAM_FLOAT, 'Average'),
            'count'    => new external_value(PARAM_INT, 'Number of ratings'),
            'myrating' => new external_value(PARAM_INT, 'Own rating (0 = none)'),
        ]);
    }

    // ---------------------------------------------------------------
    // Get_ratings: load averages for a list of questions.

    /**
     * Parameters for get_ratings.
     */
    public static function get_ratings_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionids' => new external_value(PARAM_TEXT, 'Comma-separated question IDs'),
            'contextid'   => new external_value(PARAM_INT, 'Context', VALUE_DEFAULT, 1),
        ]);
    }

    /**
     * Loads rating data for multiple questions.
     *
     * Context and block access are checked by validate_search_context(). Only questions the
     * caller may see carry rating data; all others are answered like unknown questions.
     *
     * @param string $questionids Comma-separated question IDs, at most MAX_RATING_IDS distinct ones.
     * @param int    $contextid   Current context.
     * @return array Average, count and own rating for each requested question.
     * nosemgrep: moodle-external-ohne-kontextpruefung */
    public static function get_ratings(
        string $questionids,
        int $contextid
    ): array {
        global $DB;

        $params = self::validate_parameters(self::get_ratings_parameters(), [
            'questionids' => $questionids,
            'contextid'   => $contextid,
        ]);

        self::validate_search_context($params['contextid']);

        $requested = array_values(array_unique(array_filter(
            array_map('intval', explode(',', $params['questionids'])),
            fn(int $id): bool => $id > 0
        )));
        if (count($requested) > self::MAX_RATING_IDS) {
            throw new invalid_parameter_exception('At most ' . self::MAX_RATING_IDS . ' questions may be requested.');
        }
        if (empty($requested)) {
            return ['ratings' => []];
        }
        // Questions the caller cannot see are answered like unknown ones: no rating data.
        $visible = \block_fragenfilter\question_visibility::visible_ids($requested);
        $avgs = [];
        $myratings = [];
        if ($visible) {
            [$insql, $args] = $DB->get_in_or_equal($visible, SQL_PARAMS_NAMED, 'qid');

            // Average and count per question.
            $avgs = $DB->get_records_sql(
                "SELECT questionid,
                        AVG(stars) AS average,
                        COUNT(*)    AS cnt
                   FROM {block_fragenfilter_ratings}
                  WHERE questionid $insql
               GROUP BY questionid",
                $args
            );

            $hash = self::$ratingcookie ?? \block_kursfilter\rating_helper::get_cookie_hash();
            if ($hash !== null) {
                $rows = $DB->get_records_sql(
                    "SELECT questionid, stars
                       FROM {block_fragenfilter_ratings}
                      WHERE questionid $insql
                        AND cookiehash = :hash",
                    array_merge($args, ['hash' => $hash])
                );
                foreach ($rows as $row) {
                    $myratings[(int)$row->questionid] = (int)$row->stars;
                }
            }
        }

        $result = [];
        foreach ($requested as $qid) {
            $result[] = [
                'questionid' => $qid,
                'average'    => isset($avgs[$qid]) ? round((float)$avgs[$qid]->average, 1) : 0.0,
                'count'      => isset($avgs[$qid]) ? (int)$avgs[$qid]->cnt : 0,
                'myrating'   => $myratings[$qid] ?? 0,
            ];
        }
        return ['ratings' => $result];
    }

    /**
     * Return structure for get_ratings.
     */
    public static function get_ratings_returns(): external_single_structure {
        return new external_single_structure([
            'ratings' => new external_multiple_structure(
                new external_single_structure([
                    'questionid' => new external_value(PARAM_INT),
                    'average'    => new external_value(PARAM_FLOAT),
                    'count'      => new external_value(PARAM_INT),
                    'myrating'   => new external_value(PARAM_INT),
                ])
            ),
        ]);
    }

    /**
     * Calculates the average and own rating for one question.
     *
     * @param int      $qid    Question ID.
     * @param string $hash Kursfilter cookie hash.
     * @return array Average, count and own rating.
     */
    private static function compute_rating(int $qid, string $hash): array {
        global $DB;

        $avg  = (float)$DB->get_field_sql(
            "SELECT AVG(stars) FROM {block_fragenfilter_ratings} WHERE questionid = :qid",
            ['qid' => $qid]
        );
        $cnt  = $DB->count_records('block_fragenfilter_ratings', ['questionid' => $qid]);
        $mine = (int)$DB->get_field(
            'block_fragenfilter_ratings',
            'stars',
            ['questionid' => $qid, 'cookiehash' => $hash]
        );

        return [
            'average'  => round($avg, 1),
            'count'    => (int)$cnt,
            'myrating' => $mine,
        ];
    }
}
