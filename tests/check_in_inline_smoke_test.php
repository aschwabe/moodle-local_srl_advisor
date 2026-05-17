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

        $payload = $this->decode_jwt_payload($parts[1]);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertSame(30, (int)$payload['exp'] - (int)$payload['iat']);
        // section_id default is null — preserves backward-compatible payload shape.
        $this->assertArrayHasKey('section_id', $payload);
        $this->assertNull($payload['section_id']);
    }

    public function test_jwt_launch_override_uses_300_second_ttl_and_section_id() {
        // DEC-043: launch.php delegates to local_srl_advisor_build_jwt with
        // ttl=300 + non-null section_id. Same canonical mint, different
        // claim values. Locks the launch-flow override against drift.
        $this->resetAfterTest();
        $jwt = local_srl_advisor_build_jwt(42, 'pseudo', 'secret', 300, 7);
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);

        $payload = $this->decode_jwt_payload($parts[1]);
        $this->assertSame(300, (int)$payload['exp'] - (int)$payload['iat']);
        $this->assertSame(7, $payload['section_id']);
    }

    public function test_jwt_emits_urlsafe_base64_segments() {
        // DEC-043 / DEC-039: every segment must be RFC 7515 URL-safe base64
        // (no '+', '/', or '=' padding). Backend PyJWT decoder is tolerant of
        // standard base64 but standardising both ends closes the cross-impl
        // fragility latent in pre-DEC-043 launch.php.
        $this->resetAfterTest();
        $jwt = local_srl_advisor_build_jwt(42, 'pseudo', 'secret', 300, 7);
        foreach (explode('.', $jwt) as $segment) {
            $this->assertStringNotContainsString('+', $segment);
            $this->assertStringNotContainsString('/', $segment);
            $this->assertStringNotContainsString('=', $segment);
        }
    }

    /** URL-safe base64 → JSON-decoded payload claim array. */
    private function decode_jwt_payload(string $segment): array {
        $padded = strtr($segment, '-_', '+/') . str_repeat('=', (4 - strlen($segment) % 4) % 4);
        $decoded = json_decode(base64_decode($padded), true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    public function test_get_pending_check_in_requires_login() {
        $this->resetAfterTest();
        // No session — Moodle's external_api login-required wrapper should throw.
        $this->expectException(require_login_exception::class);
        \local_srl_advisor\external\get_pending_check_in::execute(1, 1);
    }
}
