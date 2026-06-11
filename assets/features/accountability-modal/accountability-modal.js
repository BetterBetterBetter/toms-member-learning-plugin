/* Tom's School Of Life Accountability Modal */

(function() {
    'use strict';

    var modal = document.querySelector('[data-tsol-accountability-modal]');
    if (!modal) {
        return;
    }

    var dialog = modal.querySelector('.tsol-accountability-modal__dialog');
    var closeButtons = modal.querySelectorAll('[data-tsol-accountability-modal-close]');
    var launcher = document.querySelector('[data-tsol-accountability-modal-launcher]');
    var launcherBubble = launcher ? launcher.querySelector('[data-tsol-accountability-modal-launcher-bubble]') : null;
    var launcherDefaultLabel = launcher ? (launcher.getAttribute('aria-label') || '') : '';
    var adminBarButton = document.querySelector('#wp-admin-bar-tsol-accountability-modal-open > a');
    var form = modal.querySelector('[data-tsol-accountability-modal-form]');
    var status = modal.querySelector('[data-tsol-accountability-modal-status]');
    var callGroup = modal.querySelector('[data-tsol-accountability-modal-calls]');
    var steps = Array.prototype.slice.call(modal.querySelectorAll('[data-tsol-accountability-modal-step]'));
    var progressBar = modal.querySelector('[data-tsol-accountability-modal-progress-bar]');
    var progressMeter = modal.querySelector('[data-tsol-accountability-modal-progress-meter]');
    var currentStepText = modal.querySelector('[data-tsol-accountability-modal-current-step]');
    var totalStepsText = modal.querySelector('[data-tsol-accountability-modal-total-steps]');
    var backButton = modal.querySelector('[data-tsol-accountability-modal-back]');
    var nextButton = modal.querySelector('[data-tsol-accountability-modal-next]');
    var submitButton = modal.querySelector('[data-tsol-accountability-modal-submit]');
    var callCount = modal.querySelector('[data-tsol-accountability-modal-call-count]');
    var fillButtons = Array.prototype.slice.call(modal.querySelectorAll('[data-tsol-accountability-modal-fill]'));
    var rangeInputs = Array.prototype.slice.call(modal.querySelectorAll('[data-tsol-accountability-range]'));
    var autoOpen = modal.getAttribute('data-tsol-accountability-modal-auto-open') === '1';
    var storageKey = 'tsolAccountabilityModalDismissed';
    var config = window.tsolAccountabilityModal || {};
    var draftStorageKey = config.draftKey || 'tsolAccountabilityModalDraft';
    var draftTtl = Math.max(0, parseInt(config.draftTtl, 10) || 604800) * 1000;
    var draftSchema = config.draftSchema || '';
    var draftEnabled = config.draftEnabled !== false;
    var launcherEnabled = config.launcherEnabled !== false;
    var launcherAnimationEnabled = config.launcherAnimationEnabled !== false;

    var hasOpened = false;
    var isSubmitting = false;
    var currentStep = 0;
    var lastFocusedElement = null;
    var isRestoringDraft = false;
    var draftSaveTimer = null;
    var launcherShakeTimer = null;
    var launcherShakeCount = 0;
    var configuredThreshold = parseInt(config.scrollThreshold, 10);
    var scrollThreshold = isNaN(configuredThreshold) ? 320 : Math.max(0, configuredThreshold);

    function motionAllowed() {
        return launcherAnimationEnabled && (!window.matchMedia || !window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function ringLauncher() {
        if (!launcher || launcher.hidden || !motionAllowed()) {
            return;
        }

        launcher.classList.remove('is-ringing');
        void launcher.offsetWidth;
        launcher.classList.add('is-ringing');

        window.setTimeout(function() {
            launcher.classList.remove('is-ringing');
        }, 900);
    }

    function stopLauncherReminder() {
        if (launcherShakeTimer) {
            window.clearInterval(launcherShakeTimer);
            launcherShakeTimer = null;
        }
    }

    function startLauncherReminder() {
        if (!launcher || !motionAllowed() || launcherShakeTimer) {
            return;
        }

        launcherShakeCount = 0;

        window.setTimeout(ringLauncher, 900);

        launcherShakeTimer = window.setInterval(function() {
            launcherShakeCount += 1;

            if (launcherShakeCount > 2) {
                stopLauncherReminder();
                return;
            }

            ringLauncher();
        }, 25000);
    }

    function showLauncher() {
        if (!launcher || !autoOpen || !launcherEnabled) {
            return;
        }

        updateLauncherBubble(getDraft());
        launcher.hidden = false;
        startLauncherReminder();
    }

    function hideLauncher() {
        if (!launcher) {
            return;
        }

        launcher.hidden = true;
        launcher.classList.remove('is-ringing');
        updateLauncherBubble(null);
        stopLauncherReminder();
    }

    function updateLauncherBubble(draft) {
        if (!launcherBubble) {
            return;
        }

        var draftStep = draft && hasDraftValues(draft) ? Math.max(0, parseInt(draft.step, 10) || 0) : 0;
        var remainingSteps = Math.max(1, getTotalSteps() - draftStep);

        if (!draft || draftStep < 2) {
            launcherBubble.hidden = true;
            launcherBubble.textContent = '';

            if (launcher && launcherDefaultLabel) {
                launcher.setAttribute('aria-label', launcherDefaultLabel);
            }

            return;
        }

        var message = remainingSteps === 1 ? 'Only 1 step to go' : 'Only ' + String(remainingSteps) + ' steps to go';
        launcherBubble.textContent = message;
        launcherBubble.hidden = false;

        if (launcher) {
            launcher.setAttribute('aria-label', message + '. ' + launcherDefaultLabel);
        }
    }

    function openModal() {
        if (hasOpened) {
            return;
        }

        hasOpened = true;
        lastFocusedElement = document.activeElement;
        hideLauncher();
        modal.hidden = false;

        if (dialog) {
            dialog.focus();
        }
    }

    function manualOpenModal(event) {
        if (event) {
            event.preventDefault();
        }

        if (window.sessionStorage) {
            sessionStorage.removeItem(storageKey);
        }

        hasOpened = false;
        hideLauncher();
        openModal();
    }

    function closeModal() {
        saveDraft();
        modal.hidden = true;

        if (window.sessionStorage) {
            sessionStorage.setItem(storageKey, '1');
        }

        showLauncher();

        if (launcher && launcherEnabled) {
            launcher.focus({ preventScroll: true });
        } else if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
            lastFocusedElement.focus({ preventScroll: true });
        }
    }

    closeButtons.forEach(function(button) {
        button.addEventListener('click', closeModal);
    });

    if (adminBarButton) {
        adminBarButton.addEventListener('click', manualOpenModal);
    }

    if (launcher) {
        launcher.addEventListener('click', manualOpenModal);
    }

    document.addEventListener('keydown', function(event) {
        if (modal.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            closeModal();
            return;
        }

        if (event.key === 'Tab') {
            trapFocus(event);
        }
    });

    function getFocusableElements() {
        if (!dialog) {
            return [];
        }

        return Array.prototype.slice.call(dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(function(element) {
            return !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
        });
    }

    function trapFocus(event) {
        var focusable = getFocusableElements();

        if (!focusable.length) {
            event.preventDefault();
            dialog.focus();
            return;
        }

        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function setStatus(message, type) {
        if (!status) {
            return;
        }

        status.textContent = message || '';
        status.dataset.status = type || '';
    }

    function canUseLocalStorage() {
        if (!draftEnabled) {
            return false;
        }

        try {
            if (!window.localStorage) {
                return false;
            }

            var testKey = draftStorageKey + ':test';
            localStorage.setItem(testKey, '1');
            localStorage.removeItem(testKey);

            return true;
        } catch (error) {
            return false;
        }
    }

    function getFormFields() {
        if (!form) {
            return [];
        }

        return Array.prototype.slice.call(form.querySelectorAll('input[name], textarea[name], select[name]')).filter(function(field) {
            return ['button', 'submit', 'reset', 'file'].indexOf(field.type) === -1;
        });
    }

    function collectDraftValues() {
        var values = {};

        getFormFields().forEach(function(field) {
            if (field.type === 'checkbox') {
                if (!values[field.name]) {
                    values[field.name] = [];
                }

                if (field.checked) {
                    values[field.name].push(field.value);
                }

                return;
            }

            if (field.type === 'radio') {
                if (field.checked) {
                    values[field.name] = field.value;
                }

                return;
            }

            values[field.name] = field.value;
        });

        return values;
    }

    function getDraft() {
        if (!canUseLocalStorage()) {
            return null;
        }

        try {
            var rawDraft = localStorage.getItem(draftStorageKey);
            var draft = rawDraft ? JSON.parse(rawDraft) : null;

            if (!draft || draft.schema !== draftSchema || !draft.savedAt || Date.now() - draft.savedAt > draftTtl) {
                localStorage.removeItem(draftStorageKey);
                return null;
            }

            return draft;
        } catch (error) {
            localStorage.removeItem(draftStorageKey);
            return null;
        }
    }

    function hasDraftValues(draft) {
        if (!draft || !draft.values) {
            return false;
        }

        return Object.keys(draft.values).some(function(name) {
            var value = draft.values[name];

            return Array.isArray(value) ? value.length > 0 : String(value || '').trim() !== '';
        });
    }

    function saveDraft() {
        if (isRestoringDraft || !canUseLocalStorage()) {
            return;
        }

        var draft = {
            schema: draftSchema,
            savedAt: Date.now(),
            step: currentStep,
            values: collectDraftValues()
        };

        localStorage.setItem(draftStorageKey, JSON.stringify(draft));
    }

    function scheduleDraftSave() {
        if (draftSaveTimer) {
            window.clearTimeout(draftSaveTimer);
        }

        draftSaveTimer = window.setTimeout(saveDraft, 250);
    }

    function clearDraft() {
        if (!canUseLocalStorage()) {
            return;
        }

        localStorage.removeItem(draftStorageKey);
    }

    function restoreDraft() {
        var draft = getDraft();

        if (!hasDraftValues(draft)) {
            return 0;
        }

        isRestoringDraft = true;

        getFormFields().forEach(function(field) {
            if (!Object.prototype.hasOwnProperty.call(draft.values, field.name)) {
                return;
            }

            var value = draft.values[field.name];

            if (field.type === 'checkbox') {
                field.checked = Array.isArray(value) && value.indexOf(field.value) !== -1;
                return;
            }

            if (field.type === 'radio') {
                field.checked = value === field.value;
                return;
            }

            field.value = value;
        });

        isRestoringDraft = false;
        updateCallState();
        updateChoiceState();
        updateFillButtonState();
        updateRangeState();

        return Math.max(0, parseInt(draft.step, 10) || 0);
    }

    function getTotalSteps() {
        return steps.length || 1;
    }

    function setStep(index, shouldSave) {
        var totalSteps = getTotalSteps();
        currentStep = Math.max(0, Math.min(index, totalSteps - 1));

        steps.forEach(function(step, stepIndex) {
            step.hidden = stepIndex !== currentStep;
        });

        if (currentStepText) {
            currentStepText.textContent = String(currentStep + 1);
        }

        if (totalStepsText) {
            totalStepsText.textContent = String(totalSteps);
        }

        if (progressBar) {
            progressBar.style.width = String(((currentStep + 1) / totalSteps) * 100) + '%';
        }

        if (progressMeter) {
            progressMeter.setAttribute('aria-valuenow', String(currentStep + 1));
            progressMeter.setAttribute('aria-valuemax', String(totalSteps));
        }

        if (backButton) {
            backButton.hidden = currentStep === 0;
        }

        if (nextButton) {
            nextButton.hidden = currentStep === totalSteps - 1;
        }

        if (submitButton) {
            submitButton.hidden = currentStep !== totalSteps - 1;
        }

        setStatus('', '');
        updateCallState();
        updateChoiceState();
        updateFillButtonState();
        updateRangeState();
        if (shouldSave !== false) {
            scheduleDraftSave();
        }
    }

    function getCurrentStepElement() {
        return steps[currentStep] || form;
    }

    function getRequiredFieldsForStep(step) {
        if (!step) {
            return [];
        }

        return Array.prototype.slice.call(step.querySelectorAll('input[required], textarea[required], select[required]'));
    }

    function validateStep(index, shouldReport) {
        var step = steps[index];
        var requiredFields = getRequiredFieldsForStep(step);
        var invalidField = null;
        var report = shouldReport !== false;

        requiredFields.some(function(field) {
            if (field.type === 'checkbox' || field.type === 'radio') {
                return false;
            }

            if (!field.value || !field.value.trim()) {
                invalidField = field;
                return true;
            }

            return false;
        });

        if (invalidField) {
            if (report) {
                setStatus('Give this one a quick answer before moving on.', 'error');
                invalidField.focus();
            }

            return false;
        }

        if (!validateRequiredOptionGroups(step, report)) {
            return false;
        }

        if (step && step.contains(callGroup) && !hasCheckedCall()) {
            if (report) {
                setStatus((config.messages && config.messages.callRequired) || 'Please choose at least one call you can attend weekly.', 'error');
            }

            return false;
        }

        return true;
    }

    function validateRequiredOptionGroups(step, report) {
        if (!step) {
            return true;
        }

        var invalidGroup = null;

        Array.prototype.slice.call(step.querySelectorAll('[data-tsol-accountability-required-options]')).some(function(group) {
            if (group.hasAttribute('data-tsol-accountability-modal-calls')) {
                return false;
            }

            var hasOptions = !!group.querySelector('input[type="checkbox"], input[type="radio"]');
            var hasCheckedOption = !!group.querySelector('input[type="checkbox"]:checked, input[type="radio"]:checked');

            if (hasOptions && !hasCheckedOption) {
                invalidGroup = group;
                return true;
            }

            return false;
        });

        if (!invalidGroup) {
            return true;
        }

        if (report) {
            setStatus('Choose at least one option before moving on.', 'error');

            var firstInput = invalidGroup.querySelector('input[type="checkbox"], input[type="radio"]');
            if (firstInput) {
                firstInput.focus();
            }
        }

        return false;
    }

    function validateAllSteps() {
        var invalidStep = -1;

        steps.some(function(step, index) {
            if (!validateStep(index, false)) {
                invalidStep = index;
                return true;
            }

            return false;
        });

        if (invalidStep !== -1) {
            setStep(invalidStep);
            validateStep(invalidStep);
            return false;
        }

        return true;
    }

    function hasCheckedCall() {
        if (!callGroup || !callGroup.querySelector('input[type="checkbox"]')) {
            return true;
        }

        return !!callGroup.querySelector('input[type="checkbox"]:checked');
    }

    function updateCallState() {
        var selectedCount = 0;

        modal.querySelectorAll('[data-tsol-accountability-modal-call-option]').forEach(function(option) {
            var checkbox = option.querySelector('input[type="checkbox"]');
            var isChecked = !!checkbox && checkbox.checked;

            option.classList.toggle('is-selected', isChecked);

            if (isChecked) {
                selectedCount += 1;
            }
        });

        if (callCount) {
            callCount.textContent = selectedCount === 1 ? '1 selected' : String(selectedCount) + ' selected';
        }
    }

    function updateChoiceState() {
        modal.querySelectorAll('[data-tsol-accountability-modal-choice-option]').forEach(function(option) {
            var input = option.querySelector('input[type="checkbox"], input[type="radio"]');

            option.classList.toggle('is-selected', !!input && input.checked);
        });
    }

    function updateFillButtonState() {
        fillButtons.forEach(function(button) {
            var targetId = button.getAttribute('data-tsol-accountability-modal-fill-target');
            var target = targetId ? document.getElementById(targetId) : null;

            button.classList.toggle('is-selected', !!target && button.getAttribute('data-tsol-accountability-modal-fill') === target.value);
        });
    }

    function updateRangeState() {
        rangeInputs.forEach(function(input) {
            var output = input.parentNode ? input.parentNode.querySelector('[data-tsol-accountability-range-value]') : null;

            if (output) {
                output.textContent = input.value;
            }
        });
    }

    function setFormDisabled(disabled) {
        if (!form) {
            return;
        }

        form.querySelectorAll('input, textarea, select, button').forEach(function(field) {
            field.disabled = disabled;
        });
    }

    function goForward() {
        if (!validateStep(currentStep)) {
            return;
        }

        setStep(currentStep + 1);

        var nextField = getCurrentStepElement().querySelector('textarea, input:not([type="hidden"]), select');
        if (nextField) {
            nextField.focus();
        }
    }

    function goBack() {
        setStep(currentStep - 1);
    }

    function handleFormSubmit(event) {
        event.preventDefault();

        if (!form || isSubmitting) {
            return;
        }

        if (currentStep < getTotalSteps() - 1) {
            goForward();
            return;
        }

        if (!validateAllSteps()) {
            return;
        }

        var formData = new FormData(form);
        formData.append('action', 'tsol_accountability_modal_submit');
        formData.append('nonce', config.nonce || '');

        isSubmitting = true;
        setFormDisabled(true);
        setStatus('', '');

        fetch(config.ajaxUrl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(function(response) {
                return response.json().then(function(data) {
                    if (!response.ok || !data.success) {
                        throw data;
                    }

                    return data;
                });
            })
            .then(function(data) {
                var message = data.data && data.data.message ? data.data.message : 'Thanks. Your accountability group intake has been submitted.';
                setStatus(message, 'success');
                clearDraft();
                hideLauncher();

                if (window.sessionStorage) {
                    sessionStorage.setItem(storageKey, '1');
                }

                window.setTimeout(function() {
                    modal.hidden = true;
                }, 1200);
            })
            .catch(function(error) {
                var message = error && error.data && error.data.message ? error.data.message : ((config.messages && config.messages.submitError) || 'Something went wrong. Please try again.');
                setStatus(message, 'error');
                setFormDisabled(false);
                isSubmitting = false;
            });
    }

    if (nextButton) {
        nextButton.addEventListener('click', goForward);
    }

    if (backButton) {
        backButton.addEventListener('click', goBack);
    }

    modal.querySelectorAll('[data-tsol-accountability-modal-call-option] input[type="checkbox"]').forEach(function(checkbox) {
        checkbox.addEventListener('change', updateCallState);
    });

    modal.querySelectorAll('[data-tsol-accountability-modal-choice-option] input').forEach(function(input) {
        input.addEventListener('change', updateChoiceState);
    });

    rangeInputs.forEach(function(input) {
        input.addEventListener('input', updateRangeState);
    });

    fillButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            var targetId = button.getAttribute('data-tsol-accountability-modal-fill-target');
            var target = targetId ? document.getElementById(targetId) : null;

            if (!target) {
                return;
            }

            target.value = button.getAttribute('data-tsol-accountability-modal-fill') || '';
            target.focus();
            updateFillButtonState();
            scheduleDraftSave();
        });
    });

    modal.querySelectorAll('input[type="text"], textarea').forEach(function(field) {
        field.addEventListener('input', updateFillButtonState);
    });

    if (form) {
        form.addEventListener('input', scheduleDraftSave);
        form.addEventListener('change', scheduleDraftSave);
        form.addEventListener('submit', handleFormSubmit);
    }

    function maybeOpenModal() {
        if (!autoOpen || (window.sessionStorage && sessionStorage.getItem(storageKey) === '1')) {
            window.removeEventListener('scroll', maybeOpenModal);
            if (window.sessionStorage && sessionStorage.getItem(storageKey) === '1') {
                showLauncher();
            }
            return;
        }

        if (window.scrollY >= getEffectiveScrollThreshold()) {
            window.removeEventListener('scroll', maybeOpenModal);
            openModal();
        }
    }

    function getEffectiveScrollThreshold() {
        var documentHeight = Math.max(
            document.body ? document.body.scrollHeight : 0,
            document.documentElement ? document.documentElement.scrollHeight : 0
        );
        var maxScrollableDistance = Math.max(0, documentHeight - window.innerHeight);

        return Math.min(scrollThreshold, maxScrollableDistance);
    }

    var restoredStep = restoreDraft();
    var hasSavedDraft = hasDraftValues(getDraft());
    setStep(restoredStep, hasSavedDraft);

    if (hasSavedDraft) {
        showLauncher();
    }

    window.addEventListener('scroll', maybeOpenModal, { passive: true });

    if (window.scrollY >= getEffectiveScrollThreshold()) {
        maybeOpenModal();
    }
})();
