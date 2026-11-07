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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Connector behaviour against a real unmanaged HTTP peer.
 *
 * @package block_fragenfilter
 * @copyright 2026 Moodle in Niedersachsen e. V.
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class ai_connector_test extends \advanced_testcase {
    /** @var resource|null Test peer process. */
    private $server = null;
    /** @var string Temporary request receipt. */
    private string $receipt = '';
    /** @var string Temporary TLS certificate/key. */
    private string $certificate = '';

    /** @var string Temporary peer log. */
    private string $serverlog = '';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    protected function tearDown(): void {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach ([$this->receipt, $this->receipt . '.followed', $this->serverlog, $this->certificate] as $path) {
            if ($path && file_exists($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    /**
     * Starts an independent local HTTP service with a fixed response scenario.
     *
     * @param string $mode Response scenario.
     * @return string Peer URL.
     */
    private function start_peer(string $mode): string {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($socket, $error);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->receipt = tempnam(sys_get_temp_dir(), 'qf-ai-request');
        $this->serverlog = tempnam(sys_get_temp_dir(), 'qf-ai-log');
        $command = [
            PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/ai_server.php',
        ];
        if ($mode === 'tls') {
            $key = openssl_pkey_new(['private_key_bits' => 2048]);
            $csr = openssl_csr_new(['commonName' => 'localhost'], $key);
            $certificate = openssl_csr_sign($csr, null, $key, 1);
            openssl_x509_export($certificate, $pem);
            openssl_pkey_export($key, $privatekey);
            $this->certificate = tempnam(sys_get_temp_dir(), 'qf-ai-cert');
            file_put_contents($this->certificate, $pem . $privatekey);
            $command = [PHP_BINARY, __DIR__ . '/fixtures/ai_tls_server.php', $address, $this->certificate];
        }
        // phpcs:ignore moodle.Commenting.InlineComment.NotCapital -- Semgrep requires a lowercase directive for the fixed test process.
        // nosemgrep: moodle-gefaehrliche-funktion -- Test-only unmanaged peer; argv array, no shell or user input.
        $this->server = proc_open([
            'env', 'QUESTIONFILTER_AI_FIXTURE=1', 'QUESTIONFILTER_AI_MODE=' . $mode,
            'QUESTIONFILTER_AI_RECEIPT=' . $this->receipt,
            ...$command,
        ], [0 => ['pipe', 'r'], 1 => ['file', $this->serverlog, 'a'], 2 => ['file', $this->serverlog, 'a']], $pipes);
        $this->assertIsResource($this->server);
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($connection) {
                fclose($connection);
                set_config('ai_ollama_url', 'http://' . $address, 'block_fragenfilter');
                return 'http://' . $address;
            }
            usleep(20000);
        }
        $this->fail('HTTP peer did not start: ' . file_get_contents($this->serverlog));
    }

    public function test_oversized_response_is_rejected_instead_of_becoming_a_tag(): void {
        $this->start_peer('large');
        try {
            (new ai_connector())->suggest_tags('Algebra', 'Solve x + 1 = 2.', 'shortanswer');
            $this->fail('The oversized response was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_ai_response_limit', $e->errorcode);
        }
    }

    public function test_normal_response_is_deduplicated_and_prompt_is_stripped_and_truncated(): void {
        $this->start_peer('normal');
        set_config('ai_max_tags', 999, 'block_fragenfilter');
        $tags = (new ai_connector())->suggest_tags('Algebra', '<p>' . str_repeat('x', 1000) . '</p>', 'shortanswer');
        $this->assertSame(['Mathematik', 'Algebra'], $tags);
        $request = json_decode(file_get_contents($this->receipt), true, 512, JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(16384, strlen(file_get_contents($this->receipt)));
        $this->assertStringContainsString('at most 10', $request['prompt']);
        $this->assertStringContainsString(str_repeat('x', 800), $request['prompt']);
        $this->assertStringNotContainsString(str_repeat('x', 801), $request['prompt']);
        $this->assertStringNotContainsString('<p>', $request['prompt']);
    }

    public function test_untrusted_tags_are_filtered_by_length_and_control_characters_and_capped(): void {
        $this->start_peer('many');
        $tags = (new ai_connector())->suggest_tags('Algebra', 'Solve x + 1 = 2.', 'shortanswer');
        $this->assertCount(5, $tags);
        $this->assertSame(['Tag1', 'Tag2', 'Tag3', 'Tag4', 'Tag5'], $tags);
        set_config('ai_max_tags', 999, 'block_fragenfilter');
        $tags = (new ai_connector())->suggest_tags('Algebra', 'Solve x + 1 = 2.', 'shortanswer');
        $this->assertCount(10, $tags);
        $this->assertSame('Tag10', end($tags));
    }

    public function test_oversized_request_never_reaches_the_provider(): void {
        $this->start_peer('normal');
        try {
            (new ai_connector())->suggest_tags(str_repeat('x', 20000), 'Question', 'shortanswer');
            $this->fail('An oversized request was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_ai_request_limit', $e->errorcode);
        }
        $this->assertSame('', file_get_contents($this->receipt));
    }

    /**
     * Invalid peer responses are not silently recorded as empty suggestions.
     *
     * @param string $mode Peer response scenario.
     * @param string $error Expected public error code.
     */
    #[DataProvider('invalid_response_provider')]
    public function test_invalid_provider_response_fails_visibly(string $mode, string $error): void {
        $this->start_peer($mode);
        try {
            (new ai_connector())->suggest_tags('Algebra', 'Solve x + 1 = 2.', 'shortanswer');
            $this->fail('The invalid response was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame($error, $e->errorcode);
        }
        $this->assertFileDoesNotExist($this->receipt . '.followed');
    }

    /**
     * Provides malformed, redirect and HTTP error cases.
     *
     * @return array Named response scenarios.
     */
    public static function invalid_response_provider(): array {
        return [
            'invalid structure' => ['invalid', 'error_ai_response'],
            'invalid JSON' => ['malformed', 'error_ai_response'],
            'redirect' => ['redirect', 'error_ai_transport'],
            'HTTP error' => ['error', 'error_ai_transport'],
        ];
    }

    public function test_slow_provider_is_interrupted_by_the_transport_timeout(): void {
        $this->start_peer('slow');
        $start = microtime(true);
        try {
            (new ai_connector())->suggest_tags('Algebra', 'Solve x + 1 = 2.', 'shortanswer');
            $this->fail('The slow request was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_ai_transport', $e->errorcode);
        }
        $elapsed = microtime(true) - $start;
        $this->assertGreaterThan(28, $elapsed);
        $this->assertLessThan(32, $elapsed);
    }

    public function test_moodle_blocked_host_does_not_receive_question_data(): void {
        $url = $this->start_peer('normal');
        set_config('curlsecurityblockedhosts', "127.0.0.0/8");
        try {
            (new ai_connector())->suggest_tags('Algebra', 'Private question', 'shortanswer');
            $this->fail('The blocked host was contacted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_ai_transport', $e->errorcode);
        }
        $this->assertDebuggingCalled('Blocked ' . $url . '/api/generate: The URL is blocked. [user 0]', DEBUG_NONE);
        $this->assertSame('', file_get_contents($this->receipt));
    }

    /**
     * Targets that are not plain HTTP(S) service URLs never reach a peer.
     *
     * @param string $url Configured Ollama URL.
     */
    #[DataProvider('invalid_target_provider')]
    public function test_invalid_target_is_rejected_without_a_request(string $url): void {
        $address = substr($this->start_peer('normal'), strlen('http://'));
        set_config('ai_ollama_url', str_replace('{peer}', $address, $url), 'block_fragenfilter');
        try {
            (new ai_connector())->suggest_tags('Algebra', 'Private question', 'shortanswer');
            $this->fail('The invalid target was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_ai_target', $e->errorcode);
        }
        $this->assertSame('', file_get_contents($this->receipt));
    }

    /**
     * Provides rejected target shapes.
     *
     * @return array Named URLs; {peer} is replaced by the running test peer.
     */
    public static function invalid_target_provider(): array {
        return [
            'file scheme' => ['file:///etc/passwd'],
            'credentials' => ['http://user:secret@{peer}'],
            'query' => ['http://{peer}?key=secret'],
            'fragment' => ['http://{peer}#frag'],
        ];
    }

    public function test_untrusted_tls_certificate_does_not_receive_question_data(): void {
        $url = $this->start_peer('tls');
        set_config('ai_ollama_url', str_replace('http://127.0.0.1', 'https://localhost', $url), 'block_fragenfilter');
        try {
            (new ai_connector())->suggest_tags('Algebra', 'Private question', 'shortanswer');
            $this->fail('The untrusted certificate was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_ai_transport', $e->errorcode);
        }
        $this->assertSame('', file_get_contents($this->receipt));
    }

    public function test_trusted_https_provider_returns_tags(): void {
        global $CFG;

        $url = $this->start_peer('tls');
        set_config('ai_ollama_url', str_replace('http://127.0.0.1', 'https://localhost', $url), 'block_fragenfilter');
        $cafile = $CFG->dataroot . '/moodleorgca.crt';
        $this->assertFileDoesNotExist($cafile);
        openssl_x509_export(openssl_x509_read(file_get_contents($this->certificate)), $pem);
        file_put_contents($cafile, $pem);
        try {
            $this->assertSame(['Mathematik'], (new ai_connector())->suggest_tags('Algebra', 'Solve x + 1 = 2.', 'shortanswer'));
        } finally {
            unlink($cafile);
        }
    }

    /**
     * Creates more unprocessed questions than one task run may handle.
     *
     * @param string $text Question text.
     */
    private function create_questions(string $text): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        for ($i = 0; $i < 21; $i++) {
            $generator->create_question('truefalse', null, [
                'category' => $category->id, 'name' => 'Question ' . $i,
                'questiontext' => ['text' => $text, 'format' => FORMAT_HTML],
            ]);
        }
    }

    public function test_disabled_task_sends_nothing_and_stores_no_suggestions(): void {
        global $DB;

        $this->start_peer('normal');
        $this->create_questions('Contact Erika Example at erika@example.invalid.');
        ob_start();
        (new \block_fragenfilter\task\generate_tags())->execute();
        ob_end_clean();
        $this->assertSame('', file_get_contents($this->receipt));
        $this->assertSame(0, $DB->count_records('block_fragenfilter_tag_pending'));
    }

    public function test_enabled_task_limits_the_batch_and_releases_free_text(): void {
        global $DB;

        $this->start_peer('normal');
        $this->create_questions('Contact Erika Example at erika@example.invalid.');
        set_config('ai_enabled', 1, 'block_fragenfilter');
        set_config('ai_batch_size', 999, 'block_fragenfilter');
        ob_start();
        (new \block_fragenfilter\task\generate_tags())->execute();
        ob_end_clean();
        $this->assertSame(20, $DB->count_records('block_fragenfilter_tag_pending', ['status' => 'pending']));
        // Enabling AI releases free text as well: removing IDs does not anonymise it.
        $this->assertStringContainsString('erika@example.invalid', file_get_contents($this->receipt));
    }
}
