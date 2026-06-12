/* Tom's School Of Life Cookie Consent */

(function() {
    'use strict';

    var config = window.tsolCookieConsentSettings || {};
    var root = document.querySelector('[data-tsol-cookie-consent]');

    if (!root || !config.enabled) {
        return;
    }

    var banner = root.querySelector('[data-tsol-cookie-banner]');
    var preferences = root.querySelector('[data-tsol-cookie-preferences]');
    var dialog = preferences ? preferences.querySelector('.tsol-cookie-consent__dialog') : null;
    var reopenButton = root.querySelector('[data-tsol-cookie-reopen]');
    var status = root.querySelector('[data-tsol-cookie-status]');
    var gpcNotice = root.querySelector('[data-tsol-cookie-gpc-notice]');
    var categoryInputs = root.querySelectorAll('[data-tsol-cookie-category]');
    var loadedScripts = {};
    var previousFocus = null;
    var hasGpc = !!(config.respectGpc && navigator.globalPrivacyControl);

    function getCookie(name) {
        var prefix = name + '=';
        var pairs = document.cookie ? document.cookie.split('; ') : [];
        var index;

        for (index = 0; index < pairs.length; index += 1) {
            if (pairs[index].indexOf(prefix) === 0) {
                return decodeURIComponent(pairs[index].slice(prefix.length));
            }
        }

        return '';
    }

    function setCookie(name, value, days) {
        var maxAge = Math.max(30, parseInt(days, 10) || 180) * 24 * 60 * 60;
        var secure = window.location.protocol === 'https:' ? '; Secure' : '';

        document.cookie = name + '=' + encodeURIComponent(value) + '; Path=/; Max-Age=' + maxAge + '; SameSite=Lax' + secure;
    }

    function readStoredConsent() {
        var raw = getCookie(config.cookieName || 'tsol_cookie_consent');
        var parsed;

        if (!raw) {
            try {
                raw = window.localStorage.getItem(config.cookieName || 'tsol_cookie_consent') || '';
            } catch (error) {
                raw = '';
            }
        }

        if (!raw) {
            return null;
        }

        try {
            parsed = JSON.parse(raw);
        } catch (error) {
            return null;
        }

        if (!parsed || parsed.version !== config.version || parsed.necessary !== true) {
            return null;
        }

        return {
            version: parsed.version,
            necessary: true,
            analytics: !!parsed.analytics,
            marketing: !!parsed.marketing,
            timestamp: parsed.timestamp || '',
            source: parsed.source || 'stored'
        };
    }

    function storeConsent(consent) {
        var encoded = JSON.stringify(consent);

        setCookie(config.cookieName || 'tsol_cookie_consent', encoded, config.cookieLifetimeDays);

        try {
            window.localStorage.setItem(config.cookieName || 'tsol_cookie_consent', encoded);
        } catch (error) {
            // Cookie storage is the canonical persistence layer.
        }
    }

    function buildConsent(analytics, marketing, source) {
        return {
            version: config.version,
            necessary: true,
            analytics: !!(analytics && config.categories && config.categories.analytics && config.categories.analytics.enabled),
            marketing: !!(marketing && config.categories && config.categories.marketing && config.categories.marketing.enabled && !hasGpc),
            timestamp: new Date().toISOString(),
            source: source || 'banner'
        };
    }

    function getConsentModeUpdate(consent) {
        var update = {};
        var key;
        var analyticsMode = consent.analytics ? config.consentModeMap.analyticsGranted : config.consentModeMap.analyticsDenied;
        var marketingMode = consent.marketing ? config.consentModeMap.marketingGranted : config.consentModeMap.marketingDenied;

        for (key in analyticsMode) {
            if (Object.prototype.hasOwnProperty.call(analyticsMode, key)) {
                update[key] = analyticsMode[key];
            }
        }

        for (key in marketingMode) {
            if (Object.prototype.hasOwnProperty.call(marketingMode, key)) {
                update[key] = marketingMode[key];
            }
        }

        update.functionality_storage = 'granted';
        update.security_storage = 'granted';

        return update;
    }

    function updateGoogleConsentMode(consent) {
        if (!config.googleConsentMode || typeof window.gtag !== 'function') {
            return;
        }

        window.gtag('consent', 'update', getConsentModeUpdate(consent));

        if (window.dataLayer) {
            window.dataLayer.push({
                event: 'tsol_cookie_consent_update',
                tsol_cookie_analytics: consent.analytics ? 'granted' : 'denied',
                tsol_cookie_marketing: consent.marketing ? 'granted' : 'denied'
            });
        }
    }

    function hashValue(value) {
        var hash = 0;
        var index;

        for (index = 0; index < value.length; index += 1) {
            hash = ((hash << 5) - hash) + value.charCodeAt(index);
            hash |= 0;
        }

        return String(Math.abs(hash));
    }

    function loadScriptUrl(url, category) {
        var key = category + ':url:' + url;
        var script;

        if (!url || loadedScripts[key]) {
            return;
        }

        loadedScripts[key] = true;
        script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.dataset.tsolConsentLoaded = category;
        document.head.appendChild(script);
    }

    function loadInlineScript(code, category, index) {
        var key = category + ':inline:' + hashValue(code + ':' + index);
        var script;

        if (!code || loadedScripts[key]) {
            return;
        }

        loadedScripts[key] = true;
        script = document.createElement('script');
        script.dataset.tsolConsentLoaded = category;
        script.text = code;
        document.head.appendChild(script);
    }

    function activatePlainTextScripts(category) {
        var scripts = document.querySelectorAll('script[type="text/plain"][data-tsol-consent-category="' + category + '"]');

        Array.prototype.forEach.call(scripts, function(script, index) {
            var key = category + ':plain:' + index + ':' + (script.id || hashValue(script.textContent || script.src || ''));
            var replacement;
            var attrIndex;

            if (loadedScripts[key]) {
                return;
            }

            loadedScripts[key] = true;
            replacement = document.createElement('script');

            for (attrIndex = 0; attrIndex < script.attributes.length; attrIndex += 1) {
                if (script.attributes[attrIndex].name !== 'type' && script.attributes[attrIndex].name !== 'data-tsol-consent-category') {
                    replacement.setAttribute(script.attributes[attrIndex].name, script.attributes[attrIndex].value);
                }
            }

            replacement.dataset.tsolConsentLoaded = category;

            if (script.src) {
                replacement.src = script.src;
            } else {
                replacement.text = script.textContent || '';
            }

            script.parentNode.insertBefore(replacement, script.nextSibling);
        });
    }

    function loadCategoryScripts(category) {
        var scripts = config.scripts && config.scripts[category] ? config.scripts[category] : { urls: [], inline: [] };

        (scripts.urls || []).forEach(function(url) {
            loadScriptUrl(url, category);
        });

        (scripts.inline || []).forEach(function(code, index) {
            loadInlineScript(code, category, index);
        });

        activatePlainTextScripts(category);
    }

    function applyConsent(consent, persist) {
        updateGoogleConsentMode(consent);

        if (consent.analytics) {
            loadCategoryScripts('analytics');
        }

        if (consent.marketing) {
            loadCategoryScripts('marketing');
        }

        if (persist) {
            storeConsent(consent);
        }

        window.dispatchEvent(new CustomEvent('tsol_cookie_consent_updated', {
            detail: consent
        }));
    }

    function syncInputs(consent) {
        Array.prototype.forEach.call(categoryInputs, function(input) {
            var category = input.getAttribute('data-tsol-cookie-category');

            if (category === 'necessary') {
                input.checked = true;
                input.disabled = true;
                return;
            }

            if (category === 'marketing' && hasGpc) {
                input.checked = false;
                input.disabled = true;
                return;
            }

            input.checked = consent ? !!consent[category] : false;
        });

        if (gpcNotice) {
            gpcNotice.hidden = !hasGpc;
        }
    }

    function showBanner() {
        root.hidden = false;

        if (banner && config.bannerEnabled) {
            banner.hidden = false;
        }

        if (reopenButton) {
            reopenButton.hidden = !!config.bannerEnabled || !config.showReopenButton;
        }
    }

    function hideBanner() {
        root.hidden = false;

        if (banner) {
            banner.hidden = true;
        }

        if (reopenButton && config.showReopenButton) {
            reopenButton.hidden = false;
        } else if (!preferences || preferences.hidden) {
            root.hidden = true;
        }
    }

    function getFocusableElements() {
        if (!dialog) {
            return [];
        }

        return Array.prototype.slice.call(dialog.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(function(element) {
            return element.offsetParent !== null || element === dialog;
        });
    }

    function handleDialogKeydown(event) {
        var focusable;
        var first;
        var last;

        if (event.key === 'Escape') {
            event.preventDefault();
            closePreferences();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        focusable = getFocusableElements();

        if (!focusable.length) {
            event.preventDefault();
            dialog.focus();
            return;
        }

        first = focusable[0];
        last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function openPreferences() {
        var consent = readStoredConsent();

        if (!preferences || !dialog) {
            return;
        }

        previousFocus = document.activeElement;
        root.hidden = false;

        if (banner) {
            banner.hidden = true;
        }

        preferences.hidden = false;
        syncInputs(consent);
        document.addEventListener('keydown', handleDialogKeydown);
        window.setTimeout(function() {
            dialog.focus();
        }, 20);
    }

    function closePreferences() {
        var consent = readStoredConsent();

        if (!preferences) {
            return;
        }

        preferences.hidden = true;
        document.removeEventListener('keydown', handleDialogKeydown);

        if (consent) {
            hideBanner();
        } else {
            showBanner();
        }

        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
    }

    function saveCurrentPreferences() {
        var analytics = false;
        var marketing = false;
        var consent;

        Array.prototype.forEach.call(categoryInputs, function(input) {
            var category = input.getAttribute('data-tsol-cookie-category');

            if (category === 'analytics') {
                analytics = input.checked;
            } else if (category === 'marketing') {
                marketing = input.checked;
            }
        });

        consent = buildConsent(analytics, marketing, 'preferences');
        applyConsent(consent, true);
        hideBanner();

        if (preferences) {
            preferences.hidden = true;
        }

        if (status) {
            status.textContent = config.messages && config.messages.saved ? config.messages.saved : 'Cookie choices saved.';
        }
    }

    function acceptAll() {
        var consent = buildConsent(true, true, 'accept_all');

        applyConsent(consent, true);
        hideBanner();
        syncInputs(consent);
    }

    function rejectOptional() {
        var consent = buildConsent(false, false, 'reject_optional');

        applyConsent(consent, true);
        hideBanner();
        syncInputs(consent);
    }

    root.addEventListener('click', function(event) {
        var target = event.target;

        if (target.closest('[data-tsol-cookie-accept]')) {
            event.preventDefault();
            acceptAll();
        } else if (target.closest('[data-tsol-cookie-reject]')) {
            event.preventDefault();
            rejectOptional();
        } else if (target.closest('[data-tsol-cookie-manage], [data-tsol-cookie-reopen]')) {
            event.preventDefault();
            openPreferences();
        } else if (target.closest('[data-tsol-cookie-save]')) {
            event.preventDefault();
            saveCurrentPreferences();
        } else if (target.closest('[data-tsol-cookie-close]')) {
            event.preventDefault();
            closePreferences();
        }
    });

    document.addEventListener('click', function(event) {
        var adminBarLink = event.target.closest('#wp-admin-bar-tsol-cookie-consent-open a');

        if (!adminBarLink) {
            return;
        }

        event.preventDefault();
        openPreferences();
    });

    window.tsolCookieConsent = {
        openPreferences: openPreferences,
        getConsent: readStoredConsent,
        acceptAll: acceptAll,
        rejectOptional: rejectOptional,
        reset: function() {
            var secure = window.location.protocol === 'https:' ? '; Secure' : '';

            document.cookie = (config.cookieName || 'tsol_cookie_consent') + '=; Path=/; Max-Age=0; SameSite=Lax' + secure;

            try {
                window.localStorage.removeItem(config.cookieName || 'tsol_cookie_consent');
            } catch (error) {
                // Nothing to clear.
            }

            showBanner();
        }
    };

    (function init() {
        var consent = readStoredConsent();

        if (hasGpc && gpcNotice) {
            gpcNotice.hidden = false;
        }

        if (consent) {
            applyConsent(consent, false);
            syncInputs(consent);
            hideBanner();
        } else {
            syncInputs(null);
            showBanner();
        }
    })();
})();
