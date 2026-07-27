/* eslint-env browser, amd */
/**
 * Video-event telemetry (LAB-003 MVP, DEC-019/DEC-035/DEC-036).
 *
 * Observes HTML5 <video> elements + YouTube iframe embeds + Vimeo iframe
 * embeds + Panopto iframe embeds on `mod-page-view`. Emits behavior events
 * to the backend L1 event log via the plugin's relay (same path as scroll
 * telemetry). Panopto is the production player at St Andrews; its
 * EmbedApi.js is served per-tenant, so the loader derives the tenant from
 * the iframe's src origin.
 *
 * Verb subset (DEC-019 §Piece 1):
 *   video.played       — playback started or resumed.
 *   video.paused       — playback paused.
 *   video.ended        — playback reached the end.
 *   video.seeked       — playback position jumped (manual seek).
 *   video.rate_changed — playback speed changed (0.5x, 1.5x, etc).
 *
 * Future extensions (LAB-003 graduation):
 *   watch-percent buckets on .ended, restart vs. resume vs. start
 *   subtyping, abandonment-timing. Out of scope for this MVP.
 */
define([
    'core/ajax'
], function(Ajax) {

    'use strict';

    const FLUSH_INTERVAL_MS = 5000;
    const YT_API_SRC = 'https://www.youtube.com/iframe_api';
    const VIMEO_API_SRC = 'https://player.vimeo.com/api/player.js';

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
     * Return the current time as an ISO 8601 string.
     *
     * @return {string} Current timestamp in ISO 8601 format.
     */
    function nowIso() {
        return new Date().toISOString();
    }

    /**
     * Dynamically load a script by URL, resolving when loaded and rejecting
     * on error. Safe to call multiple times for the same URL — subsequent
     * calls resolve immediately if the script is already loaded.
     *
     * @param {string} src  Absolute URL of the script to load.
     * @return {Promise<void>} Resolves when the script is ready.
     */
    function loadScriptOnce(src) {
        return new Promise(function(resolve, reject) {
            const existing = document.querySelector('script[src="' + src + '"]');
            if (existing) {
                if (existing.dataset.srlLoaded === '1') {
                    resolve();
                    return;
                }
                existing.addEventListener('load', function() { resolve(); });
                existing.addEventListener('error', function() { reject(new Error('script load failed')); });
                return;
            }
            const s = document.createElement('script');
            s.src = src;
            s.async = true;
            s.onload = function() { s.dataset.srlLoaded = '1'; resolve(); };
            s.onerror = function() { reject(new Error('script load failed')); };
            document.head.appendChild(s);
        });
    }

    return {
        /**
         * Bootstrap entry. Called from lib.php before_footer.
         *
         * @param {Number} courseid    Moodle course id (passed through to the relay).
         * @param {Number} sectionid   Moodle course_sections.id (carried for analyst joins).
         * @param {String} pageType    Moodle $PAGE->pagetype.
         * @param {Number} cmid        Moodle course_modules.id of the current page (stamped on every event).
         */
        init: function(courseid, sectionid, pageType, cmid) {
            try {
                const sessionId = uuidv4();
                const pending = [];
                let videoIndex = 0;

                /**
                 * Flush all pending video events to the backend relay.
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
                            window.console.warn('local_srl_advisor[video]: flush failed (' + reason + ')', err);
                        }
                    });
                };

                /**
                 * Enqueue a video behavior event into the pending batch.
                 *
                 * @param {string} verb      Event verb (e.g. 'video.played', 'video.paused').
                 * @param {number} idx       Zero-based index of the video element on the page.
                 * @param {Object} payload   Additional event-specific payload fields.
                 * @param {string} idemKey   Optional idempotency key for dedup (e.g. session:end events).
                 * @return {void}
                 */
                const emit = function(verb, idx, payload, idemKey) {
                    pending.push({
                        verb: verb,
                        occurred_at: nowIso(),
                        session_id: sessionId,
                        moodle_cm_id: cmid || null,
                        correlation_id: null,
                        idempotency_key: idemKey || null,
                        payload_schema_version: '1.0',
                        payload: Object.assign({video_index: idx, page_type: pageType, section_id: sectionid}, payload || {})
                    });
                };

                // -------- HTML5 <video> --------
                const html5 = document.querySelectorAll('video');
                html5.forEach(function(v) {
                    const idx = videoIndex++;
                    let seekFromPosition = 0;
                    v.addEventListener('play', function() {
                        emit('video.played', idx, {
                            position_s: Math.round(v.currentTime || 0),
                            duration_s: Math.round(v.duration || 0)
                        });
                    });
                    v.addEventListener('pause', function() {
                        // 'pause' also fires on ended; the ended handler emits separately.
                        if (v.ended) { return; }
                        emit('video.paused', idx, {
                            position_s: Math.round(v.currentTime || 0)
                        });
                    });
                    v.addEventListener('ended', function() {
                        const watchPct = (v.duration > 0)
                            ? Math.round((v.currentTime / v.duration) * 100)
                            : 100;
                        emit('video.ended', idx, {watch_pct: watchPct}, sessionId + ':html5:' + idx + ':end');
                    });
                    // 'seeking' fires BEFORE the seek lands → capture origin.
                    // 'seeked' fires AFTER the seek completes → emit with from/to.
                    v.addEventListener('seeking', function() {
                        seekFromPosition = Math.round(v.currentTime || 0);
                    });
                    v.addEventListener('seeked', function() {
                        const to = Math.round(v.currentTime || 0);
                        if (Math.abs(to - seekFromPosition) < 1) {
                            return;  // Sub-second autocorrect, not a user seek.
                        }
                        emit('video.seeked', idx, {from_s: seekFromPosition, to_s: to});
                    });
                    v.addEventListener('ratechange', function() {
                        emit('video.rate_changed', idx, {playback_rate: v.playbackRate});
                    });
                });

                // -------- YouTube iframes --------
                const ytIframes = Array.prototype.slice.call(
                    document.querySelectorAll('iframe[src*="youtube.com/embed"], iframe[src*="youtube-nocookie.com/embed"]')
                );
                if (ytIframes.length > 0) {
                    // Force enablejsapi for any iframe that doesn't already have it (defensive).
                    ytIframes.forEach(function(f) {
                        if (f.src.indexOf('enablejsapi=1') === -1) {
                            f.src += (f.src.indexOf('?') === -1 ? '?' : '&') + 'enablejsapi=1';
                        }
                    });
                    loadScriptOnce(YT_API_SRC).then(function() {
                        const start = function() {
                            ytIframes.forEach(function(iframe) {
                                const idx = videoIndex++;
                                // Heartbeat tracks the "expected" position so a
                                // BUFFERING event with a wide position-jump can
                                // be classified as a seek (YouTube doesn't fire
                                // a dedicated seek event on the iframe API).
                                let expectedPosition = 0;
                                let heartbeat = null;
                                /* global YT */
                                new YT.Player(iframe, {
                                    events: {
                                        onStateChange: function(ev) {
                                            const t = Math.round(ev.target.getCurrentTime() || 0);
                                            const d = Math.round(ev.target.getDuration() || 0);
                                            if (ev.data === YT.PlayerState.PLAYING) {
                                                emit('video.played', idx, {position_s: t, duration_s: d});
                                                if (!heartbeat) {
                                                    heartbeat = window.setInterval(function() {
                                                        expectedPosition = Math.round(ev.target.getCurrentTime() || 0);
                                                    }, 1000);
                                                }
                                            } else if (ev.data === YT.PlayerState.PAUSED) {
                                                emit('video.paused', idx, {position_s: t});
                                            } else if (ev.data === YT.PlayerState.ENDED) {
                                                if (heartbeat) { window.clearInterval(heartbeat); heartbeat = null; }
                                                const pct = d > 0 ? Math.round((t / d) * 100) : 100;
                                                emit('video.ended', idx, {watch_pct: pct}, sessionId + ':yt:' + idx + ':end');
                                            } else if (ev.data === YT.PlayerState.BUFFERING) {
                                                // Heuristic seek detection: position-jump > 2s vs.
                                                // the heartbeat's expected position.
                                                if (Math.abs(t - expectedPosition) > 2 && expectedPosition > 0) {
                                                    emit('video.seeked', idx, {from_s: expectedPosition, to_s: t});
                                                    expectedPosition = t;
                                                }
                                            }
                                        },
                                        onPlaybackRateChange: function(ev) {
                                            emit('video.rate_changed', idx, {playback_rate: ev.data});
                                        }
                                    }
                                });
                            });
                        };
                        if (window.YT && window.YT.Player) {
                            start();
                        } else {
                            window.onYouTubeIframeAPIReady = start;
                        }
                    }).catch(function(e) {
                        if (window.console && window.console.warn) {
                            window.console.warn('local_srl_advisor[video]: YT load failed', e);
                        }
                    });
                }

                // -------- Vimeo iframes --------
                const vimeoIframes = Array.prototype.slice.call(
                    document.querySelectorAll('iframe[src*="player.vimeo.com"]')
                );
                if (vimeoIframes.length > 0) {
                    loadScriptOnce(VIMEO_API_SRC).then(function() {
                        vimeoIframes.forEach(function(iframe) {
                            const idx = videoIndex++;
                            let lastPosition = 0;
                            /* global Vimeo */
                            const player = new Vimeo.Player(iframe);
                            player.on('play', function(d) {
                                emit('video.played', idx, {
                                    position_s: Math.round(d.seconds || 0),
                                    duration_s: Math.round(d.duration || 0)
                                });
                            });
                            player.on('pause', function(d) {
                                emit('video.paused', idx, {position_s: Math.round(d.seconds || 0)});
                            });
                            player.on('ended', function(d) {
                                const pct = (d.duration > 0)
                                    ? Math.round((d.seconds / d.duration) * 100)
                                    : 100;
                                emit('video.ended', idx, {watch_pct: pct}, sessionId + ':vimeo:' + idx + ':end');
                            });
                            // Vimeo's `timeupdate` runs ~4 times/s during playback.
                            // We capture the last-known position so 'seeked' can
                            // emit a from_s.
                            player.on('timeupdate', function(d) {
                                lastPosition = Math.round(d.seconds || 0);
                            });
                            player.on('seeked', function(d) {
                                const to = Math.round(d.seconds || 0);
                                if (Math.abs(to - lastPosition) < 1) { return; }
                                emit('video.seeked', idx, {from_s: lastPosition, to_s: to});
                                lastPosition = to;
                            });
                            player.on('playbackratechange', function(d) {
                                emit('video.rate_changed', idx, {playback_rate: d.playbackRate});
                            });
                        });
                    }).catch(function(e) {
                        if (window.console && window.console.warn) {
                            window.console.warn('local_srl_advisor[video]: Vimeo load failed', e);
                        }
                    });
                }

                // -------- Panopto iframes --------
                // Panopto serves EmbedApi.js per-tenant (no central CDN); derive the
                // tenant origin from each iframe's src. Iframe must have an `id`
                // attribute — EmbedApi looks the element up by id.
                const panoptoIframes = Array.prototype.slice.call(
                    document.querySelectorAll('iframe[src*="/Panopto/Pages/Embed.aspx"]')
                );
                if (panoptoIframes.length > 0) {
                    const tenantsLoaded = {};
                    panoptoIframes.forEach(function(iframe) {
                        if (!iframe.id) {
                            // Synthesize an id so EmbedApi can find the iframe.
                            iframe.id = 'srl-panopto-' + Math.random().toString(36).slice(2, 10);
                        }
                        let tenantOrigin;
                        try {
                            tenantOrigin = new URL(iframe.src).origin;
                        } catch (e) {
                            if (window.console && window.console.warn) {
                                window.console.warn('local_srl_advisor[video]: Panopto iframe src unparseable', e);
                            }
                            return;
                        }
                        const apiSrc = tenantOrigin + '/Panopto/Resources/Embed/EmbedApi.js';
                        if (!tenantsLoaded[tenantOrigin]) {
                            tenantsLoaded[tenantOrigin] = loadScriptOnce(apiSrc);
                        }
                        tenantsLoaded[tenantOrigin].then(function() {
                            if (!window.EmbedApi) {
                                if (window.console && window.console.warn) {
                                    window.console.warn('local_srl_advisor[video]: Panopto EmbedApi not on window after load');
                                }
                                return;
                            }
                            const idx = videoIndex++;
                            // State enum per Panopto EmbedApi docs:
                            //   0=Unstarted, 1=Playing, 2=Paused, 3=Buffering, 4=Ended.
                            // Panopto exposes no dedicated seek event; reuse the
                            // YouTube heartbeat-vs-buffering heuristic.
                            let expectedPosition = 0;
                            let durationSec = 0;
                            let heartbeat = null;
                            let player = null;
                            const readCurrentTime = function(cb) {
                                // EmbedApi.getCurrentTime is callback-style; some
                                // tenant versions return a value directly. Handle both.
                                try {
                                    const ret = player.getCurrentTime(function(t) { cb(t); });
                                    if (typeof ret === 'number') { cb(ret); }
                                } catch (e) { cb(expectedPosition); }
                            };
                            /* global EmbedApi */
                            player = new EmbedApi(iframe.id, {
                                videoParams: {interactivity: 'all'},
                                events: {
                                    onReady: function() {
                                        try {
                                            player.getDuration(function(d) { durationSec = Math.round(d || 0); });
                                        } catch (e) { /* tenant variant — duration filled later */ }
                                    },
                                    onStateChange: function(state) {
                                        readCurrentTime(function(t) {
                                            const ts = Math.round(t || 0);
                                            if (state === 1) {
                                                emit('video.played', idx, {position_s: ts, duration_s: durationSec});
                                                if (!heartbeat) {
                                                    heartbeat = window.setInterval(function() {
                                                        readCurrentTime(function(p) {
                                                            expectedPosition = Math.round(p || 0);
                                                        });
                                                    }, 1000);
                                                }
                                            } else if (state === 2) {
                                                emit('video.paused', idx, {position_s: ts});
                                            } else if (state === 4) {
                                                if (heartbeat) { window.clearInterval(heartbeat); heartbeat = null; }
                                                const pct = durationSec > 0
                                                    ? Math.round((ts / durationSec) * 100)
                                                    : 100;
                                                emit('video.ended', idx, {watch_pct: pct}, sessionId + ':panopto:' + idx + ':end');
                                            } else if (state === 3) {
                                                if (Math.abs(ts - expectedPosition) > 2 && expectedPosition > 0) {
                                                    emit('video.seeked', idx, {from_s: expectedPosition, to_s: ts});
                                                    expectedPosition = ts;
                                                }
                                            }
                                        });
                                    },
                                    onPlaybackRateChange: function(rate) {
                                        emit('video.rate_changed', idx, {playback_rate: rate});
                                    }
                                }
                            });
                        }).catch(function(e) {
                            if (window.console && window.console.warn) {
                                window.console.warn('local_srl_advisor[video]: Panopto EmbedApi load failed', e);
                            }
                        });
                    });
                }

                if (html5.length === 0 && ytIframes.length === 0 && vimeoIframes.length === 0 && panoptoIframes.length === 0) {
                    // No video surfaces on this page — no need to start a flush timer.
                    return null;
                }

                window.addEventListener('pagehide', function() { flush('pagehide'); });
                window.addEventListener('visibilitychange', function() {
                    if (document.visibilityState === 'hidden') { flush('hidden'); }
                });
                return window.setInterval(function() { flush('interval'); }, FLUSH_INTERVAL_MS);
            } catch (e) {
                if (window.console && window.console.warn) {
                    window.console.warn('local_srl_advisor[video]: init failed', e);
                }
                return null;
            }
        }
    };
});
