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
 * Question search: filtering, rate limit, categories, question types, tags and labels.
 *
 * The web service adapter checks parameters and the caller context, then delegates here.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class search {
    /** Maximum number of requests per account and address within RATE_LIMIT_WINDOW seconds. */
    public const RATE_LIMIT_REQUESTS = 30;

    /** Length of the rate limit window in seconds. */
    public const RATE_LIMIT_WINDOW = 60;

    /** Upper bound for the result limit (DoS protection). */
    public const MAX_RESULT_LIMIT = 200;

    /**
     * Returns the effective result limit ceiling from the block settings.
     *
     * @return int Maximum number of results per search.
     */
    public static function max_result_limit(): int {
        return max(1, min(self::MAX_RESULT_LIMIT, (int)(get_config('block_fragenfilter', 'resultlimit') ?: 200)));
    }

    /**
     * Enforces the sliding rate limit for one account at one network address.
     *
     * The bucket belongs to the account and the address together, so anonymous visitors (user 0)
     * and reused pool accounts do not share one.
     *
     * @param int $userid Moodle user ID.
     * @param string $address Network address of the caller.
     * @throws \moodle_exception If the rate limit is exceeded.
     */
    public static function enforce_rate_limit(int $userid, string $address): void {
        $cache = \cache::make('block_fragenfilter', 'ratelimit');
        $key   = 'rl_' . hash('sha256', $userid . '|' . $address);
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
     * Searches questions visible in the given context.
     *
     * The limit is applied after all filters. The caller must have validated the context.
     *
     * @param search_filters $filters Filters, including the limit.
     * @param \context $context Validated caller context.
     * @param int $userid Account making the request, for the rate limit.
     * @param string $address Network address of the caller, for the rate limit.
     * @return array ['questions' => [...], 'total' => int]
     */
    public static function questions(search_filters $filters, \context $context, int $userid, string $address): array {
        global $DB;

        self::enforce_rate_limit($userid, $address);

        [$from, $queryargs] = \block_fragenfilter\question_visibility::visible_query($filters->scope, $context->id);

        $sql = "SELECT DISTINCT q.id, q.name, q.qtype, q.createdby,
                       qc.id AS categoryid, qc.name AS categoryname,
                       qc.contextid AS categorycontextid
                  $from";

        // Free-text search: question title OR tags.
        if (!empty($filters->text)) {
            $searchparam = self::like_pattern($filters->text);
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

        if ($filters->types) {
            [$typesql, $typeargs] = $DB->get_in_or_equal($filters->types, SQL_PARAMS_NAMED, 'type');
            $sql .= " AND q.qtype $typesql";
            $queryargs = array_merge($queryargs, $typeargs);
        }

        if ($filters->categoryids) {
            [$catsql, $catargs] = $DB->get_in_or_equal($filters->categoryids, SQL_PARAMS_NAMED, 'cat');
            $sql .= " AND qbe.questioncategoryid $catsql";
            $queryargs = array_merge($queryargs, $catargs);
        }

        // Every tag filter, and the difficulty level, must match as a tag of the question.
        $alltags = $filters->tags;
        if (!empty($filters->difficulty)) {
            $alltags[] = trim($filters->difficulty);
        }
        foreach (array_values($alltags) as $index => $tag) {
            $placeholder = 'filtertag' . $index;
            $sql .= " AND EXISTS (
                SELECT 1 FROM {tag_instance} ti JOIN {tag} t ON t.id = ti.tagid
                 WHERE ti.itemid = q.id AND ti.itemtype = 'question' AND ti.component = 'core_question'
                   AND " . $DB->sql_like('t.name', ':' . $placeholder, false, true, false, '!') . ")";
            $queryargs[$placeholder] = self::like_pattern($tag);
        }
        $sql .= " ORDER BY qc.name, q.name";

        $rows = $DB->get_records_sql($sql, $queryargs, 0, $filters->limit);
        $tags = self::tags_for_questions(array_map(static fn($row) => (int)$row->id, $rows));

        $questions = [];
        foreach ($rows as $row) {
            $questions[] = [
                'id' => (int)$row->id,
                'name' => $row->name,
                'qtype' => $row->qtype,
                'categoryid' => (int)$row->categoryid,
                'categoryname' => $row->categoryname,
                'contextid' => (int)$row->categorycontextid,
                'tags' => $tags[(int)$row->id] ?? [],
            ];
        }

        return [
            'questions' => $questions,
            'total' => count($questions),
        ];
    }

    /**
     * Loads the tags of many questions in one query.
     *
     * @param int[] $questionids Question IDs.
     * @return array<int, array<int, array{id: int, name: string}>> Tags keyed by question ID.
     */
    public static function tags_for_questions(array $questionids): array {
        global $DB;

        $tags = [];
        if (!$questionids) {
            return $tags;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values(array_unique($questionids)), SQL_PARAMS_NAMED, 'qid');
        $rs = $DB->get_recordset_sql(
            "SELECT ti.itemid, t.id, t.name
               FROM {tag_instance} ti
               JOIN {tag} t ON t.id = ti.tagid
              WHERE ti.itemtype = 'question'
                AND ti.component = 'core_question'
                AND ti.itemid $insql
           ORDER BY ti.id",
            $params
        );
        foreach ($rs as $r) {
            $tags[(int)$r->itemid][] = ['id' => (int)$r->id, 'name' => $r->name];
        }
        $rs->close();
        return $tags;
    }

    /**
     * Loads the categories visible in the given context, with readable context labels.
     *
     * @param string $scope Search scope: all, course or system.
     * @param \context $context Validated caller context.
     * @return array Categories with id, name, contextlabel and questioncount.
     */
    public static function categories(string $scope, \context $context): array {
        global $DB;

        [$from, $ctxargs] = \block_fragenfilter\question_visibility::visible_query($scope, $context->id);
        $rows = $DB->get_records_sql(
            "SELECT qc.id, qc.name, qc.contextid, ctx.contextlevel,
                    ctx.instanceid, COUNT(q.id) AS questioncount
               $from
           GROUP BY qc.id, qc.name, qc.contextid, ctx.contextlevel, ctx.instanceid
           ORDER BY qc.name",
            $ctxargs
        );

        $cats = [];
        foreach ($rows as $r) {
            $cats[] = [
                'id' => (int)$r->id,
                'name' => $r->name,
                'contextlabel' => self::context_label((int)$r->contextlevel, (int)$r->instanceid),
                'questioncount' => (int)$r->questioncount,
            ];
        }
        return $cats;
    }

    /**
     * Loads the question types for the search, sorted by display name.
     *
     * @param string $scope Search scope: all, course or system.
     * @param \context $context Validated caller context.
     * @param string $source Source: installed or existing.
     * @return array Entries with key and label.
     * @throws \invalid_parameter_exception If scope or source is unknown.
     */
    public static function question_types(string $scope, \context $context, string $source): array {
        global $DB;

        if (!in_array($scope, ['all', 'course', 'system'], true) || !in_array($source, ['installed', 'existing'], true)) {
            throw new \invalid_parameter_exception('Unknown search scope or question type source.');
        }

        $qtypes = [];
        if ($source === 'installed') {
            foreach (\core_component::get_plugin_list('qtype') as $key => $path) {
                if (in_array($key, ['missingtype', 'unknowntype'])) {
                    continue;
                }
                $qtypes[$key] = self::qtype_label($key);
            }
        } else {
            // Only types actually present in the question bank.
            [$from, $ctxargs] = \block_fragenfilter\question_visibility::visible_query($scope, $context->id);
            $rows = $DB->get_records_sql("SELECT DISTINCT q.qtype $from ORDER BY q.qtype", $ctxargs);
            foreach ($rows as $r) {
                $qtypes[$r->qtype] = self::qtype_label($r->qtype);
            }
        }

        asort($qtypes);
        $result = [];
        foreach ($qtypes as $key => $label) {
            $result[] = ['key' => $key, 'label' => $label];
        }
        return $result;
    }

    /**
     * Returns the display name of a question type.
     *
     * @param string $key Question type name.
     * @return string Plugin name, or the capitalised key if the plugin has no name string.
     */
    private static function qtype_label(string $key): string {
        $plugin = 'qtype_' . $key;
        return get_string_manager()->string_exists('pluginname', $plugin)
            ? get_string('pluginname', $plugin) : ucfirst($key);
    }

    /**
     * Returns a readable label for a context: course, module or question bank.
     *
     * @param int $contextlevel Context level.
     * @param int $instanceid Context instance ID.
     * @return string Readable label.
     */
    public static function context_label(int $contextlevel, int $instanceid): string {
        global $DB;
        $component = 'block_fragenfilter';
        switch ($contextlevel) {
            case CONTEXT_SYSTEM:
                return get_string('coresystem', $component);

            case CONTEXT_COURSECAT:
                $name = $DB->get_field('course_categories', 'name', ['id' => $instanceid]);
                return $name ? get_string('ctxcategory', $component, $name)
                    : get_string('ctxcategoryid', $component, $instanceid);

            case CONTEXT_COURSE:
                $name = $DB->get_field('course', 'fullname', ['id' => $instanceid]);
                return $name ?: get_string('ctxcourseid', $component, $instanceid);

            case CONTEXT_MODULE:
                $cm = $DB->get_record('course_modules', ['id' => $instanceid], 'id,course,module,instance');
                if (!$cm) {
                    return get_string('ctxmoduleid', $component, $instanceid);
                }

                $coursename = $DB->get_field('course', 'shortname', ['id' => $cm->course])
                    ?: get_string('ctxcoursedefault', $component);
                $modname = $DB->get_field('modules', 'name', ['id' => $cm->module]);

                if ($modname === 'qbank') {
                    $bankname = $DB->get_field('qbank', 'name', ['id' => $cm->instance]);
                    if ($bankname) {
                        return get_string('ctxbank', $component, (object)['course' => $coursename, 'name' => $bankname]);
                    }
                } else if ($modname === 'quiz') {
                    $quizname = $DB->get_field('quiz', 'name', ['id' => $cm->instance]);
                    if ($quizname) {
                        return get_string('ctxquiz', $component, (object)['course' => $coursename, 'name' => $quizname]);
                    }
                }

                // Fallback: the course name is sufficient.
                return $coursename;

            default:
                return get_string('ctxcontextid', $component, $instanceid);
        }
    }

    /**
     * Escapes a search term for a LIKE pattern with '!' as escape character.
     *
     * @param string $term Raw term.
     * @return string Pattern with leading and trailing wildcard.
     */
    private static function like_pattern(string $term): string {
        global $DB;
        return '%' . $DB->sql_like_escape(str_replace('!', '!!', $term), '!') . '%';
    }
}
