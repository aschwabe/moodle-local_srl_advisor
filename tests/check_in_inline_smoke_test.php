<?php
/**
 * PHPUnit smoke tests for v1.1 inline check-in plumbing (DEC-031).
 *
 * Runs inside a Moodle PHPUnit harness:
 *   php admin/tool/phpunit/cli/util.php --install
 *   vendor/bin/phpunit --filter local_srl_advisor local/srl_advisor/tests
 *
 * Covers:
 *   - relay helper rejects non-/api/v1 paths (defensive)
 *   - relay helper reports error_kind='transport' when backend_url empty
 *   - JWT builder honours the DEC-031 30s TTL
 *   - external functions reject unauthenticated AJAX callers
 *
 * Functional integration tests (live backend, real Moodle session) live in
 * the manual UAT runbook for slice 8; this file is the cheap regression net.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/srl_advisor/lib.php');

class local_srl_advisor_check_in_inline_smoke_test extends advanced_testcase {

    public function test_relay_helper_refuses_non_api_v1_path() {
        $this->resetAfterTest();
        $result = local_srl_advisor_relay_backend_call(
            'inline_get', '/some/other/path', 'GET', null, 'dummy.jwt'
        );
        $this->assertFalse($result['ok']);
        $this->assertSame('transport', $result['error_kind']);
    }

    public function test_relay_helper_reports_transport_when_backend_url_empty() {
        $this->resetAfterTest();
        // Ensure the plugin is unconfigured for this test.
        set_config('backend_url', '', 'local_srl_advisor');

        $result = local_srl_advisor_relay_backend_call(
            'inline_get', '/api/v1/check-in', 'GET', null, 'dummy.jwt'
        );
        $this->assertFalse($result['ok']);
        $this->assertSame('transport', $result['error_kind']);
    }

    public function test_jwt_uses_30_second_ttl() {
        $this->resetAfterTest();
        $jwt = local_srl_advisor_build_jwt(42, 'pseudo', 'secret');
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);

        $payload = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4)),
            true
        );
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertSame(30, (int)$payload['exp'] - (int)$payload['iat']);
    }

    public function test_get_pending_check_in_requires_login() {
        $this->resetAfterTest();
        // No session — Moodle's external_api login-required wrapper should throw.
        $this->expectException(require_login_exception::class);
        \local_srl_advisor\external\get_pending_check_in::execute(1, 1);
    }
}
