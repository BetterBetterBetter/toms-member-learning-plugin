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
    var loadedCategories = {
        analytics: false,
        marketing: false
    };
    var consentStorageState = {
        source: '',
        needsPersistence: false
    };
    var previousFocus = null;
    var hasGpc = !!(config.respectGpc && navigator.globalPrivacyControl);

    function getStorageKey() {
        return config.cookieName || 'tsol_cookie_consent';
    }

    function isCategoryEnabled(category) {
        return !!(config.categories && config.categories[category] && config.categories[category].enabled);
    }

    function getCookie(name) {
        var prefix = name + '=';
        var pairs = document.cookie ? document.cookie.split(';') : [];
        var index;

        for (index = 0; index < pairs.length; index += 1) {
            pairs[index] = pairs[index].trim();

            if (pairs[index].indexOf(prefix) === 0) {
                try {
                    return decodeURIComponent(pairs[index].slice(prefix.length));
                } catch (error) {
                    return '';
                }
            }
        }

        return '';
    }

    function setCookie(name, value, days) {
        var maxAge = Math.max(30, parseInt(days, 10) || 180) * 24 * 60 * 60;
        var secure = window.location.protocol === 'https:' ? '; Secure' : '';

        document.cookie = name + '=' + encodeURIComponent(value) + '; Path=/; Max-Age=' + maxAge + '; SameSite=Lax' + secure;
    }

    function getCookieDeletionPaths() {
        var pathname = window.location.pathname || '/';
        var parts = pathname.split('/').filter(function(part) {
            return part !== '';
        });
        var paths = ['/'];
        var index;
        var path;

        for (index = parts.length; index > 0; index -= 1) {
            path = '/' + parts.slice(0, index).join('/');
            paths.push(path);
            paths.push(path + '/');
        }

        return paths.filter(function(value, index, values) {
            return values.indexOf(value) === index;
        });
    }

    function getCookieDeletionDomains() {
        var hostname = window.location.hostname || '';
        var labels;
        var domains = [''];

        if (!hostname || hostname === 'localhost' || /^\d{1,3}(?:\.\d{1,3}){3}$/.test(hostname) || hostname.indexOf(':') !== -1) {
            return domains;
        }

        domains.push(hostname);
        domains.push('.' + hostname);
        labels = hostname.split('.');

        if (labels.length > 2) {
            domains.push('.' + labels.slice(1).join('.'));
        }

        return domains.filter(function(value, index, values) {
            return values.indexOf(value) === index;
        });
    }

    function expireCookie(name) {
        var secure = window.location.protocol === 'https:' ? '; Secure' : '';
        var paths = getCookieDeletionPaths();
        var domains = getCookieDeletionDomains();

        paths.forEach(function(path) {
            domains.forEach(function(domain) {
                var domainAttribute = domain ? '; Domain=' + domain : '';

                document.cookie = name + '=; Path=' + path + domainAttribute + '; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax' + secure;
            });
        });
    }

    function clearConsentStorage() {
        expireCookie(getStorageKey());

        try {
            window.localStorage.removeItem(getStorageKey());
        } catch (error) {
            // Nothing to clear.
        }
    }

    function isStoredTimestampValid(timestamp) {
        var parsedTimestamp = Date.parse(timestamp || '');
        var now = Date.now();
        var lifetimeDays = Math.max(30, parseInt(config.cookieLifetimeDays, 10) || 180);
        var lifetimeMilliseconds = lifetimeDays * 24 * 60 * 60 * 1000;

        return !isNaN(parsedTimestamp)
            && parsedTimestamp <= now + (5 * 60 * 1000)
            && parsedTimestamp >= now - lifetimeMilliseconds;
    }

    function readStoredConsent() {
        var raw = getCookie(getStorageKey());
        var parsed;
        var analytics;
        var marketing;

        consentStorageState.source = raw ? 'cookie' : '';
        consentStorageState.needsPersistence = false;

        if (!raw) {
            try {
                raw = window.localStorage.getItem(getStorageKey()) || '';
                consentStorageState.source = raw ? 'localStorage' : '';
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
            clearConsentStorage();
            return null;
        }

        if (!parsed || parsed.version !== config.version || parsed.necessary !== true || !isStoredTimestampValid(parsed.timestamp)) {
            clearConsentStorage();
            return null;
        }

        analytics = !!(parsed.analytics && isCategoryEnabled('analytics'));
        marketing = !!(parsed.marketing && isCategoryEnabled('marketing') && !hasGpc);
        consentStorageState.needsPersistence = consentStorageState.source === 'localStorage'
            || analytics !== !!parsed.analytics
            || marketing !== !!parsed.marketing;

        return {
            version: parsed.version,
            necessary: true,
            analytics: analytics,
            marketing: marketing,
            timestamp: parsed.timestamp,
            source: parsed.source || 'stored'
        };
    }

    function storeConsent(consent) {
        var encoded = JSON.stringify(consent);

        setCookie(getStorageKey(), encoded, config.cookieLifetimeDays);

        try {
            window.localStorage.setItem(getStorageKey(), encoded);
        } catch (error) {
            // Cookie storage is the canonical persistence layer.
        }
    }

    function buildConsent(analytics, marketing, source) {
        return {
            version: config.version,
            necessary: true,
            analytics: !!(analytics && isCategoryEnabled('analytics')),
            marketing: !!(marketing && isCategoryEnabled('marketing') && !hasGpc),
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

    function getVisibleCookieNames() {
        var names = [];

        (document.cookie ? document.cookie.split(';') : []).forEach(function(pair) {
            var separator = pair.indexOf('=');
            var name = (separator === -1 ? pair : pair.slice(0, separator)).trim();

            if (name) {
                names.push(name);
            }
        });

        return names;
    }

    function cleanupCategoryCookies(category) {
        var cleanup = config.cookieCleanup && config.cookieCleanup[category] ? config.cookieCleanup[category] : {};
        var exactNames = Array.isArray(cleanup.names) ? cleanup.names : [];
        var prefixes = Array.isArray(cleanup.prefixes) ? cleanup.prefixes : [];
        var candidates = exactNames.slice();

        getVisibleCookieNames().forEach(function(name) {
            var matchesPrefix = prefixes.some(function(prefix) {
                return prefix && name.indexOf(prefix) === 0;
            });

            if ((exactNames.indexOf(name) !== -1 || matchesPrefix) && candidates.indexOf(name) === -1) {
                candidates.push(name);
            }
        });

        candidates.forEach(expireCookie);

        if (candidates.length) {
            window.dispatchEvent(new CustomEvent('tsol_cookie_consent_cleanup', {
                detail: {
                    category: category,
                    cookieNames: candidates
                }
            }));
        }
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

    function activateConsentEmbeds(category) {
        var embeds = document.querySelectorAll('[data-tsol-consent-embed][data-tsol-consent-category="' + category + '"]');

        Array.prototype.forEach.call(embeds, function(embed) {
            var iframe = embed.querySelector('iframe[data-tsol-consent-src]');
            var placeholder = embed.querySelector('[data-tsol-consent-embed-placeholder]');

            if (!iframe) {
                return;
            }

            iframe.src = iframe.getAttribute('data-tsol-consent-src');
            iframe.removeAttribute('data-tsol-consent-src');
            embed.classList.add('is-active');

            if (placeholder) {
                placeholder.hidden = true;
            }
        });
    }

    function loadCategoryScripts(category) {
        var scripts = config.scripts && config.scripts[category] ? config.scripts[category] : { urls: [], inline: [] };
        var plainTextScripts = document.querySelectorAll('script[type="text/plain"][data-tsol-consent-category="' + category + '"]');
        var consentEmbeds = document.querySelectorAll('[data-tsol-consent-embed][data-tsol-consent-category="' + category + '"]');

        if ((scripts.urls || []).length || (scripts.inline || []).length || plainTextScripts.length || consentEmbeds.length) {
            loadedCategories[category] = true;
        }

        (scripts.urls || []).forEach(function(url) {
            loadScriptUrl(url, category);
        });

        (scripts.inline || []).forEach(function(code, index) {
            loadInlineScript(code, category, index);
        });

        activatePlainTextScripts(category);
        activateConsentEmbeds(category);
    }

    function applyConsent(consent, persist) {
        var reloadRequired = false;

        updateGoogleConsentMode(consent);

        if (!consent.analytics) {
            cleanupCategoryCookies('analytics');
            reloadRequired = persist && loadedCategories.analytics;
        }

        if (!consent.marketing) {
            cleanupCategoryCookies('marketing');
            reloadRequired = reloadRequired || (persist && loadedCategories.marketing);
        }

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

        if (reloadRequired && window.location && typeof window.location.reload === 'function') {
            window.setTimeout(function() {
                window.location.reload();
            }, 100);
        }
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

        hidePreferences();

        if (consent) {
            hideBanner();
        } else {
            showBanner();
        }

        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
    }

    function hidePreferences() {
        if (preferences) {
            preferences.hidden = true;
        }

        document.removeEventListener('keydown', handleDialogKeydown);
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
        hidePreferences();
        hideBanner();

        if (status) {
            status.textContent = config.messages && config.messages.saved ? config.messages.saved : 'Cookie choices saved.';
        }
    }

    function acceptAll() {
        var consent = buildConsent(true, true, 'accept_all');

        applyConsent(consent, true);
        hidePreferences();
        hideBanner();
        syncInputs(consent);
    }

    function rejectOptional() {
        var consent = buildConsent(false, false, 'reject_optional');

        applyConsent(consent, true);
        hidePreferences();
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

    if (reopenButton) {
        reopenButton.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            openPreferences();
        });
    }

    document.addEventListener('click', function(event) {
        var adminBarLink = event.target.closest('#wp-admin-bar-tsol-cookie-consent-open a');

        if (!adminBarLink) {
            return;
        }

        event.preventDefault();
        openPreferences();
    });

    document.addEventListener('click', function(event) {
        var manageButton = event.target.closest('[data-tsol-cookie-embed-manage]');
        var videoLink = event.target.closest('a[data-elementor-lightbox-video]');
        var videoUrl = videoLink ? videoLink.getAttribute('data-elementor-lightbox-video') || '' : '';
        var consent;

        if (manageButton) {
            event.preventDefault();
            event.stopImmediatePropagation();
            openPreferences();
            return;
        }

        if (!videoLink || !/(?:player\.)?vimeo\.com|youtube(?:-nocookie)?\.com/i.test(videoUrl)) {
            return;
        }

        consent = readStoredConsent();

        if (consent && consent.marketing) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        openPreferences();
    }, true);

    window.tsolCookieConsent = {
        openPreferences: openPreferences,
        getConsent: readStoredConsent,
        acceptAll: acceptAll,
        rejectOptional: rejectOptional,
        reset: function() {
            var deniedConsent = buildConsent(false, false, 'reset');

            clearConsentStorage();
            applyConsent(deniedConsent, false);
            syncInputs(null);

            if (window.location && typeof window.location.reload === 'function') {
                window.setTimeout(function() {
                    window.location.reload();
                }, 100);
            } else {
                showBanner();
            }
        }
    };

    (function init() {
        var consent = readStoredConsent();

        if (hasGpc && gpcNotice) {
            gpcNotice.hidden = false;
        }

        if (consent) {
            if (consentStorageState.needsPersistence) {
                storeConsent(consent);
            }

            applyConsent(consent, false);
            syncInputs(consent);
            hideBanner();
        } else {
            syncInputs(null);
            showBanner();
        }
    })();
})();
