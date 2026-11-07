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

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Anonymous visitors use the real Moodle AJAX endpoint.
 *
 * HTTP requests execute in a separate web server process.
 *
 * @package    block_fragenfilter
 * @copyright  2026 Moodle in Niedersachsen e. V.
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class request_test extends \advanced_testcase {
    /** @var string Test web server URL. */
    private string $baseurl = '';

    /** @var string Cookie file of the current visitor. */
    private string $cookiejar = '';

    /** @var string[] Cookie files of all visitors in this test. */
    private array $cookiejars = [];

    /** @var string Response headers of the last AJAX request. */
    private string $ajaxheaders = '';

    /** @var string Redirect target of the last preview_request(), empty without a redirect. */
    private string $redirecturl = '';

    /** @var string Password unique to this test run. */
    private string $password = '';

    /** @var bool Whether Moodle's test setup ran. */
    private bool $setupdone = false;

    protected function setUp(): void {
        $url = getenv('QUESTIONFILTER_WEB_URL');
        if (!$url) {
            $this->markTestSkipped('QUESTIONFILTER_WEB_URL is not set.');
        }
        parent::setUp();
        $this->setupdone = true;
        $this->password = bin2hex(random_bytes(16));
        $this->resetAfterTest();
        // The materials hub exposes search to anonymous visitors.
        set_config('forcelogin', 0);
        // The web server process runs with developer debugging shown, so notices reach the response.
        set_config('debug', DEBUG_DEVELOPER);
        set_config('debugdisplay', 1);
        $this->baseurl = rtrim($url, '/');
    }

    protected function tearDown(): void {
        if (!$this->setupdone) {
            return;
        }
        foreach ($this->cookiejars as $cookiejar) {
            unlink($cookiejar);
        }
        // HTTP writes bypass PHPUnit's change tracking, requiring a full reset.
        \testing_util::$tableupdated = [];
        \phpunit_util::$lastdbwrites = null;
        self::resetAllData(false);
        parent::tearDown();
    }

    /**
     * Calls AJAX without a login, cookie or session key.
     *
     * @param array $requests AJAX batch with registered functions and arguments.
     * @param string $sesskey Optional authenticated visitor session key.
     * @param bool $cookies Send the visitor's cookies even without a sesskey.
     * @param string $method HTTP method.
     * @return array Decoded AJAX responses.
     */
    private function ajax(array $requests, string $sesskey = '', bool $cookies = false, string $method = 'POST'): array {
        // Commit fixtures through Moodle's public test API before the separate process reads them.
        $this->preventResetByRollback();
        $ch = curl_init($this->baseurl . '/lib/ajax/service.php' . ($sesskey ? '?sesskey=' . $sesskey : ''));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($requests),
            CURLOPT_COOKIEFILE => $sesskey || $cookies ? $this->cookiejar : '',
            CURLOPT_COOKIEJAR => $sesskey || $cookies ? $this->cookiejar : '',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $this->ajaxheaders = '';
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, string $header): int {
            $this->ajaxheaders .= $header;
            return strlen($header);
        });
        $body = curl_exec($ch);
        $this->assertNotFalse($body, 'Test web server not reachable: ' . curl_error($ch));
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $this->assertSame(200, $status, $body);
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey(0, $response, $body);
        return $response;
    }

    public function test_rating_uses_a_server_cookie_once_per_question(): void {
        global $DB;

        $question = $this->create_question();
        $other = $this->create_question();
        // The web server process only logs events when a log store is enabled.
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        $sesskey = $this->visitor_sesskey();
        $request = [
            'index' => 0, 'methodname' => 'block_fragenfilter_rate_question',
            'args' => ['questionid' => $question->id, 'rating' => 4, 'contextid' => 1],
        ];
        $result = $this->ajax([$request], $sesskey)[0];
        $this->assertFalse($result['error'], json_encode($result));
        $this->assertSame(['average' => 4, 'count' => 1, 'myrating' => 4], $result['data']);
        $this->assertSame(1, preg_match('/Set-Cookie: kf_rater_id=([a-f0-9]{64});([^\r\n]+)/i', $this->ajaxheaders, $cookie));
        foreach (['secure', 'HttpOnly', 'SameSite=Lax', 'path=/'] as $option) {
            $this->assertStringContainsString($option, $cookie[2]);
        }
        $this->assertMatchesRegularExpression('/Max-Age=31536000\b/', $cookie[2]);
        $stored = $DB->get_record('block_fragenfilter_ratings', ['questionid' => $question->id], '*', MUST_EXIST);
        $this->assertSame($cookie[1], $stored->cookiehash);
        $this->assertSame(4, (int)$stored->stars);
        $request['args']['rating'] = 1;
        $again = $this->ajax([$request], $sesskey)[0];
        $this->assertFalse($again['error'], json_encode($again));
        $this->assertSame($result['data'], $again['data']);
        $this->assertEquals($stored, $DB->get_record('block_fragenfilter_ratings', ['id' => $stored->id]));
        // Only the accepted rating is logged, without the cookie hash.
        $logs = $DB->get_records('logstore_standard_log', ['eventname' => '\\block_fragenfilter\\event\\question_rated']);
        $this->assertCount(1, $logs);
        $log = reset($logs);
        $this->assertEquals($stored->id, $log->objectid);
        $this->assertSame(['questionid' => (int)$question->id, 'stars' => 4], json_decode($log->other, true));
        $this->assertStringNotContainsString($cookie[1], json_encode($log));
        $read = $this->ajax([[
            'index' => 0, 'methodname' => 'block_fragenfilter_get_ratings',
            'args' => ['questionids' => $question->id . ',' . $other->id, 'contextid' => 1],
        ]], $sesskey)[0];
        $this->assertFalse($read['error'], json_encode($read));
        $this->assertSame(4, $read['data']['ratings'][0]['myrating']);
        $this->assertSame(0, $read['data']['ratings'][1]['myrating']);
        $request['args']['questionid'] = $other->id;
        $this->assertSame(1, $this->ajax([$request], $sesskey)[0]['data']['count']);
        $this->assertSame(2, $DB->count_records('block_fragenfilter_ratings', ['cookiehash' => $cookie[1]]));
    }

    public function test_rating_rejects_invalid_requests_without_changing_domain_data(): void {
        global $DB;

        $question = $this->create_question();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $hidden = $generator->create_question('truefalse', null, [
            'category' => $generator->create_question_category()->id,
        ]);
        $sesskey = $this->visitor_sesskey();
        $request = [
            'index' => 0, 'methodname' => 'block_fragenfilter_rate_question',
            'args' => ['questionid' => $question->id, 'rating' => 5, 'contextid' => 1],
        ];
        $before = [];
        foreach (['question', 'question_answers', 'block_fragenfilter_ratings', 'block_fragenfilter_tag_pending'] as $table) {
            $before[$table] = $DB->get_records($table, null, 'id');
        }
        foreach (
            [
                [$request, '', 'POST'],
                [$request, 'wrong12345', 'POST'],
                [$request, $sesskey, 'GET'],
                [$request, $sesskey, 'PUT'],
                [array_replace_recursive($request, ['args' => ['rating' => 0]]), $sesskey, 'POST'],
                [array_replace_recursive($request, ['args' => ['rating' => 6]]), $sesskey, 'POST'],
                [array_replace_recursive($request, ['args' => ['questionid' => -1]]), $sesskey, 'POST'],
                [array_replace_recursive($request, ['args' => ['questionid' => 2147483647]]), $sesskey, 'POST'],
                [array_replace_recursive($request, ['args' => ['questionid' => $hidden->id]]), $sesskey, 'POST'],
                [array_replace_recursive($request, ['args' => ['questionid' => 'abc']]), $sesskey, 'POST'],
                [array_replace_recursive($request, ['args' => ['rating' => 'abc']]), $sesskey, 'POST'],
                [array_replace_recursive($request, ['args' => ['rating' => [5]]]), $sesskey, 'POST'],
            ] as [$invalid, $key, $method]
        ) {
            $result = $this->ajax([$invalid], $key, true, $method)[0];
            $this->assertTrue($result['error'], json_encode($result));
            $this->assertArrayNotHasKey('data', $result);
            $this->assertStringNotContainsString('kf_rater_id=', $this->ajaxheaders);
            foreach ($before as $table => $records) {
                $this->assertEquals($records, $DB->get_records($table, null, 'id'), $table);
            }
        }
        $accepted = $this->ajax([$request], $sesskey)[0];
        $this->assertFalse($accepted['error'], json_encode($accepted));
        $this->assertSame(1, $accepted['data']['count']);
        // A forged legacy parameter cannot choose a second identity for the same cookie.
        $request['args']['sessionhash'] = str_repeat('b', 64);
        $request['args']['cookiehash'] = str_repeat('c', 64);
        $request['args']['rating'] = 1;
        $forged = $this->ajax([$request], $sesskey)[0];
        $this->assertTrue($forged['error'], 'Unknown identity parameters must be rejected.');
        $this->assertSame(1, $DB->count_records('block_fragenfilter_ratings'));
        $this->assertSame(5, (int)$DB->get_field('block_fragenfilter_ratings', 'stars', ['questionid' => $question->id]));
    }

    public function test_rating_requires_block_permission_for_writes_and_reads(): void {
        global $DB;

        $question = $this->create_question();
        $user = $this->getDataGenerator()->create_user(['password' => $this->password]);
        $role = create_role('Denied ratings', 'deniedratings', '');
        assign_capability('block/fragenfilter:view', CAP_PROHIBIT, $role, 1);
        role_assign($role, $user->id, 1);
        $key = $this->login_reviewer($user);
        foreach (['block_fragenfilter_rate_question', 'block_fragenfilter_get_ratings'] as $method) {
            $args = $method === 'block_fragenfilter_rate_question'
                ? ['questionid' => $question->id, 'rating' => 3, 'contextid' => 1]
                : ['questionids' => (string)$question->id, 'contextid' => 1];
            $result = $this->ajax([['index' => 0, 'methodname' => $method, 'args' => $args]], $key)[0];
            $this->assertTrue($result['error'], json_encode($result));
            $this->assertSame('nopermission', $result['exception']['errorcode']);
            $this->assertSame(0, $DB->count_records('block_fragenfilter_ratings'));
            $this->assertStringNotContainsString('kf_rater_id=', $this->ajaxheaders);
        }
    }

    public function test_rating_identity_is_the_cookie_even_when_a_pool_account_is_reused(): void {
        global $DB;

        $question = $this->create_question();
        $user = $DB->get_record('user', ['username' => 'kursfilter_pool01'], '*', MUST_EXIST);
        update_internal_user_password($user, $this->password);
        $request = [
            'index' => 0, 'methodname' => 'block_fragenfilter_rate_question',
            'args' => ['questionid' => $question->id, 'rating' => 5, 'contextid' => 1],
        ];
        $key = $this->login_reviewer($user);
        $firstjar = $this->cookiejar;
        $first = $this->ajax([$request], $key)[0];
        $this->assertFalse($first['error'], json_encode($first));
        $this->start_new_visitor();
        $secondkey = $this->login_reviewer($user);
        $request['args']['rating'] = 1;
        $second = $this->ajax([$request], $secondkey)[0];
        $this->assertFalse($second['error'], json_encode($second));
        $this->assertSame(['average' => 3, 'count' => 2, 'myrating' => 1], $second['data']);
        $this->assertSame(2, count(array_unique($DB->get_fieldset_select('block_fragenfilter_ratings', 'cookiehash', '1 = 1'))));
        $this->cookiejar = $firstjar;
        $read = $this->ajax([[
            'index' => 0, 'methodname' => 'block_fragenfilter_get_ratings',
            'args' => ['questionids' => (string)$question->id, 'contextid' => 1],
        ]], $key)[0];
        $this->assertFalse($read['error'], json_encode($read));
        $this->assertSame(5, $read['data']['ratings'][0]['myrating']);
    }

    public function test_first_rating_batch_uses_one_cookie_for_writes_and_reads(): void {
        global $DB;

        $question = $this->create_question();
        $sesskey = $this->visitor_sesskey();
        $write = [
            'index' => 0, 'methodname' => 'block_fragenfilter_rate_question',
            'args' => ['questionid' => $question->id, 'rating' => 3, 'contextid' => 1],
        ];
        $again = $write;
        $again['index'] = 1;
        $again['args']['rating'] = 1;
        $read = [
            'index' => 2, 'methodname' => 'block_fragenfilter_get_ratings',
            'args' => ['questionids' => (string)$question->id, 'contextid' => 1],
        ];
        $results = $this->ajax([$write, $again, $read], $sesskey);
        foreach ($results as $result) {
            $this->assertFalse($result['error'], json_encode($result));
        }
        $this->assertSame(['average' => 3, 'count' => 1, 'myrating' => 3], $results[1]['data']);
        $this->assertSame(3, $results[2]['data']['ratings'][0]['myrating']);
        $this->assertSame(1, $DB->count_records('block_fragenfilter_ratings'));
    }

    public function test_get_ratings_treats_questions_the_visitor_cannot_see_like_unknown_ones(): void {
        global $DB;

        $visible = $this->create_question();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $hidden = $generator->create_question('truefalse', null, [
            'category' => $generator->create_question_category()->id,
        ]);
        $sesskey = $this->visitor_sesskey();
        $rate = $this->ajax([[
            'index' => 0, 'methodname' => 'block_fragenfilter_rate_question',
            'args' => ['questionid' => $visible->id, 'rating' => 3, 'contextid' => 1],
        ]], $sesskey)[0];
        $this->assertFalse($rate['error'], json_encode($rate));
        $ownhash = $DB->get_field('block_fragenfilter_ratings', 'cookiehash', ['questionid' => $visible->id], MUST_EXIST);
        // Ratings that already exist for a question the visitor cannot see, including one under the visitor's own cookie.
        $existing = [
            [$visible->id, str_repeat('a', 64), 4],
            [$hidden->id, str_repeat('b', 64), 5],
            [$hidden->id, $ownhash, 1],
        ];
        foreach ($existing as [$questionid, $cookiehash, $stars]) {
            $DB->insert_record('block_fragenfilter_ratings', (object)[
                'questionid' => $questionid, 'cookiehash' => $cookiehash, 'stars' => $stars, 'timecreated' => time(),
            ]);
        }
        $this->assertSame(2, $DB->count_records('block_fragenfilter_ratings', ['questionid' => $hidden->id]));
        $unknown = 2147483647;

        $read = $this->ajax([[
            'index' => 0, 'methodname' => 'block_fragenfilter_get_ratings',
            'args' => ['questionids' => implode(',', [$visible->id, $hidden->id, $unknown]), 'contextid' => 1],
        ]], $sesskey)[0];

        $this->assertFalse($read['error'], json_encode($read));
        $this->assertSame(
            [
                ['questionid' => (int)$visible->id, 'average' => 3.5, 'count' => 2, 'myrating' => 3],
                ['questionid' => (int)$hidden->id, 'average' => 0, 'count' => 0, 'myrating' => 0],
                ['questionid' => $unknown, 'average' => 0, 'count' => 0, 'myrating' => 0],
            ],
            $read['data']['ratings']
        );
    }

    public function test_get_ratings_accepts_at_most_200_distinct_question_ids(): void {
        $question = $this->create_question();
        $sesskey = $this->visitor_sesskey();
        $call = fn(array $ids) => $this->ajax([[
            'index' => 0, 'methodname' => 'block_fragenfilter_get_ratings',
            'args' => ['questionids' => implode(',', $ids), 'contextid' => 1],
        ]], $sesskey)[0];

        $limit = range(1, 200);
        $limit[0] = (int)$question->id;
        $accepted = $call($limit);
        $this->assertFalse($accepted['error'], json_encode($accepted));
        $this->assertCount(200, $accepted['data']['ratings']);
        // Repeating an ID does not count twice.
        $repeated = $call(array_merge($limit, [$limit[0], $limit[0]]));
        $this->assertFalse($repeated['error'], json_encode($repeated));
        $this->assertCount(200, $repeated['data']['ratings']);

        $rejected = $call(range(1, 201));
        $this->assertTrue($rejected['error'], json_encode($rejected));
        $this->assertArrayNotHasKey('data', $rejected);
        $this->assertSame('invalidparameter', $rejected['exception']['errorcode']);
    }

    /**
     * Fails if a page response carries a Moodle debugging message or a displayed PHP notice or warning.
     *
     * AJAX responses are not checked: Moodle never displays debugging there (NO_DEBUG_DISPLAY).
     *
     * @param string $body Response body.
     */
    private function assert_no_debug_output(string $body): void {
        $this->assertDoesNotMatchRegularExpression(
            '/debuggingmessage|\b(?:Notice|Warning|Deprecated)\b(?:<\/b>)?:[^\n]* on line \d+/',
            $body,
            'The response contains debugging output.'
        );
    }

    /**
     * Calls the preview endpoint with the same visitor session.
     *
     * @param array $fields Query or form fields.
     * @param bool $post Whether to submit a POST.
     * @param string $path Endpoint path.
     * @param string[] $headers Additional request headers.
     * @return string Response body.
     */
    private function preview_request(
        array $fields,
        bool $post,
        string $path = '/blocks/fragenfilter/guest_preview.php',
        array $headers = []
    ): string {
        $this->preventResetByRollback();
        if ($this->cookiejar === '') {
            $this->cookiejar = tempnam(sys_get_temp_dir(), 'qfjar');
            $this->cookiejars[] = $this->cookiejar;
        }
        $url = $this->baseurl . $path;
        if (!$post && $fields) {
            $url .= '?' . http_build_query($fields, '', '&');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => $this->cookiejar,
            CURLOPT_COOKIEJAR => $this->cookiejar,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields, '', '&'));
        }
        $body = curl_exec($ch);
        $this->assertNotFalse($body, 'Test web server not reachable: ' . curl_error($ch));
        $this->assert_no_debug_output($body);
        $this->redirecturl = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return $body;
    }

    public function test_preview_rejects_get_and_invalid_sesskeys_before_logging_in(): void {
        global $DB;

        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $this->make_category_visible($category);
        $question = $generator->create_question('truefalse', null, ['category' => $category->id]);
        $pooluser = $DB->get_record('user', ['username' => 'kursfilter_pool01'], '*', MUST_EXIST);
        $loginpage = $this->preview_request([], false, '/login/index.php');
        $this->assertSame(1, preg_match('/"sesskey":"([A-Za-z0-9]+)"/', $loginpage, $matches));
        $sesskey = $matches[1];
        $before = [];
        foreach (['user', 'question', 'block_fragenfilter_ratings', 'block_fragenfilter_tag_pending'] as $table) {
            $before[$table] = $DB->get_records($table, null, 'id');
        }

        $requests = [
            [false, ['qid' => $question->id], 'error_preview_needs_post'],
            [false, ['qid' => $question->id, 'sesskey' => 'wrong12345'], 'error_preview_needs_post'],
            [false, ['qid' => $question->id, 'sesskey' => $sesskey], 'error_preview_needs_post'],
            [true, ['qid' => $question->id], 'missingparam'],
            [true, ['qid' => $question->id, 'sesskey' => 'wrong12345'], 'invalidsesskey'],
        ];
        foreach ($requests as [$post, $fields, $error]) {
            $body = $this->preview_request($fields, $post);
            $component = $error === 'error_preview_needs_post' ? 'block_fragenfilter' : 'error';
            $this->assertStringContainsString(get_string($error, $component, 'sesskey'), $body, $error);
            $this->assertStringNotContainsString('qf_pool_login_done', $body);
            $this->assertSame(0, $DB->count_records('sessions', ['userid' => $pooluser->id]));
            foreach ($before as $table => $records) {
                $this->assertEquals($records, $DB->get_records($table, null, 'id'), $table . ' changed.');
            }
        }

        $body = $this->preview_request(['qid' => $question->id, 'sesskey' => $sesskey], true);
        $this->assertStringContainsString('qf_pool_login_done', $body);
        $this->assertStringContainsString(
            '/question/bank/previewquestion/preview.php?id=' . $question->id,
            str_replace('\\/', '/', $body)
        );
        $this->assertSame(1, $DB->count_records('sessions', ['userid' => $pooluser->id]));
    }

    /**
     * Starts a new visitor with an empty cookie jar.
     */
    private function start_new_visitor(): void {
        $this->cookiejar = '';
    }

    /**
     * Reads the sesskey Moodle hands to the current visitor on the login page.
     *
     * @param string[] $headers Additional request headers.
     * @return string Session key.
     */
    private function visitor_sesskey(array $headers = []): string {
        $page = $this->preview_request([], false, '/login/index.php', $headers);
        $this->assertSame(1, preg_match('/"sesskey":"([A-Za-z0-9]+)"/', $page, $matches));
        return $matches[1];
    }

    /**
     * Occupies a pool account with a running session from the given address.
     *
     * @param string $username Pool account username.
     * @param string $ip Address of the occupying session.
     */
    private function occupy_pool_account(string $username, string $ip): void {
        global $DB;

        $now = time();
        $DB->insert_record('sessions', (object)[
            'state' => 0,
            'sid' => bin2hex(random_bytes(16)),
            'userid' => $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST),
            'sessdata' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'firstip' => $ip,
            'lastip' => $ip,
        ]);
    }

    /**
     * Counts the sessions of all pool accounts.
     *
     * @return int Number of sessions.
     */
    private function count_pool_sessions(): int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(
            \block_kursfilter\pool_manager::get_pool_usernames(\block_kursfilter\pool_manager::POOL_SIZE_MAX)
        );
        return $DB->count_records_sql(
            "SELECT COUNT(s.id) FROM {sessions} s JOIN {user} u ON u.id = s.userid WHERE u.username $insql",
            $params
        );
    }

    /**
     * Creates a visible question for the preview tests.
     *
     * @return \stdClass Question.
     */
    private function create_question(): \stdClass {
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $this->make_category_visible($category);
        return $generator->create_question('truefalse', null, ['category' => $category->id]);
    }

    /**
     * Lets visitors and regular accounts see the questions of a category, as the hub does.
     *
     * @param \stdClass $category Question category.
     */
    private function make_category_visible(\stdClass $category): void {
        foreach (['guestroleid', 'defaultuserroleid'] as $setting) {
            assign_capability('moodle/question:viewall', CAP_ALLOW, get_config('core', $setting), $category->contextid);
        }
    }

    public function test_preview_keeps_a_real_login_and_takes_no_pool_account(): void {
        $question = $this->create_question();
        $user = $this->getDataGenerator()->create_user(['password' => $this->password]);
        $sesskey = $this->login_reviewer($user);

        $body = $this->preview_request(['qid' => $question->id, 'sesskey' => $sesskey], true);

        $this->assertStringContainsString('/question/bank/previewquestion/preview.php?id=' . $question->id, $body);
        $this->assertStringNotContainsString('qf_pool_login_done', $body);
        $this->assertSame(0, $this->count_pool_sessions());
        // The same Moodle session (same sesskey) still belongs to the real account.
        $this->assertStringContainsString('"sesskey":"' . $sesskey . '"', $this->preview_request([], false, '/my/'));
    }

    public function test_preview_starts_pool_accounts_for_visitors_on_different_addresses(): void {
        global $DB;

        $question = $this->create_question();
        set_config('getremoteaddrconf', GETREMOTEADDR_SKIP_HTTP_CLIENT_IP);
        $logins = [];
        foreach (['198.51.100.1', '198.51.100.2'] as $ip) {
            $this->start_new_visitor();
            $headers = ['X-Forwarded-For: ' . $ip];
            $sesskey = $this->visitor_sesskey($headers);
            $body = $this->preview_request(
                ['qid' => $question->id, 'sesskey' => $sesskey],
                true,
                '/blocks/fragenfilter/guest_preview.php',
                $headers
            );
            $this->assertStringContainsString('qf_pool_login_done', $body);
            $logins[$ip] = $DB->get_field_sql(
                "SELECT u.username FROM {sessions} s JOIN {user} u ON u.id = s.userid WHERE s.firstip = ?",
                [$ip]
            );
        }

        $this->assertStringStartsWith('kursfilter_pool', $logins['198.51.100.1']);
        $this->assertStringStartsWith('kursfilter_pool', $logins['198.51.100.2']);
        $this->assertNotEquals($logins['198.51.100.1'], $logins['198.51.100.2']);
    }

    public function test_preview_shows_a_notice_when_the_pool_is_full(): void {
        $question = $this->create_question();
        foreach (\block_kursfilter\pool_manager::get_pool_usernames() as $username) {
            $this->occupy_pool_account($username, '203.0.113.9');
        }
        $occupied = $this->count_pool_sessions();
        $sesskey = $this->visitor_sesskey();

        $body = $this->preview_request(['qid' => $question->id, 'sesskey' => $sesskey], true);

        $this->assertStringContainsString(get_string('preview_pool_full', 'block_fragenfilter'), $body);
        $this->assertStringNotContainsString('qf_pool_login_done', $body);
        $this->assertSame($occupied, $this->count_pool_sessions());
    }

    public function test_preview_shows_a_notice_when_the_address_already_has_a_preview(): void {
        $question = $this->create_question();
        set_config('getremoteaddrconf', GETREMOTEADDR_SKIP_HTTP_CLIENT_IP);
        $this->occupy_pool_account('kursfilter_pool01', '198.51.100.7');
        $headers = ['X-Forwarded-For: 198.51.100.7'];
        $sesskey = $this->visitor_sesskey($headers);

        $body = $this->preview_request(
            ['qid' => $question->id, 'sesskey' => $sesskey],
            true,
            '/blocks/fragenfilter/guest_preview.php',
            $headers
        );

        $this->assertStringContainsString(get_string('preview_in_use', 'block_fragenfilter'), $body);
        $this->assertStringNotContainsString('qf_pool_login_done', $body);
        $this->assertSame(1, $this->count_pool_sessions());
    }

    public function test_preview_gives_the_preview_role_to_a_pool_account_created_later(): void {
        global $DB;

        $question = $this->create_question();
        set_config('poolsize', 11, 'block_kursfilter');
        \block_kursfilter\pool_manager::create_pool_accounts();
        $latest = $DB->get_record('user', ['username' => 'kursfilter_pool11'], '*', MUST_EXIST);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'fragenfilter_preview'], MUST_EXIST);
        $systemcontextid = \context_system::instance()->id;
        $this->assertFalse(user_has_role_assignment($latest->id, $roleid, $systemcontextid));
        foreach (\block_kursfilter\pool_manager::get_pool_usernames(10) as $username) {
            $this->occupy_pool_account($username, '203.0.113.9');
        }
        $sesskey = $this->visitor_sesskey();

        $body = $this->preview_request(['qid' => $question->id, 'sesskey' => $sesskey], true);

        $this->assertStringContainsString('qf_pool_login_done', $body);
        $this->assertSame(1, $DB->count_records('sessions', ['userid' => $latest->id]));
        $this->assertTrue(user_has_role_assignment($latest->id, $roleid, $systemcontextid));
    }

    public function test_pool_account_is_kept_off_profile_pages_and_a_real_account_is_not(): void {
        $question = $this->create_question();
        $sesskey = $this->visitor_sesskey();
        $body = $this->preview_request(['qid' => $question->id, 'sesskey' => $sesskey], true);
        $this->assertStringContainsString('qf_pool_login_done', $body);

        // The guard belongs to block_kursfilter; this proves it holds in the combined installation.
        foreach (['/user/profile.php', '/user/preferences.php', '/login/change_password.php'] as $path) {
            $this->preview_request([], false, $path);
            $this->assertSame($this->baseurl . '/', $this->redirecturl, $path);
        }
        $this->preview_request([], false, '/my/');
        $this->assertNotSame($this->baseurl . '/', $this->redirecturl);

        $this->start_new_visitor();
        $user = $this->getDataGenerator()->create_user(['password' => $this->password]);
        $this->login_reviewer($user);
        $body = $this->preview_request([], false, '/user/profile.php');
        $this->assertSame('', $this->redirecturl);
        $this->assertStringContainsString(fullname($user), $body);
    }

    public function test_preview_of_a_question_the_visitor_cannot_see_takes_no_pool_account(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $hidden = $generator->create_question('truefalse', null, ['category' => $category->id]);
        $sesskey = $this->visitor_sesskey();

        $body = $this->preview_request(['qid' => $hidden->id, 'sesskey' => $sesskey], true);

        $this->assertStringContainsString(get_string('invalidquestion', 'block_fragenfilter'), $body);
        $this->assertStringNotContainsString('qf_pool_login_done', $body);
        $this->assertSame(0, $this->count_pool_sessions());
    }

    public function test_preview_of_a_question_a_real_account_cannot_see_keeps_the_login_and_skips_the_preview(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $hidden = $generator->create_question('truefalse', null, ['category' => $category->id]);
        $user = $this->getDataGenerator()->create_user(['password' => $this->password]);
        $sesskey = $this->login_reviewer($user);

        $body = $this->preview_request(['qid' => $hidden->id, 'sesskey' => $sesskey], true);

        $this->assertStringContainsString(get_string('invalidquestion', 'block_fragenfilter'), $body);
        $this->assertStringNotContainsString('/question/bank/previewquestion/preview.php', $body);
        $this->assertSame(0, $this->count_pool_sessions());
        $this->assertStringContainsString('"sesskey":"' . $sesskey . '"', $this->preview_request([], false, '/my/'));
    }

    public function test_preview_of_an_unknown_question_takes_no_pool_account(): void {
        $sesskey = $this->visitor_sesskey();

        $body = $this->preview_request(['qid' => 999999999, 'sesskey' => $sesskey], true);

        $this->assertStringContainsString(get_string('invalidquestion', 'block_fragenfilter'), $body);
        $this->assertSame(0, $this->count_pool_sessions());
    }

    /**
     * Logs a fixture user into the real HTTP session.
     *
     * @param \stdClass $user User with the test password.
     * @return string Session key of the logged-in user.
     */
    private function login_reviewer(\stdClass $user): string {
        $page = $this->preview_request([], false, '/login/index.php');
        $this->assertSame(1, preg_match('/name="logintoken" value="([^"]+)"/', $page, $matches));
        $this->preview_request([
            'username' => $user->username,
            'password' => $this->password,
            'logintoken' => $matches[1],
        ], true, '/login/index.php');
        $page = $this->preview_request([], false, '/my/');
        $this->assertSame(1, preg_match('/"sesskey":"([A-Za-z0-9]+)"/', $page, $matches));
        return $matches[1];
    }

    public function test_review_requires_capability_post_and_sesskey_before_any_write(): void {
        global $DB;

        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user(['password' => $this->password]);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question('truefalse', null, ['category' => $category->id]);
        $pendingid = $DB->insert_record('block_fragenfilter_tag_pending', (object)[
            'questionid' => $question->id, 'tags' => 'Mathematik', 'status' => 'pending',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $anonymous = $this->preview_request([], false, '/blocks/fragenfilter/review.php');
        $this->assertStringNotContainsString('Mathematik', $anonymous);
        $this->assertStringContainsString('/login/index.php', $anonymous);
        $sesskey = $this->login_reviewer($user);
        $before = [];
        foreach (['block_fragenfilter_tag_pending', 'tag', 'tag_instance', 'question'] as $table) {
            $before[$table] = $DB->get_records($table, null, 'id');
        }
        $path = '/blocks/fragenfilter/review.php';
        foreach (['', 'approve', 'reject', 'approveall', 'rejectall'] as $action) {
            $body = $this->preview_request(['action' => $action, 'id' => $pendingid, 'sesskey' => $sesskey], true, $path);
            $this->assertStringNotContainsString('Mathematik', $body);
            $this->assertStringContainsString(get_string('nopermissions', 'error', get_string(
                'block_fragenfilter:reviewtags',
                'block_fragenfilter'
            )), $body);
            foreach ($before as $table => $records) {
                $this->assertEquals($records, $DB->get_records($table, null, 'id'), $table);
            }
        }

        $role = $this->getDataGenerator()->create_role();
        foreach (['block/fragenfilter:reviewtags', 'moodle/question:viewall', 'moodle/question:tagall'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $role, \context_system::instance());
        }
        role_assign($role, $user->id, \context_system::instance());
        foreach (['approve', 'reject', 'approveall', 'rejectall'] as $action) {
            foreach ([[false, $sesskey], [true, null], [true, 'wrong12345']] as [$post, $key]) {
                $fields = ['action' => $action, 'id' => $pendingid];
                if ($key !== null) {
                    $fields['sesskey'] = $key;
                }
                $this->preview_request($fields, $post, $path);
                foreach ($before as $table => $records) {
                    $this->assertEquals($records, $DB->get_records($table, null, 'id'), "$action changed $table");
                }
            }
        }
        $body = $this->preview_request([], false, $path);
        $this->assertStringContainsString('Mathematik', $body);
        $this->assertStringContainsString('method="post"', $body);
        $this->preview_request(['action' => 'approve', 'id' => $pendingid, 'sesskey' => $sesskey], true, $path);
        $this->assertSame('applied', $DB->get_field('block_fragenfilter_tag_pending', 'status', ['id' => $pendingid]));
        $this->assertSame(['Mathematik'], array_values(\core_tag_tag::get_item_tags_array(
            'core_question',
            'question',
            $question->id
        )));
    }

    public function test_review_masks_suggestions_and_restricts_single_and_bulk_actions_to_allowed_questions(): void {
        global $DB;

        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user(['password' => $this->password]);
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => $context->id]);
        $own = $generator->create_question('truefalse', null, [
            'category' => $category->id, 'createdby' => $user->id,
        ]);
        $foreign = $generator->create_question('truefalse', null, ['category' => $category->id]);
        $hiddencategory = $generator->create_question_category();
        $hidden = $generator->create_question('truefalse', null, ['category' => $hiddencategory->id]);
        $script = '<script>alert(1)</script>';
        $ids = [];
        foreach ([$own->id, $foreign->id, $hidden->id, 999999999] as $questionid) {
            $ids[$questionid] = $DB->insert_record('block_fragenfilter_tag_pending', (object)[
                'questionid' => $questionid, 'tags' => $script, 'status' => 'pending',
                'timecreated' => time(), 'timemodified' => time(),
            ]);
        }
        \core_tag_tag::set_item_tags('core_question', 'question', $own->id, $context, ['Existing']);
        $role = $this->getDataGenerator()->create_role();
        assign_capability('block/fragenfilter:reviewtags', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $user->id, \context_system::instance());
        // Ownership permits tagging only the reviewer's own question, even with viewall.
        assign_capability('moodle/question:viewall', CAP_ALLOW, $role, $context);
        assign_capability('moodle/question:tagmine', CAP_ALLOW, $role, $context);
        $sesskey = $this->login_reviewer($user);
        $path = '/blocks/fragenfilter/review.php';
        $page = $this->preview_request([], false, $path);
        $this->assertStringContainsString(s($script), $page);
        $this->assertStringNotContainsString($script, $page);
        foreach ([$foreign->id, $hidden->id, 999999999] as $questionid) {
            $before = [];
            foreach (['block_fragenfilter_tag_pending', 'tag', 'tag_instance'] as $table) {
                $before[$table] = $DB->get_records($table, null, 'id');
            }
            foreach (['approve', 'reject'] as $action) {
                $page = $this->preview_request([
                    'action' => $action, 'id' => $ids[$questionid], 'sesskey' => $sesskey,
                ], true, $path);
                $this->assertStringContainsString(get_string('error_review_unavailable', 'block_fragenfilter'), $page);
                foreach ($before as $table => $records) {
                    $this->assertEquals($records, $DB->get_records($table, null, 'id'), $table);
                }
            }
        }
        foreach (['reject', 'approveall', 'rejectall'] as $action) {
            $DB->set_field('block_fragenfilter_tag_pending', 'status', 'pending', ['id' => $ids[$own->id]]);
            $this->preview_request(['action' => $action, 'id' => $ids[$own->id], 'sesskey' => $sesskey], true, $path);
            $expected = $action === 'approveall' ? 'applied' : 'rejected';
            $this->assertSame($expected, $DB->get_field('block_fragenfilter_tag_pending', 'status', ['id' => $ids[$own->id]]));
            foreach ([$foreign->id, $hidden->id, 999999999] as $questionid) {
                $this->assertSame(
                    'pending',
                    $DB->get_field('block_fragenfilter_tag_pending', 'status', ['id' => $ids[$questionid]])
                );
            }
        }
        $this->assertContains('Existing', \core_tag_tag::get_item_tags_array('core_question', 'question', $own->id));
        // A stale pending ID cannot be applied again.
        $this->preview_request(['action' => 'approve', 'id' => $ids[$own->id], 'sesskey' => $sesskey], true, $path);
        $this->assertSame('rejected', $DB->get_field('block_fragenfilter_tag_pending', 'status', ['id' => $ids[$own->id]]));
    }

    public function test_anonymous_search_returns_a_visible_question(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question('truefalse', null, [
            'category' => $category->id,
            'name' => 'Visible tracer question',
        ]);

        assign_capability(
            'moodle/question:viewall',
            CAP_ALLOW,
            get_config('core', 'guestroleid'),
            $category->contextid
        );
        $result = $this->ajax([[
            'index' => 0,
            'methodname' => 'block_fragenfilter_search_questions',
            'args' => [
                'search' => 'Visible tracer question',
                'contextid' => \context_system::instance()->id,
            ],
        ]])[0];

        $this->assertFalse($result['error'], json_encode($result));
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame((int)$question->id, $result['data']['questions'][0]['id']);
        $this->assertSame('Visible tracer question', $result['data']['questions'][0]['name']);
    }

    public function test_anonymous_export_after_search_is_rejected_without_data_changes(): void {
        global $DB;

        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question('truefalse', null, ['category' => $category->id]);
        $contextid = \context_system::instance()->id;
        $before = [];
        $tables = ['question', 'question_versions', 'question_bank_entries', 'question_categories',
            'question_answers', 'block_fragenfilter_ratings', 'block_fragenfilter_tag_pending', 'files'];
        foreach ($tables as $table) {
            $before[$table] = $DB->get_records($table, null, 'id');
        }

        // An earlier public search in the same request must not grant a login to the export.
        $responses = $this->ajax([
            ['index' => 0, 'methodname' => 'block_fragenfilter_search_questions', 'args' => ['contextid' => $contextid]],
            ['index' => 1, 'methodname' => 'block_fragenfilter_export_questions', 'args' => [
                'questionids' => (string)$question->id,
                'format' => 'csv',
                'contextid' => $contextid,
            ]],
        ]);

        $this->assertFalse($responses[0]['error'], json_encode($responses));
        $this->assertTrue($responses[1]['error']);
        $this->assertSame('servicerequireslogin', $responses[1]['exception']['errorcode']);
        $this->assertArrayNotHasKey('data', $responses[1]);
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], $DB->get_records($table, null, 'id'), $table . ' changed.');
        }
    }
    public function test_pool_export_checks_each_question_format_and_download_owner(): void {
        global $DB;

        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $this->make_category_visible($category);
        $question = $generator->create_question('truefalse', null, [
            'category' => $category->id, 'name' => 'Allowed export example',
        ]);
        $hiddencategory = $generator->create_question_category();
        $hidden = $generator->create_question('truefalse', null, [
            'category' => $hiddencategory->id, 'name' => 'Secret export example',
        ]);
        $role = $DB->get_field('role', 'id', ['shortname' => preview_role::SHORTNAME], MUST_EXIST);
        assign_capability('moodle/question:viewall', CAP_ALLOW, $role, $category->contextid);
        set_config('exportformats', 'csv', 'block_fragenfilter');
        $sesskey = $this->visitor_sesskey();
        $body = $this->preview_request(['qid' => $question->id, 'sesskey' => $sesskey], true);
        $this->assertStringContainsString('qf_pool_login_done', $body);
        $sesskey = $this->visitor_sesskey();
        $contextid = \context_system::instance()->id;
        $args = ['questionids' => (string)$question->id, 'format' => 'csv', 'contextid' => $contextid];
        $call = fn($args) => $this->ajax([[
            'index' => 0, 'methodname' => 'block_fragenfilter_export_questions', 'args' => $args,
        ]], $sesskey)[0];
        $result = $call($args);
        $this->assertFalse($result['error'], json_encode($result));
        $this->assertSame(1, $result['data']['count']);
        $url = $result['data']['url'];
        $path = substr($url, strlen($this->baseurl));
        $download = $this->preview_request([], false, $path);
        $this->assertStringContainsString('Allowed export example', $download);
        $this->assertStringNotContainsString('Secret export example', $download);
        $before = [];
        foreach (['question', 'question_answers', 'block_fragenfilter_ratings', 'files'] as $table) {
            $before[$table] = $DB->get_records($table, null, 'id');
        }
        foreach (
            [
            array_replace($args, ['questionids' => (string)$hidden->id]),
            array_replace($args, ['questionids' => $question->id . ',' . $hidden->id, 'contextid' => $category->contextid]),
            array_replace($args, ['format' => 'xml']),
            array_replace($args, ['format' => 'unknown']),
            ] as $invalid
        ) {
            $rejected = $call($invalid);
            $this->assertTrue($rejected['error'], json_encode($rejected));
            $this->assertArrayNotHasKey('data', $rejected);
            foreach ($before as $table => $records) {
                $this->assertEquals($records, $DB->get_records($table, null, 'id'), $table);
            }
        }
        // Use the authorised session so an unrelated owner check cannot hide a broken path check.
        $this->assertStringContainsString('Allowed export example', $this->preview_request([], false, $path));
        $this->assertNotSame($contextid, (int)$category->contextid);
        $this->assertStringNotContainsString('Allowed export example', $this->preview_request(
            [],
            false,
            str_replace('/pluginfile.php/' . $contextid . '/', '/pluginfile.php/' . $category->contextid . '/', $path)
        ));
        $this->assertStringNotContainsString('Allowed export example', $this->preview_request(
            [],
            false,
            str_replace('/questions_', '/../questions_', $path)
        ));
        // A separate visitor cannot retrieve the first visitor's export.
        $this->cookiejar = '';
        $this->assertStringNotContainsString('Allowed export example', $this->preview_request([], false, $path));
        $other = $this->getDataGenerator()->create_user(['password' => $this->password]);
        role_assign($role, $other->id, $contextid);
        $this->login_reviewer($other);
        $this->assertStringNotContainsString('Allowed export example', $this->preview_request([], false, $path));
    }
    public function test_export_download_is_bound_to_the_exporting_session(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question('truefalse', null, [
            'category' => $category->id, 'name' => 'Session private export',
        ]);
        $user = $this->getDataGenerator()->create_user(['password' => $this->password]);
        $role = create_role('Session exporter', 'sessionexporter', '');
        assign_capability('block/fragenfilter:export', CAP_ALLOW, $role, 1);
        assign_capability('moodle/question:viewall', CAP_ALLOW, $role, $category->contextid);
        role_assign($role, $user->id, 1);
        $sesskey = $this->login_reviewer($user);
        $result = $this->ajax([[
            'index' => 0, 'methodname' => 'block_fragenfilter_export_questions',
            'args' => ['questionids' => (string)$question->id, 'format' => 'csv', 'contextid' => 1],
        ]], $sesskey)[0];
        $this->assertFalse($result['error'], json_encode($result));
        $path = substr($result['data']['url'], strlen($this->baseurl));
        $this->assertStringContainsString('Session private export', $this->preview_request([], false, $path));
        $this->cookiejar = '';
        $this->login_reviewer($user);
        $this->assertStringNotContainsString('Session private export', $this->preview_request([], false, $path));
    }
}
