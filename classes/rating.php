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
 * Star ratings for questions: one rating per question and browser (ADR 0003).
 *
 * The browser is identified by the Kursfilter cookie kf_rater_id. The web service adapter checks
 * parameters, POST and sesskey, then delegates here.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rating {
    /** Maximum number of questions per read request (DoS protection). */
    public const MAX_IDS = search::MAX_RESULT_LIMIT;

    /**
     * @var string|null Cookie hash resolved for rating writes in this request.
     */
    private static ?string $cookie = null;

    /**
     * Returns the cookie hash that identifies the browser for a write, and sets the cookie if needed.
     *
     * @return string Cookie hash.
     */
    public static function cookie_for_rating(): string {
        return self::$cookie ??= \block_kursfilter\rating_helper::renew_cookie_hash()
            ?? \block_kursfilter\rating_helper::create_cookie_hash();
    }

    /**
     * Returns the cookie hash of the current browser for reads, without setting a cookie.
     *
     * @return string|null Cookie hash, or null if the browser has none.
     */
    public static function cookie_for_reading(): ?string {
        return self::$cookie ?? \block_kursfilter\rating_helper::get_cookie_hash();
    }

    /**
     * Saves a rating unless the browser has already rated the question, and returns the current state.
     *
     * @param int $questionid Question ID.
     * @param int $stars Rating from 1 to 5.
     * @param \context $context Context in which the rating happens (for the event).
     * @param string|null $hash Cookie hash of the browser; null resolves it from the request.
     * @return array Average, count and own rating.
     * @throws \moodle_exception If the question is not visible to the user.
     */
    public static function rate(int $questionid, int $stars, \context $context, ?string $hash = null): array {
        global $DB;

        if (!question_visibility::is_visible($questionid)) {
            throw new \moodle_exception('invalidquestion', 'block_fragenfilter');
        }
        $hash ??= self::cookie_for_rating();
        $record = (object)[
            'questionid' => $questionid, 'cookiehash' => $hash,
            'stars' => $stars, 'timecreated' => time(),
        ];
        // The unique index also protects simultaneous requests from the same browser.
        try {
            $record->id = $DB->insert_record('block_fragenfilter_ratings', $record);
        } catch (\dml_write_exception $e) {
            if (!$DB->record_exists('block_fragenfilter_ratings', ['questionid' => $questionid, 'cookiehash' => $hash])) {
                throw $e;
            }
            return self::summary($questionid, $hash);
        }
        event\question_rated::create([
            'context' => $context, 'objectid' => $record->id,
            'other' => ['questionid' => $questionid, 'stars' => $stars],
        ])->trigger();
        return self::summary($questionid, $hash);
    }

    /**
     * Returns average, count and own rating for each question the user may see.
     *
     * Questions the user may not see, and unknown ones, get zero values.
     *
     * @param int[] $questionids Distinct question IDs.
     * @param string|null $hash Cookie hash of the browser, or null if it has none.
     * @return array List of questionid, average, count and myrating in the given order.
     */
    public static function for_questions(array $questionids, ?string $hash): array {
        global $DB;

        $visible = $questionids ? question_visibility::visible_ids($questionids) : [];
        $avgs = [];
        $myratings = [];
        if ($visible) {
            [$insql, $args] = $DB->get_in_or_equal($visible, SQL_PARAMS_NAMED, 'qid');
            $avgs = $DB->get_records_sql(
                "SELECT questionid, AVG(stars) AS average, COUNT(*) AS cnt
                   FROM {block_fragenfilter_ratings}
                  WHERE questionid $insql
               GROUP BY questionid",
                $args
            );
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
        foreach ($questionids as $qid) {
            $result[] = [
                'questionid' => $qid,
                'average'    => isset($avgs[$qid]) ? round((float)$avgs[$qid]->average, 1) : 0.0,
                'count'      => isset($avgs[$qid]) ? (int)$avgs[$qid]->cnt : 0,
                'myrating'   => $myratings[$qid] ?? 0,
            ];
        }
        return $result;
    }

    /**
     * Calculates average, count and own rating for one question.
     *
     * @param int $questionid Question ID.
     * @param string $hash Cookie hash of the browser.
     * @return array Average, count and own rating.
     */
    private static function summary(int $questionid, string $hash): array {
        $row = self::for_questions([$questionid], $hash)[0];
        return ['average' => $row['average'], 'count' => $row['count'], 'myrating' => $row['myrating']];
    }
}
