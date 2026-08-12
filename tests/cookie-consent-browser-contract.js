/* eslint-env node */

'use strict';

var assert = require('assert');
var fs = require('fs');
var path = require('path');
var vm = require('vm');
var consentScript = fs.readFileSync(path.join(__dirname, '../assets/features/cookie-consent/cookie-consent.js'), 'utf8');

function createElement(attributes) {
    attributes = attributes || {};

    return {
        hidden: true,
        checked: false,
        disabled: false,
        dataset: {},
        offsetParent: {},
        getAttribute: function(name) {
            return attributes[name] || '';
        },
        setAttribute: function(name, value) {
            attributes[name] = value;
        },
        addEventListener: function() {},
        focus: function() {},
        closest: function() {
            return null;
        },
        querySelectorAll: function() {
            return [];
        }
    };
}

function buildEnvironment(options) {
    options = options || {};

    var cookieJar = Object.assign({}, options.cookies || {});
    var localStorageValues = Object.assign({}, options.localStorage || {});
    var appendedScripts = [];
    var events = [];
    var reloads = 0;
    var banner = createElement();
    var preferences = createElement();
    var dialog = createElement();
    var reopen = createElement();
    var status = createElement();
    var gpcNotice = createElement();
    var inputs = ['necessary', 'analytics', 'marketing'].map(function(category) {
        return createElement({ 'data-tsol-cookie-category': category });
    });
    var root = createElement();
    var document = {
        activeElement: createElement(),
        head: {
            appendChild: function(script) {
                appendedScripts.push(script);
            }
        },
        querySelector: function(selector) {
            return selector === '[data-tsol-cookie-consent]' ? root : null;
        },
        querySelectorAll: function() {
            return [];
        },
        createElement: function(tagName) {
            var element = createElement();

            element.tagName = tagName;
            element.attributes = [];
            element.text = '';

            return element;
        },
        addEventListener: function() {},
        removeEventListener: function() {}
    };

    Object.defineProperty(document, 'cookie', {
        get: function() {
            return Object.keys(cookieJar).map(function(name) {
                return name + '=' + cookieJar[name];
            }).join('; ');
        },
        set: function(value) {
            var parts = value.split(';');
            var assignment = parts[0];
            var separator = assignment.indexOf('=');
            var name = assignment.slice(0, separator);
            var cookieValue = assignment.slice(separator + 1);
            var expired = parts.some(function(part) {
                return /^\s*Max-Age=0\s*$/i.test(part);
            });

            if (expired) {
                delete cookieJar[name];
            } else {
                cookieJar[name] = cookieValue;
            }
        }
    });

    preferences.querySelector = function(selector) {
        return selector === '.tsol-cookie-consent__dialog' ? dialog : null;
    };
    root.querySelector = function(selector) {
        return {
            '[data-tsol-cookie-banner]': banner,
            '[data-tsol-cookie-preferences]': preferences,
            '[data-tsol-cookie-reopen]': reopen,
            '[data-tsol-cookie-status]': status,
            '[data-tsol-cookie-gpc-notice]': gpcNotice
        }[selector] || null;
    };
    root.querySelectorAll = function(selector) {
        return selector === '[data-tsol-cookie-category]' ? inputs : [];
    };

    var settings = {
        enabled: true,
        bannerEnabled: true,
        cookieName: 'tsol_cookie_consent',
        version: 'contract-test',
        cookieLifetimeDays: 30,
        respectGpc: true,
        showReopenButton: true,
        googleConsentMode: true,
        categories: {
            necessary: { enabled: true },
            analytics: { enabled: true },
            marketing: { enabled: true }
        },
        scripts: {
            analytics: { urls: [], inline: [] },
            marketing: { urls: [], inline: [] }
        },
        cookieCleanup: {
            analytics: { names: ['_ga'], prefixes: ['_ga_'] },
            marketing: { names: ['_gcl_au'], prefixes: ['_gcl_'] }
        },
        consentModeMap: {
            analyticsGranted: { analytics_storage: 'granted' },
            analyticsDenied: { analytics_storage: 'denied' },
            marketingGranted: { ad_storage: 'granted' },
            marketingDenied: { ad_storage: 'denied' }
        },
        messages: { saved: 'Saved.' }
    };

    Object.assign(settings, options.settings || {});

    var window = {
        document: document,
        localStorage: {
            getItem: function(key) {
                return Object.prototype.hasOwnProperty.call(localStorageValues, key) ? localStorageValues[key] : null;
            },
            setItem: function(key, value) {
                localStorageValues[key] = value;
            },
            removeItem: function(key) {
                delete localStorageValues[key];
            }
        },
        location: {
            protocol: 'https:',
            hostname: 'www.tomschooloflife.com',
            pathname: '/',
            reload: function() {
                reloads += 1;
            }
        },
        dataLayer: [],
        gtag: function() {},
        setTimeout: function(callback) {
            callback();
        },
        dispatchEvent: function(event) {
            events.push(event);
        },
        tsolCookieConsentSettings: settings
    };

    function CustomEvent(type, init) {
        this.type = type;
        this.detail = init ? init.detail : null;
    }

    vm.runInNewContext(consentScript, {
        window: window,
        document: document,
        navigator: { globalPrivacyControl: !!options.gpc },
        CustomEvent: CustomEvent,
        Date: Date,
        JSON: JSON,
        Array: Array,
        Object: Object,
        Math: Math,
        parseInt: parseInt,
        isNaN: isNaN
    });

    return {
        window: window,
        cookieJar: cookieJar,
        localStorage: localStorageValues,
        appendedScripts: appendedScripts,
        events: events,
        getReloads: function() {
            return reloads;
        }
    };
}

