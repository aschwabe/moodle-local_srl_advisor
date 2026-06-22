# SRL Advisor — Moodle local plugin (`local_srl_advisor`)

A lightweight launcher and check-in plugin that connects a Moodle course to the
**SRL Advisor** self-regulated-learning (SRL) backend. It surfaces short
learning-strategy check-ins inside course activities, shows a navbar action-item
badge, and bridges enrolled students to their personalised SRL Advisor portal —
without storing any personal data in Moodle.

- **Component:** `local_srl_advisor`
- **Maturity:** alpha
- **Requires:** Moodle 4.5+ (`2024100700`) — supports 4.5 LTS, 5.0, 5.1, 5.2
- **License:** [GNU GPL v3 or later](https://www.gnu.org/copyleft/gpl.html)

## What it does

- **Launch bridge** (`launch.php`) — mints a short-lived signed JWT carrying a
  *salted hash* of the student's user id plus the course id, then POST-redirects
  the student to the SRL Advisor web app. No name or email is ever transmitted.
- **Inline check-ins** — a compact pre/post learning-strategy prompt rendered at
  the foot of activity pages in enabled courses (Moodle 4.5 output hook).
- **Navbar badge** — a graduation-cap link in the user menu showing the count of
  pending action items for the current course.
- **Behavioural telemetry** — optional, consent-gated scroll/video/clipboard/
  download events relayed to the backend to inform strategy prompts.

All student-facing features are gated on the `local/srl_advisor:participate`
capability (granted to the `student` archetype by default) **and** an admin
allowlist of course ids, so the plugin is inert until explicitly enabled.

## Installation

1. Copy this directory to `local/srl_advisor` in your Moodle root, **or** install
   the packaged zip via *Site administration → Plugins → Install plugins*.
2. Visit *Site administration → Notifications* to run the upgrade and register the
   `local/srl_advisor:participate` capability.
3. Configure the plugin (below).

## Configuration

*Site administration → Plugins → Local plugins → SRL Advisor.*

| Setting | Required | Description |
|---|---|---|
| **Backend URL** | Yes | Server-to-server base URL Moodle uses to reach the SRL Advisor app (no trailing slash). |
| **Public URL** | No | Browser-facing base URL for launch redirects. Set only when the Moodle host reaches the backend over an internal hostname the student's browser cannot resolve. Falls back to **Backend URL**. |
| **Organization API Token** | Yes | Per-institution token from the SRL Advisor Superadmin portal; signs the JWTs. |
| **Enabled Course IDs** | Yes | Comma-separated Moodle course ids where the plugin activates (e.g. `2,3,7`). All other courses show nothing. |
| **Allow insecure SSL** | No | **Dev only.** Disables SSL verification on backend calls for self-signed dev certs. Leave **off** in production. |

The plugin renders nothing until **Backend URL**, **API Token**, and at least one
**Enabled Course ID** are set.

## Privacy

The plugin stores **no personal data in the Moodle database**. It transmits to the
external SRL Advisor backend: a salted SHA-256 hash of the user id, the course id,
behavioural telemetry events, and learning-strategy / reflection responses. These
are declared via Moodle's Privacy API (`classes/privacy/provider.php`,
external-location provider). See the privacy details on the plugin's settings page.

## Web services

The plugin defines AJAX-only external functions (login required), used by its own
JavaScript modules — not intended for third-party use:

- `local_srl_advisor_get_pending_check_in`
- `local_srl_advisor_submit_check_in`
- `local_srl_advisor_dismiss_check_in`
- `local_srl_advisor_record_behavior_events`
- `local_srl_advisor_get_enrolled_users` (teacher-facing; requires
  `moodle/course:viewparticipants`)

## Testing

```bash
# From your Moodle root, with the PHPUnit harness initialised:
php admin/tool/phpunit/cli/util.php --install
vendor/bin/phpunit --filter local_srl_advisor local/srl_advisor/tests
```

## Source & issues

- Source: https://github.com/aschwabe/moodle-srl_advisor
- Issues: https://github.com/aschwabe/moodle-srl_advisor/issues
