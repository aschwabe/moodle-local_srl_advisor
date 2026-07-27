/* eslint-env browser, amd */
/**
 * SRL Advisor inline check-in panel (DEC-031 v1.1).
 *
 * Lifecycle:
 *   1. lib.php injects this module via $PAGE->requires->js_call_amd on
 *      mod-page-view (slice 7).
 *   2. init(courseid, sectionid, portalUrl) fetches the pending check-in
 *      via the local_srl_advisor_get_pending_check_in AJAX function.
 *   3. If has_task, renders the Mustache panel into a mount point and
 *      wires submit + dismiss handlers.
 *   4. Submit/dismiss POST through the matching AJAX functions with a
 *      client-generated Idempotency-Key (UUID v4) so retries don't
 *      double-record (BLOCKER #4).
 *
 * Failure mode contract (DEC-013): any non-success degrades to a no-op
 * panel + nav badge + portal link remain functional. We surface a
 * generic error on submit/dismiss failure but never block the page.
 */
define([
    'jquery',
    'core/ajax',
    'core/templates',
    'core/str',
    'core/notification'
], function($, Ajax, Templates, Str, Notification) {

    'use strict';

    const MOUNT_ID_PREFIX = 'srladvisor-check-in-mount-';
    const PHASE_PRE = 'pre';
    const PHASE_POST = 'post';

    /**
     * RFC 4122 v4 UUID using the browser crypto API where available; falls
     * back to Math.random for environments that don't ship crypto.getRandomValues.
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
     * Ensure a mount-point element exists for the given phase, creating and
     * inserting it into the DOM if absent.
     *
     * @param {string} phase - 'pre' or 'post'; controls insertion position.
     * @return {HTMLElement} The mount-point element.
     */
    function ensureMount(phase) {
        const mountId = MOUNT_ID_PREFIX + phase;
        let mount = document.getElementById(mountId);
        if (mount) {
            return mount;
        }
        mount = document.createElement('div');
        mount.id = mountId;
        // DEC-048: phase=pre mounts at TOP of #region-main (intent before
        // engagement); phase=post mounts at BOTTOM (reflection after
        // engagement). Falls back to body when #region-main is absent.
        // Single-Page sections see init() called twice (pre + post); each
        // call gets its own mount so both can coexist when needed.
        const host = document.getElementById('region-main') || document.body;
        if (phase === PHASE_PRE) {
            host.insertBefore(mount, host.firstChild);
        } else {
            host.appendChild(mount);
        }
        return mount;
    }

    /**
     * Load all localised strings required by the check-in panel via core/str.
     *
     * @return {Promise<Object>} Resolves to a keyed string map for template rendering.
     */
    function loadStrings() {
        return Str.get_strings([
            {key: 'inline_question_pre', component: 'local_srl_advisor'},
            {key: 'inline_question_post', component: 'local_srl_advisor'},
            {key: 'inline_submit', component: 'local_srl_advisor'},
            {key: 'inline_dismiss', component: 'local_srl_advisor'},
            {key: 'inline_thanks', component: 'local_srl_advisor'},
            {key: 'inline_error_generic', component: 'local_srl_advisor'},
            {key: 'inline_portal_fallback_link', component: 'local_srl_advisor'},
            {key: 'inline_aria_panel', component: 'local_srl_advisor'},
            {key: 'inline_placeholder', component: 'local_srl_advisor'},
            {key: 'inline_other_label', component: 'local_srl_advisor'},
            {key: 'inline_other_placeholder', component: 'local_srl_advisor'},
            {key: 'inline_other_required', component: 'local_srl_advisor'}
        ]).then(function(s) {
            /* eslint-disable camelcase */
            return {
                question_pre: s[0],
                question_post: s[1],
                submit: s[2],
                dismiss: s[3],
                thanks: s[4],
                error_generic: s[5],
                portal_fallback: s[6],
                aria_panel: s[7],
                placeholder: s[8],
                other_label: s[9],
                other_placeholder: s[10],
                other_required: s[11]
            };
            /* eslint-enable camelcase */
        });
    }

    /**
     * Fetch the pending check-in task for a course section via AJAX.
     *
     * @param {Number} courseid  Moodle course id.
     * @param {Number} sectionid Moodle course_sections.id (NOT sectionnum).
     * @return {Promise<Object>} Resolves to the pending check-in payload.
     */
    function fetchPending(courseid, sectionid) {
        return Ajax.call([{
            methodname: 'local_srl_advisor_get_pending_check_in',
            args: {courseid: courseid, sectionid: sectionid}
        }])[0];
    }

    /**
     * Submit a check-in response via AJAX.
     *
     * @param {Number} courseid       Moodle course id.
     * @param {Number} taskid         Check-in task id.
     * @param {Number} strategyid     Selected strategy id (0 when none/other).
     * @param {Boolean} nostrategy    True when the student picked "no strategy".
     * @param {String} otherText      Free-text strategy when "Other" was picked.
     * @param {Number} responseTimeMs Milliseconds from render to submit.
     * @param {String} idemKey        Idempotency key (UUID v4).
     * @return {Promise<Object>} Resolves to the submit result payload.
     */
    function submit(courseid, taskid, strategyid, nostrategy, otherText, responseTimeMs, idemKey) {
        return Ajax.call([{
            methodname: 'local_srl_advisor_submit_check_in',
            args: {
                courseid: courseid,
                taskid: taskid,
                strategyid: strategyid,
                nostrategy: nostrategy,
                othertext: otherText,
                responsetimems: responseTimeMs,
                idempotencykey: idemKey
            }
        }])[0];
    }

    /**
     * Dismiss a pending check-in task via AJAX.
     *
     * @param {Number} courseid Moodle course id.
     * @param {Number} taskid   Check-in task id.
     * @param {String} reason   Optional dismissal reason.
     * @param {String} idemKey  Idempotency key (UUID v4).
     * @return {Promise<Object>} Resolves to the dismiss result payload.
     */
    function dismiss(courseid, taskid, reason, idemKey) {
        return Ajax.call([{
            methodname: 'local_srl_advisor_dismiss_check_in',
            args: {
                courseid: courseid,
                taskid: taskid,
                reason: reason || '',
                idempotencykey: idemKey
            }
        }])[0];
    }

    /**
     * Render the check-in Mustache panel into a mount point and wire handlers.
     *
     * @param {HTMLElement} mount  Mount-point element to render into.
     * @param {Object} task        Pending check-in task payload from the backend.
     * @param {Object} strings     Localised string map from loadStrings().
     * @param {Number} courseid    Moodle course id.
     * @param {String} portalUrl   Fallback portal launch URL.
     * @return {Promise} Resolves once the panel is rendered and handlers wired.
     */
    function renderPanel(mount, task, strings, courseid, portalUrl) {
        const heading = task.is_pre ? strings.question_pre : strings.question_post;
        // Server-side already shuffled; ensure each option carries the kind flag the
        // template branches on. strategy_id=0 + kind=no_strategy triggers the no-strat
        // branch; strategy_id>0 triggers the strategy branch.
        /* eslint-disable camelcase */
        const ctx = {
            task_id: task.task_id,
            is_pre: task.is_pre,
            heading: heading,
            submit_label: strings.submit,
            dismiss_label: strings.dismiss,
            portal_fallback_label: strings.portal_fallback,
            portal_url: portalUrl,
            aria_label: strings.aria_panel,
            idempotency_key: uuidv4(),
            placeholder_label: strings.placeholder,
            other_label: strings.other_label,
            other_placeholder: strings.other_placeholder,
            options: task.options
        };
        /* eslint-enable camelcase */
        return Templates.render('local_srl_advisor/check_in_panel', ctx).then(function(html) {
            mount.innerHTML = html;
            wireHandlers(mount, task, strings, courseid);
        });
    }

    /**
     * Wire submit/dismiss/selection handlers on a rendered check-in panel.
     *
     * @param {HTMLElement} mount  Mount-point element containing the panel.
     * @param {Object} task        Pending check-in task payload from the backend.
     * @param {Object} strings     Localised string map from loadStrings().
     * @param {Number} courseid    Moodle course id.
     * @return {void}
     */
    function wireHandlers(mount, task, strings, courseid) {
        const $panel = $(mount).find('.srladvisor-check-in');
        const $form = $panel.find('[data-srladvisor-form]');
        const $select = $panel.find('[data-srladvisor-select]');
        const $description = $panel.find('[data-srladvisor-description]');
        const $otherWrap = $panel.find('[data-srladvisor-other-wrap]');
        const $otherInput = $panel.find('[data-srladvisor-other-input]');
        const $submit = $panel.find('[data-srladvisor-submit]');
        const $dismiss = $panel.find('[data-srladvisor-dismiss]');
        const $status = $panel.find('[data-srladvisor-status]');
        const renderedAt = Date.now();

        /**
         * Clear the status line text and reset its success/error classes.
         *
         * @return {void}
         */
        function clearStatus() {
            $status.text('').removeClass('srladvisor-check-in__status--success srladvisor-check-in__status--error');
        }

        // DEC-048: keep submit disabled until the student picks a non-placeholder
        // option. Description block mirrors the selected option's data-description.
        // DEC-048 follow-up: when 'Other' selected, reveal text input + require
        // non-empty value to enable Save.
        /**
         * Mirror the selected option's description, toggle the "Other" text
         * input, and enable/disable the submit button accordingly.
         *
         * @return {void}
         */
        function syncSelection() {
            const $opt = $select.find('option:selected');
            const value = $select.val();
            const desc = $opt.attr('data-description') || '';
            const isOther = $opt.attr('data-other') === '1';
            $description.text(desc);
            if (isOther) {
                $otherWrap.removeAttr('hidden');
                const otherFilled = $.trim($otherInput.val() || '').length > 0;
                $submit.prop('disabled', !otherFilled);
            } else {
                $otherWrap.attr('hidden', 'hidden');
                $submit.prop('disabled', !value);
            }
        }
        $select.on('change', function() {
            clearStatus();
            syncSelection();
        });
        $otherInput.on('input', syncSelection);

        // DEC-048: for post tasks, pre-select the strategy the student picked at
        // pre time so the dropdown reads "you said X — was it?". previous_strategy_id
        // is 0 when no pre on file or pre was no_strategy.
        if (!task.is_pre && task.previous_strategy_id) {
            $select.val('strategy:' + task.previous_strategy_id);
        }
        syncSelection();

        $form.on('submit', function(ev) {
            ev.preventDefault();
            const $opt = $select.find('option:selected');
            const value = $select.val();
            if (!value) {
                return;
            }
            const strategyId = parseInt($opt.attr('data-strategy-id'), 10) || 0;
            const noStrategy = $opt.attr('data-no-strategy') === '1';
            const isOther = $opt.attr('data-other') === '1';
            const otherText = isOther ? $.trim($otherInput.val() || '') : '';
            if (isOther && otherText === '') {
                $status.text(strings.other_required)
                    .removeClass('srladvisor-check-in__status--success')
                    .addClass('srladvisor-check-in__status--error');
                $otherInput.trigger('focus');
                return;
            }
            const responseTimeMs = Date.now() - renderedAt;
            const idemKey = $panel.attr('data-idempotency-key');

            $submit.prop('disabled', true);
            $dismiss.prop('disabled', true);

            submit(courseid, task.task_id, strategyId, noStrategy, otherText, responseTimeMs, idemKey).then(function(res) {
                if (res.ok) {
                    $status.text(strings.thanks)
                        .removeClass('srladvisor-check-in__status--error')
                        .addClass('srladvisor-check-in__status--success');
                    $select.prop('disabled', true);
                    $otherInput.prop('disabled', true);
                    return;
                }
                $status.text(strings.error_generic)
                    .removeClass('srladvisor-check-in__status--success')
                    .addClass('srladvisor-check-in__status--error');
                $submit.prop('disabled', false);
                $dismiss.prop('disabled', false);
                return;
            }).fail(function(err) {
                $status.text(strings.error_generic)
                    .removeClass('srladvisor-check-in__status--success')
                    .addClass('srladvisor-check-in__status--error');
                $submit.prop('disabled', false);
                $dismiss.prop('disabled', false);
                Notification.exception(err);
            });
        });

        $dismiss.on('click', function() {
            const idemKey = uuidv4();
            $submit.prop('disabled', true);
            $dismiss.prop('disabled', true);
            dismiss(courseid, task.task_id, '', idemKey).then(function(res) {
                if (res.ok) {
                    $panel.remove();
                    // WCAG 2.2 AA 2.4.3 (DEC-069): panel destroyed on dismiss;
                    // move focus to the mount container so it isn't lost to <body>.
                    $(mount).attr('tabindex', '-1').trigger('focus');
                    return;
                }
                $status.text(strings.error_generic);
                $submit.prop('disabled', false);
                $dismiss.prop('disabled', false);
                return;
            }).fail(function(err) {
                $status.text(strings.error_generic);
                $submit.prop('disabled', false);
                $dismiss.prop('disabled', false);
                Notification.exception(err);
            });
        });
    }

    return {
        /**
         * Bootstrap entry point invoked by lib.php via js_call_amd.
         *
         * @param {Number} courseid     Moodle course id
         * @param {Number} sectionid    Moodle course_sections.id (NOT sectionnum)
         * @param {String} portalUrl    Fallback portal launch URL
         * @param {String} phase        DEC-048: 'pre' or 'post'. Drives mount
         *                              placement (top vs bottom of #region-main)
         *                              and is sanity-checked against the
         *                              backend-returned task.is_pre.
         */
        init: function(courseid, sectionid, portalUrl, phase) {
            // Defensive — never throw out of an AMD bootstrap and break Moodle's
            // own JS bundle. Any failure degrades to no panel; nav badge + portal
            // route remain functional per DEC-013.
            const resolvedPhase = (phase === PHASE_POST) ? PHASE_POST : PHASE_PRE;
            try {
                $.when(fetchPending(courseid, sectionid), loadStrings()).then(function(payload, strings) {
                    if (!payload || !payload.has_task) {
                        return;
                    }
                    // DEC-048 sanity gate: only render the panel when the
                    // backend's pending task matches the page-position phase
                    // lib.php asked for. Avoids rendering a pre panel at the
                    // bottom of the last page (or vice versa) if backend state
                    // and section sequence drift.
                    const taskIsPre = !!payload.is_pre;
                    if (resolvedPhase === PHASE_PRE && !taskIsPre) {
                        return;
                    }
                    if (resolvedPhase === PHASE_POST && taskIsPre) {
                        return;
                    }
                    const mount = ensureMount(resolvedPhase);
                    return renderPanel(mount, payload, strings, courseid, portalUrl);
                }).fail(function(err) {
                    // Silent failure on bootstrap — log only at DEBUG_DEVELOPER
                    // equivalent (console.warn).
                    if (window.console && console.warn) {
                        console.warn('local_srl_advisor[inline_get]: bootstrap failed', err);
                    }
                });
            } catch (e) {
                if (window.console && console.warn) {
                    console.warn('local_srl_advisor[inline_get]: init exception', e);
                }
            }
        }
    };
});
