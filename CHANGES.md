# Changelog — local_srl_advisor

All notable changes to this plugin are documented here. Versions are the
`$plugin->version` date-stamp from `version.php`.

## v0.5.0-alpha — 2026062200 (DEC-062)

- **Capability gate.** Added `db/access.php` declaring `local/srl_advisor:participate`
  (student archetype). All student-facing access checks now use
  `has_capability()` instead of `is_enrolled()`, so the gate is visible in the
  role-permission UI and can be granted to non-enrolled researchers/auditors.
  Supersedes the DEC-047/049 enrolment-gate deviation.
- **Privacy API.** Replaced the incorrect `null_provider` with an
  external-location metadata provider (`add_external_location_link`), accurately
  declaring the data sent to the SRL Advisor backend. Required for Plugins
  Directory submission.
- Added `db/uninstall.php` to clean up capability rows on uninstall.
- Added `README.md`, `CHANGES.md`, and `LICENSE`.
- Added a PHPUnit test locking the capability-gate wiring.

## v0.4.0-alpha — 2026052602 (DEC-056..060)

- Behavioural telemetry (scroll/video/clipboard/download) relayed to backend.
- Summative end-of-course survey banner.
- Navbar pending-count session cache tuned to 5s (DEC-059).
- Download-telemetry `file_type` sentinel fix for `/mod/resource/view.php` (DEC-057).

## v1.1.x — 2026-05 (DEC-031, DEC-048)

- Inline pre/post learning-strategy check-ins (dropdown panel, section-scoped).
- Public-backend-URL split for browser vs server reachability (DEC-048).

## v0.x — earlier

- Initial launch bridge (signed JWT, pseudonymous id), navbar action-item badge,
  consent-gated sync web service.
