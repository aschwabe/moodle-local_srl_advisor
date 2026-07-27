/* eslint-env browser, amd */
/**
 * Scroll-event telemetry (LAB-002 MVP, DEC-019/DEC-035).
 *
 * Observes window scroll on the page-resource view, emits batched events to
 * the backend L1 event log (tbl_behavior_event) via the plugin's relay
 * external function. Throttled + batched so we never sustain >4 events/s
 * client-side and the network spend is one POST every 5s while active.
 *
 * MVP verb subset (DEC-019 §Piece 1):
 *   scroll.session_started — fires once per page load when scroll listener attaches.
 *   scroll.session_ended   — fires on pagehide / visibility hidden.
 *   scroll.back            — fires when a scroll event reverses direction
 *                            (downward → upward) with a depth-pct delta ≥ 5pp.
 *
 * Future extensions (LAB-002 graduation):
 *   coverage_bucket emission at 50/80/100, break_class detection, reload
 *   session_id continuation. Out of scope for this MVP.
 */
define([
    'core/ajax',
    'core/notification'
], function(Ajax, Notification) {

    'use strict';

    const FLUSH_INTERVAL_MS = 5000;
    const MIN_BACK_DELTA_PCT = 5;

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

    return {
        /**
         * Bootstrap entry. Called from lib.php before_footer.
         *
         * @param {Number} courseid    Moodle course id (unused client-side; backend resolves from JWT).
         * @param {Number} sectionid   Moodle course_sections.id (passed in payload as page_type context).
         * @param {String} pageType    Moodle $PAGE->pagetype (e.g., 'mod-page-view').
         * @param {Number} cmid        Moodle course_modules.id of the current page (stamped on every event).
         */
        init: function(courseid, sectionid, pageType, cmid) {
            try {
                const sessionId = uuidv4();
                const sessionStartMs = Date.now();
                let lastDepth = scrollDepthPct();
                let lastDirection = 0;
                let maxDepth = lastDepth;
                let totalSpeedSamples = 0;
                let totalSpeedAccum = 0;
                const pending = [];
                let flushTimer = null;

                pending.push({
                    verb: 'scroll.session_started',
                    occurred_at: nowIso(),
                    session_id: sessionId,
                    moodle_cm_id: cmid || null,
                    correlation_id: null,
                    idempotency_key: sessionId + ':start',
                    payload_schema_version: '1.0',
                    payload: {page_type: pageType}
                });

                /**
                 * Flush all pending scroll events to the backend relay.
                 *
                 * @param {string} reason  Label for debug logging (e.g. 'interval', 'session_end').
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
                        if (window.console && console.warn) {
                            console.warn('local_srl_advisor[scroll]: flush failed (' + reason + ')', err);
                        }
                    });
                };

                let lastScrollMs = sessionStartMs;

                /**
                 * Process a throttled scroll event, updating depth/direction metrics
                 * and enqueuing a scroll.back event when a significant reversal is detected.
                 *
                 * @return {void}
                 */
                const onScroll = function() {
                    const now = Date.now();
                    const depth = scrollDepthPct();
                    const dtMs = Math.max(1, now - lastScrollMs);
                    const delta = depth - lastDepth;
                    const direction = delta === 0 ? lastDirection : (delta > 0 ? 1 : -1);

                    // Track speed (pps = percent per second).
                    totalSpeedAccum += Math.abs(delta) / (dtMs / 1000);
                    totalSpeedSamples += 1;
                    if (depth > maxDepth) {
                        maxDepth = depth;
                    }

                    // scroll.back fires on direction reversal (downward → upward) with delta ≥ MIN_BACK_DELTA_PCT.
                    if (lastDirection === 1 && direction === -1 && Math.abs(delta) >= MIN_BACK_DELTA_PCT) {
                        pending.push({
                            verb: 'scroll.back',
                            occurred_at: nowIso(),
                            session_id: sessionId,
                            moodle_cm_id: cmid || null,
                            correlation_id: null,
                            idempotency_key: null,
                            payload_schema_version: '1.0',
                            payload: {
                                from_depth_pct: lastDepth,
                                to_depth_pct: depth,
                                time_on_page_ms: now - sessionStartMs
                            }
                        });
                    }
                    lastDepth = depth;
                    lastDirection = direction;
                    lastScrollMs = now;
                };

                /**
                 * Enqueue a scroll.session_ended event with final metrics and flush
                 * immediately. Called on pagehide or when the page becomes hidden.
                 *
                 * @return {void}
                 */
                const endSession = function() {
                    pending.push({
                        verb: 'scroll.session_ended',
                        occurred_at: nowIso(),
                        session_id: sessionId,
                        moodle_cm_id: cmid || null,
                        correlation_id: null,
                        idempotency_key: sessionId + ':end',
                        payload_schema_version: '1.0',
                        payload: {
                            page_type: pageType,
                            max_depth_pct: maxDepth,
                            mean_speed_pps: totalSpeedSamples > 0
                                ? Math.round((totalSpeedAccum / totalSpeedSamples) * 100) / 100
                                : 0,
                            time_on_page_ms: Date.now() - sessionStartMs
                        }
                    });
                    flush('session_end');
                };

                // Bind listeners — throttle scroll via rAF to keep event rate ≤ ~60/s but
                // only emit the FLUSH every FLUSH_INTERVAL_MS.
                let rafPending = false;
                window.addEventListener('scroll', function() {
                    if (rafPending) {
                        return;
                    }
                    rafPending = true;
                    window.requestAnimationFrame(function() {
                        rafPending = false;
                        onScroll();
                    });
                }, {passive: true});

                window.addEventListener('pagehide', endSession);
                window.addEventListener('visibilitychange', function() {
                    if (document.visibilityState === 'hidden') {
                        endSession();
                    }
                });

                flushTimer = window.setInterval(function() {
                    flush('interval');
                }, FLUSH_INTERVAL_MS);

                // Flush the start event immediately so the backend has a session start
                // even if the user never scrolls.
                flush('init');
                return flushTimer;
            } catch (e) {
                if (window.console && console.warn) {
                    console.warn('local_srl_advisor[scroll]: init failed', e);
                }
                return null;
            }
        }
    };
});
