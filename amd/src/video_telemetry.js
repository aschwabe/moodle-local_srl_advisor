/* eslint-env browser, amd */
/**
 * Video-event telemetry (LAB-003 MVP, DEC-019/DEC-035/DEC-036).
 *
 * Observes HTML5 <video> elements + YouTube iframe embeds + Vimeo iframe
 * embeds on `mod-page-view`. Emits behavior events to the backend L1 event
 * log via the plugin's relay (same path as scroll telemetry).
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
