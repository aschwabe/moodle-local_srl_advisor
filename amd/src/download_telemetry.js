/* eslint-env browser, amd */
/**
 * Download-click telemetry (LAB-004 MVP, DEC-019/DEC-035/DEC-057).
 *
 * Listens for clicks on download-bearing anchors and emits `download.clicked`
 * events to the L1 event log. Mounted in two contexts via lib.php:
 *
 *   - course-view-*  : captures mod-resource activity-link clicks from the
 *                      section/course homepage (PDF / slides / docx attached
 *                      to a Resource module).
 *   - mod-page-view  : captures embedded `pluginfile.php/...` links inside
 *                      Page content (downloads referenced from within a
 *                      Page activity).
 *
 * Privacy contract: only file extension is captured; never the filename,
 * never the URL, never the destination. The `file_type` payload field is
 * an extension token (lowercased, alphanumeric only, capped at 8 chars)
 * or 'other' when no extension is parseable.
 *
 * Batched flush every 5s, same pattern as scroll_telemetry.
 */
define([
    'core/ajax'
], function(Ajax) {

    'use strict';

    const FLUSH_INTERVAL_MS = 5000;
    const FILE_TYPE_MAX_LEN = 8;

    /**
     * RFC 4122 v4 UUID using the browser crypto API where available; falls
     * back to Math.random for environments that don't ship crypto.getRandomValues.
     *
     * @return {string} A UUID v4 string.
     */
    function uuidv4() {
        if (window.crypto && window.crypto.getRandomValues) {
            const bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            /* eslint-disable no-bitwise */
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            /* eslint-enable no-bitwise */
            const hex = [];
            for (let i = 0; i < bytes.length; i++) {
                hex.push((bytes[i] < 16 ? '0' : '') + bytes[i].toString(16));
            }
            return (
                hex.slice(0, 4).join('') + '-' +
                hex.slice(4, 6).join('') + '-' +
                hex.slice(6, 8).join('') + '-' +
                hex.slice(8, 10).join('') + '-' +
                hex.slice(10, 16).join('')
            );
        }
        return 'xxxxxxxxxxxx4xxxyxxxxxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            /* eslint-disable no-bitwise */
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            /* eslint-enable no-bitwise */
            return v.toString(16);
        });
    }

    /**
     * Calculate the current scroll depth as a percentage of the total
     * scrollable page height.
     *
     * @return {number} Scroll depth percentage (0–100, integer).
     */
    function scrollDepthPct() {
        const doc = document.documentElement;
        const body = document.body;
        const scrolled = window.scrollY || doc.scrollTop || body.scrollTop || 0;
        const viewport = window.innerHeight || doc.clientHeight;
        const total = Math.max(
            doc.scrollHeight, body.scrollHeight,
            doc.offsetHeight, body.offsetHeight
        ) - viewport;
        if (total <= 0) {
            return 0;
        }
        return Math.max(0, Math.min(100, Math.round((scrolled / total) * 100)));
    }

    /**
     * Return the current time as an ISO 8601 string.
     *
     * @return {string} Current timestamp in ISO 8601 format.
     */
    function nowIso() {
        return new Date().toISOString();
    }

    /**
     * Extract a sanitized file-extension token from an href. Returns 'other'
     * when no parseable extension exists. Strips query strings and fragments
     * before parsing; lowercases; truncates to FILE_TYPE_MAX_LEN; rejects
     * non-alphanumeric extensions defensively.
     *
     * Special case: `/mod/resource/view.php` is Moodle's intermediary URL
     * that redirects to the actual file. The href extension is always 'php'
     * here, which is misleading for analysts who'd interpret it as the file
     * type. Return the sentinel 'resource' instead — joins against
     * `mdl_files` server-side recover the real extension when needed.
     *
     * @param {string} href  The anchor href to extract the file type from.
     * @return {string} Lowercased alphanumeric extension token, or 'other'/'resource'.
     */
    function fileTypeFromHref(href) {
        if (!href || typeof href !== 'string') {
            return 'other';
        }
        if (href.indexOf('/mod/resource/view.php') !== -1) {
            return 'resource';
        }
        let path = href.split('?')[0].split('#')[0];
        const lastSlash = path.lastIndexOf('/');
        if (lastSlash >= 0) {
            path = path.substring(lastSlash + 1);
        }
        const dotIdx = path.lastIndexOf('.');
        if (dotIdx < 0 || dotIdx === path.length - 1) {
            return 'other';
        }
        let ext = path.substring(dotIdx + 1).toLowerCase();
        if (ext.length > FILE_TYPE_MAX_LEN) {
            ext = ext.substring(0, FILE_TYPE_MAX_LEN);
        }
        if (!/^[a-z0-9]+$/.test(ext)) {
            return 'other';
        }
        return ext;
    }

    /**
     * Decide whether a clicked anchor is a download we care about.
     *
     * Match patterns:
     *   - href contains 'pluginfile.php/'  → embedded file in any Moodle content
     *   - anchor has [download] attribute  → explicit HTML5 download anchor
     *   - href contains '/mod/resource/view.php' → mod-resource activity link
     *     (Moodle redirects this to the actual file)
     *
     * @param {HTMLElement} anchor  The anchor element to test.
     * @return {boolean} True when the anchor is a tracked download target.
     */
    function isDownloadAnchor(anchor) {
        if (!anchor || anchor.tagName !== 'A') {
            return false;
        }
        if (anchor.hasAttribute('download')) {
            return true;
        }
        const href = anchor.getAttribute('href') || '';
        if (href.indexOf('pluginfile.php/') !== -1) {
            return true;
        }
        if (href.indexOf('/mod/resource/view.php') !== -1) {
            return true;
        }
        return false;
    }

    return {
        /**
         * Bootstrap entry. Called from lib.php before_footer.
         *
         * @param {Number} courseid    Moodle course id (unused client-side; backend resolves from JWT).
         * @param {Number} sectionid   Moodle course_sections.id; null on course-view (no section context).
         * @param {String} pageType    Moodle $PAGE->pagetype.
         * @param {Number} cmid        Moodle course_modules.id; null on course-view (no cm context).
         */
        init: function(courseid, sectionid, pageType, cmid) {
            try {
                const sessionId = uuidv4();
                const sessionStartMs = Date.now();
                const pending = [];
                let flushTimer = null;

                /**
                 * Flush all pending download events to the backend relay.
                 *
                 * @param {string} reason  Label for debug logging (e.g. 'click', 'pagehide').
                 * @return {void}
                 */
                const flush = function(reason) {
                    if (pending.length === 0) {
                        return;
                    }
                    const batch = pending.splice(0, pending.length);
                    Ajax.call([{
                        methodname: 'local_srl_advisor_record_behavior_events',
                        args: {courseid: courseid, events: JSON.stringify(batch)}
                    }])[0].fail(function(err) {
                        if (window.console && window.console.warn) {
                            window.console.warn('local_srl_advisor[download]: flush failed (' + reason + ')', err);
                        }
                    });
                };

                /**
                 * Handle a document click event, walking up the DOM to find the
                 * nearest anchor and enqueuing a download.clicked event when it
                 * matches a tracked download pattern.
                 *
                 * @param {MouseEvent} ev  The click event.
                 * @return {void}
                 */
                const onClick = function(ev) {
                    let target = ev.target;
                    // Walk up to find the nearest anchor (handles clicks on child <span>, <i>, etc.).
                    while (target && target !== document.body && target.tagName !== 'A') {
                        target = target.parentNode;
                    }
                    if (!target || target.tagName !== 'A') {
                        return;
                    }
                    if (!isDownloadAnchor(target)) {
                        return;
                    }
                    const href = target.getAttribute('href') || '';
                    pending.push({
                        verb: 'download.clicked',
                        occurred_at: nowIso(),
                        session_id: sessionId,
                        moodle_cm_id: cmid || null,
                        correlation_id: null,
                        idempotency_key: null,
                        payload_schema_version: '1.0',
                        payload: {
                            file_type: fileTypeFromHref(href),
                            scroll_depth_pct: scrollDepthPct(),
                            time_on_page_ms: Date.now() - sessionStartMs
                        }
                    });
                    // Flush immediately on click — navigation away will kill the page
                    // before the next interval. Backend tolerates 1-event batches.
                    flush('click');
                };

                document.addEventListener('click', onClick, true);

                window.addEventListener('pagehide', function() {
                    flush('pagehide');
                });
                window.addEventListener('visibilitychange', function() {
                    if (document.visibilityState === 'hidden') {
                        flush('visibility_hidden');
                    }
                });

                flushTimer = window.setInterval(function() {
                    flush('interval');
                }, FLUSH_INTERVAL_MS);

                return flushTimer;
            } catch (e) {
                if (window.console && window.console.warn) {
                    window.console.warn('local_srl_advisor[download]: init failed', e);
                }
                return null;
            }
        }
    };
});
