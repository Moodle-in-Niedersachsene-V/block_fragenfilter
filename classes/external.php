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
        global $USER;

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

        $maxlimit = \block_fragenfilter\search::max_result_limit();
        $context = self::validate_search_context($params['contextid']);
        $userid = (int)$USER->id;
        $address = getremoteaddr();

        try {
            $filters = \block_fragenfilter\search_filters::from_input(
                $params['search'],
                $params['categories'],
                $params['types'],
                $params['difficulty'],
                $params['tags'],
                $params['scope'],
                $params['limit'],
                $maxlimit
            );
        } catch (invalid_parameter_exception $e) {
            // Invalid input still counts against the rate limit, as before the refactoring.
            \block_fragenfilter\search::enforce_rate_limit($userid, $address);
            throw $e;
        }

        return \block_fragenfilter\search::questions($filters, $context, $userid, $address);
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
        $params = self::validate_parameters(
            self::get_categories_parameters(),
            ['scope' => $scope, 'contextid' => $contextid]
        );
        $context = self::validate_search_context($params['contextid']);

        return ['categories' => \block_fragenfilter\search::categories($params['scope'], $context)];
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
        $params = self::validate_parameters(
            self::get_questiontypes_parameters(),
            ['scope' => $scope, 'contextid' => $contextid, 'source' => $source]
        );
        $context = self::validate_search_context($params['contextid']);

        return ['qtypes' => \block_fragenfilter\search::question_types($params['scope'], $context, $params['source'])];
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
        if (!\block_fragenfilter\visitor_access::may_export()) {
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

        $formats = implode(',', \block_fragenfilter\question_export::enabled_formats());

        return [
            'canexport'     => \block_fragenfilter\visitor_access::may_export(),
            'canpreview'    => \block_fragenfilter\visitor_access::may_preview(),
            'isloggedin'    => \block_fragenfilter\visitor_access::has_real_login(),
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
        return \block_fragenfilter\rating::rate((int)$params['questionid'], $params['rating'], $context);
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
     * @param string $questionids Comma-separated question IDs, at most rating::MAX_IDS distinct ones.
     * @param int    $contextid   Current context.
     * @return array Average, count and own rating for each requested question.
     * nosemgrep: moodle-external-ohne-kontextpruefung */
    public static function get_ratings(
        string $questionids,
        int $contextid
    ): array {
        $params = self::validate_parameters(self::get_ratings_parameters(), [
            'questionids' => $questionids,
            'contextid'   => $contextid,
        ]);

        self::validate_search_context($params['contextid']);

        $requested = array_values(array_unique(array_filter(
            array_map('intval', explode(',', $params['questionids'])),
            fn(int $id): bool => $id > 0
        )));
        if (count($requested) > \block_fragenfilter\rating::MAX_IDS) {
            throw new invalid_parameter_exception(
                'At most ' . \block_fragenfilter\rating::MAX_IDS . ' questions may be requested.'
            );
        }
        if (empty($requested)) {
            return ['ratings' => []];
        }
        $hash = \block_fragenfilter\rating::cookie_for_reading();
        return ['ratings' => \block_fragenfilter\rating::for_questions($requested, $hash)];
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
}
