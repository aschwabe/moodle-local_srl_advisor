/* eslint-env browser, amd */
/**
 * Video-event telemetry (LAB-003 MVP, DEC-019/DEC-035/DEC-036).
 *
 * Observes HTML5 <video> elements + YouTube iframe embeds + Vimeo iframe
 * embeds on `mod-page-view`. Emits behavior events to the backend L1 event
 * log via the plugin's relay (same path as scroll telemetry).
 *
 * MVP verb subset (DEC-019 §Piece 1):
 *   video.played   — playback started or resumed.
 *   video.paused   — playback paused.
 *   video.ended    — playback reached the end.
 *
 * Future extensions (LAB-003 graduation):
 *   video.seeked, video.rate_changed, watch-percent tracking on
 *   .ended, restart vs. resume vs. start subtyping, abandonment-
 *   timing. Out of scope for this MVP.
 */
define([
    'core/ajax'
], function(Ajax) {

    'use strict';

    const FLUSH_INTERVAL_MS = 5000;
    const YT_API_SRC = 'https://www.youtube.com/iframe_api';
    const VIMEO_API_SRC = 'https://player.vimeo.com/api/player.js';

    function uuidv4() {
        if (window.crypto && window.crypto.getRandomValues) {
            const bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
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
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    function nowIso() {
        return new Date().toISOString();
    }

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
         */
        init: function(courseid, sectionid, pageType) {
            try {
                const sessionId = uuidv4();
                const pending = [];
                let videoIndex = 0;

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
                            console.warn('local_srl_advisor[video]: flush failed (' + reason + ')', err);
                        }
                    });
                };

                const emit = function(verb, idx, payload, idemKey) {
                    pending.push({
                        verb: verb,
                        occurred_at: nowIso(),
                        session_id: sessionId,
                        moodle_cm_id: null,
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
                                /* global YT */
                                new YT.Player(iframe, {
                                    events: {
                                        onStateChange: function(ev) {
                                            const t = Math.round(ev.target.getCurrentTime() || 0);
                                            const d = Math.round(ev.target.getDuration() || 0);
                                            if (ev.data === YT.PlayerState.PLAYING) {
                                                emit('video.played', idx, {position_s: t, duration_s: d});
                                            } else if (ev.data === YT.PlayerState.PAUSED) {
                                                emit('video.paused', idx, {position_s: t});
                                            } else if (ev.data === YT.PlayerState.ENDED) {
                                                const pct = d > 0 ? Math.round((t / d) * 100) : 100;
                                                emit('video.ended', idx, {watch_pct: pct}, sessionId + ':yt:' + idx + ':end');
                                            }
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
                        if (window.console && console.warn) { console.warn('local_srl_advisor[video]: YT load failed', e); }
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
                        });
                    }).catch(function(e) {
                        if (window.console && console.warn) { console.warn('local_srl_advisor[video]: Vimeo load failed', e); }
                    });
                }

                if (html5.length === 0 && ytIframes.length === 0 && vimeoIframes.length === 0) {
                    // No video surfaces on this page — no need to start a flush timer.
                    return null;
                }

                window.addEventListener('pagehide', function() { flush('pagehide'); });
                window.addEventListener('visibilitychange', function() {
                    if (document.visibilityState === 'hidden') { flush('hidden'); }
                });
                return window.setInterval(function() { flush('interval'); }, FLUSH_INTERVAL_MS);
            } catch (e) {
                if (window.console && console.warn) {
                    console.warn('local_srl_advisor[video]: init failed', e);
                }
                return null;
            }
        }
    };
});
