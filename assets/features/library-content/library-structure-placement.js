(function () {
    'use strict';

    var placement = document.querySelector('[data-library-placement]');
    if (!placement) {
        return;
    }

    var typeSelect = placement.querySelector('[data-placement-type]');
    var optionsElement = placement.querySelector('[data-placement-options]');
    var warning = placement.querySelector('[data-placement-warning]');
    var manage = placement.querySelector('[data-placement-manage]');
    var options = {};
    try {
        options = JSON.parse(optionsElement ? optionsElement.textContent : '{}');
    } catch (error) {
        options = {};
    }

    function parentSelect(type) {
        return placement.querySelector('[data-placement-parent="' + type + '"]');
    }

    function groupSelect(type) {
        return placement.querySelector('[data-placement-group="' + type + '"]');
    }

    function refreshGroup(type, preserveInitial) {
        var parent = parentSelect(type);
        var group = groupSelect(type);
        var empty = placement.querySelector('[data-placement-empty="' + type + '"]');
        if (!parent || !group) {
            return;
        }
        var parentId = parent.value || '0';
        var rows = options[type] && options[type][parentId] ? options[type][parentId] : [];
        var wanted = preserveInitial ? group.getAttribute('data-selected-key') : group.value;
        group.replaceChildren();
        rows.forEach(function (row) {
            var option = document.createElement('option');
            option.value = row.key;
            option.textContent = row.title;
            option.selected = row.key === wanted;
            group.appendChild(option);
        });
        group.disabled = rows.length === 0 || typeSelect.value !== type;
        group.required = rows.length > 0 && typeSelect.value === type;
        if (empty) {
            empty.hidden = parentId === '0' || rows.length > 0;
        }
    }

    function refresh() {
        var type = typeSelect.value;
        ['course', 'series'].forEach(function (candidate) {
            var panel = placement.querySelector('[data-placement-panel="' + candidate + '"]');
            var parent = parentSelect(candidate);
            if (panel) {
                panel.hidden = candidate !== type;
            }
            if (parent) {
                parent.disabled = candidate !== type;
                parent.required = candidate === type;
            }
            refreshGroup(candidate, false);
        });

        var activeParent = parentSelect(type);
        var parentId = activeParent ? activeParent.value : '0';
        var savedType = placement.getAttribute('data-saved-placement') || 'standalone';
        var savedParentId = placement.getAttribute('data-saved-parent-id') || '0';
        if (warning) {
            warning.hidden = type === savedType && String(parentId) === String(savedParentId);
        }

        if (manage) {
            var link = manage.querySelector('a');
            var canManage = (type === 'course' || type === 'series') && parentId !== '0';
            manage.hidden = !canManage;
            if (canManage && link) {
                var adminUrl = window.ajaxurl ? window.ajaxurl.replace(/admin-ajax\.php.*$/, 'admin.php') : '/wp-admin/admin.php';
                var url = new URL(adminUrl, window.location.origin);
                url.searchParams.set('page', 'tsol-library-structure');
                url.searchParams.set('parent_id', parentId);
                link.href = url.toString();
            }
        }
    }

    ['course', 'series'].forEach(function (type) {
        refreshGroup(type, true);
        var parent = parentSelect(type);
        if (parent) {
            parent.addEventListener('change', function () {
                refreshGroup(type, false);
                refresh();
            });
        }
    });
    typeSelect.addEventListener('change', refresh);
    refresh();
})();
