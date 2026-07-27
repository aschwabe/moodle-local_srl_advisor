/* eslint-env browser, amd */
/**
 * Clipboard-copy telemetry (LAB-005 MVP, DEC-019/DEC-035/DEC-057).
 *
 * Listens for the document-level `copy` event and emits `clipboard.copied`
 * events to the L1 event log. Mounted on `mod-page-view` only (same surface
 * as scroll + video) — captures the most common case of students lifting
 * reading content for notes or external lookup.
 *
 * Privacy contract: ONLY selection length is captured. Never the selected
 * text. Never partial text. Never the URL of the copied source. The
 * `selection_length` field is a non-negative integer.
 *
 * Throttle: copy events that fire within COPY_THROTTLE_MS of the previous
 * copy are coalesced (the most recent payload wins). Defends against double-
 * fire from triple-click + ctrl-c bursts and keeps event rate sane.
 *
 * Batched flush every 5s, same pattern as scroll_telemetry.
 */
define([
    'core/ajax'
], function(Ajax) {

    'use strict';

    const FLUSH_INTERVAL_MS = 5000;
    const COPY_THROTTLE_MS = 500;

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
     * Read selection length without retaining the selected text.
     * Falls back to 0 when selection API is unavailable.
     *
     * @return {number} Length of the current text selection in characters.
     */
    function selectionLength() {
        try {
            const sel = window.getSelection ? window.getSelection() : null;
            if (!sel) {
                return 0;
            }
            const s = sel.toString();
            const len = s ? s.length : 0;
            // No retention: the local `s` falls out of scope at function end.
            return len;
        } catch (e) {
            return 0;
        }
    }

    return {
        /**
         * Bootstrap entry. Called from lib.php before_footer on mod-page-view.
         *
         * @param {Number} courseid    Moodle course id (unused client-side; backend resolves from JWT).
         * @param {Number} sectionid   Moodle course_sections.id.
         * @param {String} pageType    Moodle $PAGE->pagetype.
         * @param {Number} cmid        Moodle course_modules.id of the current page.
         */
        init: function(courseid, sectionid, pageType, cmid) {
            try {
                const sessionId = uuidv4();
                const sessionStartMs = Date.now();
                const pending = [];
                let lastCopyMs = 0;
                let flushTimer = null;

                /**
                 * Flush all pending clipboard events to the backend relay.
                 *
                 * @param {string} reason  Label for debug logging (e.g. 'interval', 'pagehide').
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
                            window.console.warn('local_srl_advisor[clipboard]: flush failed (' + reason + ')', err);
                        }
                    });
                };

                /**
                 * Handle a document copy event, throttling burst copies and
                 * enqueuing a clipboard.copied event in the pending batch.
                 *
                 * @return {void}
                 */
                const onCopy = function() {
                    const now = Date.now();
                    if (now - lastCopyMs < COPY_THROTTLE_MS) {
                        // Throttle: replace the last pending copy event's payload
                        // rather than enqueue a new one. Burst collapses to one.
                        const last = pending.length > 0 ? pending[pending.length - 1] : null;
                        if (last && last.verb === 'clipboard.copied') {
                            last.occurred_at = nowIso();
                            last.payload.selection_length = selectionLength();
                            last.payload.scroll_depth_pct = scrollDepthPct();
                            last.payload.time_on_page_ms = now - sessionStartMs;
                            return;
                        }
                    }
                    lastCopyMs = now;
                    pending.push({
                        verb: 'clipboard.copied',
                        occurred_at: nowIso(),
                        session_id: sessionId,
                        moodle_cm_id: cmid || null,
                        correlation_id: null,
                        idempotency_key: null,
                        payload_schema_version: '1.0',
                        payload: {
                            selection_length: selectionLength(),
                            scroll_depth_pct: scrollDepthPct(),
                            time_on_page_ms: now - sessionStartMs
                        }
                    });
                };

                document.addEventListener('copy', onCopy, true);

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
                    window.console.warn('local_srl_advisor[clipboard]: init failed', e);
                }
                return null;
            }
        }
    };
});