function encodedConsent(overrides) {
    return encodeURIComponent(JSON.stringify(Object.assign({
        version: 'contract-test',
        necessary: true,
        analytics: true,
        marketing: true,
        timestamp: new Date().toISOString(),
        source: 'contract_test'
    }, overrides || {})));
}

var expired = buildEnvironment({
    localStorage: {
        tsol_cookie_consent: decodeURIComponent(encodedConsent({
            timestamp: new Date(Date.now() - (31 * 24 * 60 * 60 * 1000)).toISOString()
        }))
    }
});
assert.strictEqual(expired.window.tsolCookieConsent.getConsent(), null, 'Expired localStorage consent should be rejected.');
assert.strictEqual(expired.localStorage.tsol_cookie_consent, undefined, 'Expired localStorage consent should be removed.');

var gpc = buildEnvironment({
    gpc: true,
    cookies: { tsol_cookie_consent: encodedConsent() }
});
assert.strictEqual(gpc.window.tsolCookieConsent.getConsent().marketing, false, 'GPC should override stored marketing consent.');
assert.strictEqual(JSON.parse(decodeURIComponent(gpc.cookieJar.tsol_cookie_consent)).marketing, false, 'The GPC-normalized choice should be persisted.');

var withdrawal = buildEnvironment({
    cookies: {
        tsol_cookie_consent: encodedConsent(),
        _gcl_au: 'campaign-cookie'
    },
    settings: {
        scripts: {
            analytics: { urls: [], inline: [] },
            marketing: { urls: [], inline: ['window.marketingTrackerLoaded = true;'] }
        }
    }
});
assert.strictEqual(withdrawal.appendedScripts.length, 1, 'Granted marketing scripts should load once.');
withdrawal.window.tsolCookieConsent.rejectOptional();
assert.strictEqual(JSON.parse(decodeURIComponent(withdrawal.cookieJar.tsol_cookie_consent)).marketing, false, 'Rejected marketing consent should be persisted.');
assert.strictEqual(withdrawal.cookieJar._gcl_au, undefined, 'Known marketing cookies should be removed after rejection.');
assert.strictEqual(withdrawal.getReloads(), 1, 'Withdrawing consent after loading a tracker should reload the page.');

console.log('Cookie consent browser contract checks passed.');
