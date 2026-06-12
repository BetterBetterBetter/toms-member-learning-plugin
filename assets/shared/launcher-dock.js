/* Tom's School Of Life Launcher Dock */

(function() {
    'use strict';

    var positions = ['bottom_right', 'bottom_left', 'top_right', 'top_left'];
    var docks = {};

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }

        window.setTimeout(callback, 0);
    }

    function normalizePosition(position) {
        return positions.indexOf(position) !== -1 ? position : 'bottom_right';
    }

    function getDock(position) {
        position = normalizePosition(position);

        if (docks[position] && document.body.contains(docks[position])) {
            return docks[position];
        }

        docks[position] = document.querySelector('[data-tsol-launcher-dock="' + position + '"]');

        if (!docks[position]) {
            docks[position] = document.createElement('div');
            docks[position].className = 'tsol-site-launcher-dock tsol-site-launcher-dock--' + position;
            docks[position].setAttribute('data-tsol-launcher-dock', position);
            docks[position].setAttribute('aria-hidden', 'false');
            document.body.appendChild(docks[position]);
        }

        return docks[position];
    }

    function sortDock(dock) {
        var items = Array.prototype.slice.call(dock.querySelectorAll('[data-tsol-launcher-dock-item]'));

        items.sort(function(left, right) {
            var leftPriority = parseInt(left.getAttribute('data-tsol-launcher-dock-priority'), 10) || 50;
            var rightPriority = parseInt(right.getAttribute('data-tsol-launcher-dock-priority'), 10) || 50;

            return leftPriority - rightPriority;
        });

        items.forEach(function(item) {
            dock.appendChild(item);
        });
    }

    function cleanupEmptyDocks() {
        Object.keys(docks).forEach(function(position) {
            var dock = docks[position];

            if (!dock || dock.querySelector('[data-tsol-launcher-dock-item]')) {
                return;
            }

            dock.remove();
            delete docks[position];
        });
    }

    function refresh() {
        var items = Array.prototype.slice.call(document.querySelectorAll('[data-tsol-launcher-dock-item]'));
        var touched = {};

        items.forEach(function(item) {
            var position = normalizePosition(item.getAttribute('data-tsol-launcher-dock-position'));
            var dock = getDock(position);

            touched[position] = dock;
            item.setAttribute('data-tsol-launcher-docked', '1');

            if (item.parentNode !== dock) {
                dock.appendChild(item);
            }
        });

        Object.keys(touched).forEach(function(position) {
            sortDock(touched[position]);
        });

        cleanupEmptyDocks();
    }

    window.tsolSiteLauncherDock = {
        refresh: refresh
    };

    ready(refresh);
    window.addEventListener('tsol_site_launcher_dock_refresh', refresh);
})();
