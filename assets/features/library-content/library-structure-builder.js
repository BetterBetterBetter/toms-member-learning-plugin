(function ($) {
    'use strict';

    var config = window.tsolLibraryStructureBuilder || {};
    var messages = config.messages || {};
    var builder = document.querySelector('[data-structure-builder]');
    if (!builder) {
        return;
    }

    var groups = builder.querySelector('[data-structure-groups]');
    var saveButtons = Array.prototype.slice.call(builder.querySelectorAll('[data-structure-save]'));
    var status = builder.querySelector('[data-structure-status]');
    var errorNotice = builder.querySelector('[data-structure-error]');
    var search = builder.querySelector('[data-structure-search]');
    var filterNote = builder.querySelector('[data-structure-filter-note]');
    var dirty = false;
    var saving = false;
    var searchDisclosure = null;
    var disclosureStorageKey = 'tsolLibraryStructureDisclosure:' + builder.getAttribute('data-parent-id');

    function groupElements() {
        return Array.prototype.slice.call(groups.querySelectorAll(':scope > [data-structure-group]'));
    }

    function itemElements(group) {
        return Array.prototype.slice.call(group.querySelectorAll('[data-structure-items] > [data-structure-item]'));
    }

    function groupTitle(group) {
        var input = group.querySelector('[data-group-title]');
        return input ? input.value.trim() : '';
    }

    function makeDirty() {
        dirty = true;
        saveButtons.forEach(function (button) {
            button.disabled = false;
        });
        if (status) {
            status.textContent = messages.unsaved || 'You have unsaved structure changes.';
        }
        hideError();
        refreshControls();
    }

    function hideError() {
        if (!errorNotice) {
            return;
        }
        errorNotice.hidden = true;
        var paragraph = errorNotice.querySelector('p');
        if (paragraph) {
            paragraph.textContent = '';
        }
    }

    function showError(message) {
        if (errorNotice) {
            errorNotice.hidden = false;
            var paragraph = errorNotice.querySelector('p');
            if (paragraph) {
                paragraph.textContent = message;
            }
            errorNotice.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        if (status) {
            status.textContent = message;
        }
    }

    function collapsedKeys() {
        return groupElements().filter(function (group) {
            var body = group.querySelector('[data-group-body]');
            return body && body.hidden;
        }).map(function (group) {
            return group.getAttribute('data-group-key');
        });
    }

    function storeDisclosure(keys) {
        try {
            window.sessionStorage.setItem(disclosureStorageKey, JSON.stringify(keys));
        } catch (error) {
            // Storage can be unavailable in privacy-restricted browsers. The
            // disclosure controls still work for the current page.
        }
    }

    function setCollapsed(group, collapsed, remember) {
        var body = group.querySelector('[data-group-body]');
        var toggle = group.querySelector('[data-group-toggle]');
        if (!body || !toggle) {
            return;
        }
        body.hidden = collapsed;
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        var icon = toggle.querySelector('.dashicons');
        if (icon) {
            icon.classList.toggle('dashicons-arrow-right-alt2', collapsed);
            icon.classList.toggle('dashicons-arrow-down-alt2', !collapsed);
        }
        toggle.setAttribute('aria-label', (collapsed ? 'Expand ' : 'Collapse ') + (groupTitle(group) || messages.newGroup || 'group'));
        if (remember !== false) {
            if (searchDisclosure) {
                searchDisclosure[group.getAttribute('data-group-key')] = collapsed;
                storeDisclosure(Object.keys(searchDisclosure).filter(function (key) { return searchDisclosure[key]; }));
            } else {
                storeDisclosure(collapsedKeys());
            }
        }
    }

    function initializeDisclosure() {
        var remembered = null;
        try {
            var raw = window.sessionStorage.getItem(disclosureStorageKey);
            remembered = raw === null ? null : JSON.parse(raw);
        } catch (error) {
            remembered = null;
        }
        if (remembered !== null && !Array.isArray(remembered)) {
            remembered = null;
        }
        var collapseByDefault = builder.getAttribute('data-start-collapsed') === 'true';
        groupElements().forEach(function (group) {
            var key = group.getAttribute('data-group-key');
            setCollapsed(group, remembered === null ? collapseByDefault : remembered.indexOf(key) !== -1, false);
        });
    }

    function moveElement(element, direction) {
        var focused = document.activeElement;
        if (direction < 0 && element.previousElementSibling) {
            element.parentNode.insertBefore(element, element.previousElementSibling);
            if (focused && element.contains(focused)) {
                focused.focus({ preventScroll: true });
            }
            makeDirty();
        } else if (direction > 0 && element.nextElementSibling) {
            element.parentNode.insertBefore(element.nextElementSibling, element);
            if (focused && element.contains(focused)) {
                focused.focus({ preventScroll: true });
            }
            makeDirty();
        }
    }

    function refreshCounts() {
        groupElements().forEach(function (group) {
            var count = itemElements(group).length;
            var label = group.querySelector('[data-group-count]');
            var empty = group.querySelector('[data-group-empty]');
            var remove = group.querySelector('[data-group-remove]');
            if (label) {
                label.textContent = count + ' ' + (count === 1
                    ? (builder.getAttribute('data-item-label') || 'item')
                    : (builder.getAttribute('data-item-plural') || 'items'));
            }
            if (empty) {
                empty.hidden = count > 0;
            }
            if (remove) {
                remove.disabled = count > 0;
            }
        });
    }

    function refreshSelects() {
        var options = groupElements().map(function (group) {
            return {
                key: group.getAttribute('data-group-key'),
                title: groupTitle(group) || messages.newGroup || 'New group'
            };
        });

        groupElements().forEach(function (group) {
            itemElements(group).forEach(function (item) {
                var select = item.querySelector('[data-item-group-select]');
                if (!select) {
                    return;
                }
                var current = group.getAttribute('data-group-key');
                select.replaceChildren();
                options.forEach(function (optionData) {
                    var option = document.createElement('option');
                    option.value = optionData.key;
                    option.textContent = optionData.title;
                    option.selected = optionData.key === current;
                    select.appendChild(option);
                });
            });
        });
    }

    function refreshControls() {
        var allGroups = groupElements();
        allGroups.forEach(function (group, groupIndex) {
            var up = group.querySelector('[data-group-up]');
            var down = group.querySelector('[data-group-down]');
            if (up) {
                up.disabled = groupIndex === 0;
            }
            if (down) {
                down.disabled = groupIndex === allGroups.length - 1;
            }
            var items = itemElements(group);
            items.forEach(function (item, itemIndex) {
                var itemUp = item.querySelector('[data-item-up]');
                var itemDown = item.querySelector('[data-item-down]');
                if (itemUp) {
                    itemUp.disabled = itemIndex === 0;
                }
                if (itemDown) {
                    itemDown.disabled = itemIndex === items.length - 1;
                }
            });
        });
        refreshCounts();
        refreshSelects();
    }

    function initSortables() {
        $(groups).sortable({
            items: '> [data-structure-group]',
            handle: '[data-group-handle]',
            cancel: 'input, textarea, select, option, a',
            placeholder: 'tsol-library-structure-group--placeholder',
            tolerance: 'pointer',
            update: makeDirty
        });
        $(builder.querySelectorAll('[data-structure-items]')).sortable({
            connectWith: '[data-structure-items]',
            items: '> [data-structure-item]',
            handle: '[data-item-handle]',
            cancel: 'input, textarea, select, option, a',
            placeholder: 'tsol-library-structure-item--placeholder',
            tolerance: 'pointer',
            receive: function () {
                makeDirty();
                refreshControls();
            },
            update: function (event, ui) {
                if (!ui.sender) {
                    makeDirty();
                }
                refreshControls();
            }
        });
    }

    function refreshSortables() {
        $(groups).sortable('refresh');
        $(builder.querySelectorAll('[data-structure-items]')).sortable('refresh');
    }

    function newKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return 'group-' + window.crypto.randomUUID().toLowerCase();
        }
        return 'group-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    function addGroup() {
        var template = builder.querySelector('[data-structure-group-template]');
        if (!template) {
            return;
        }
        var fragment = template.content.cloneNode(true);
        var group = fragment.querySelector('[data-structure-group]');
        var key = newKey();
        group.removeAttribute('data-template-group');
        group.setAttribute('data-group-key', key);
        var title = group.querySelector('[data-group-title]');
        if (title) {
            title.value = messages.newGroup || 'New group';
        }
        var addItem = group.querySelector('[data-group-add-item]');
        if (addItem) {
            var url = new URL(addItem.href, window.location.href);
            url.searchParams.set('tsol_structure_key', key);
            addItem.href = url.toString();
        }
        groups.appendChild(fragment);
        initNewItemsList(group);
        refreshSortables();
        refreshControls();
        makeDirty();
        setCollapsed(group, false);
        if (title) {
            title.focus();
            title.select();
        }
    }

    function initNewItemsList(group) {
        var list = group.querySelector('[data-structure-items]');
        if (!list) {
            return;
        }
        $(list).sortable({
            connectWith: '[data-structure-items]',
            items: '> [data-structure-item]',
            handle: '[data-item-handle]',
            cancel: 'input, textarea, select, option, a',
            placeholder: 'tsol-library-structure-item--placeholder',
            tolerance: 'pointer',
            receive: function () {
                makeDirty();
                refreshControls();
            },
            update: function (event, ui) {
                if (!ui.sender) {
                    makeDirty();
                }
                refreshControls();
            }
        });
    }

    function applySearch() {
        var term = search ? search.value.trim().toLowerCase() : '';
        var filtered = term.length > 0;
        if (filtered && searchDisclosure === null) {
            searchDisclosure = {};
            groupElements().forEach(function (group) {
                var body = group.querySelector('[data-group-body]');
                searchDisclosure[group.getAttribute('data-group-key')] = Boolean(body && body.hidden);
            });
        }
        groupElements().forEach(function (group) {
            var groupMatches = groupTitle(group).toLowerCase().indexOf(term) !== -1;
            var itemMatchCount = 0;
            itemElements(group).forEach(function (item) {
                var matches = !filtered || groupMatches || (item.getAttribute('data-search-text') || '').indexOf(term) !== -1;
                item.hidden = !matches;
                if (matches) {
                    itemMatchCount += 1;
                }
            });
            group.hidden = filtered && !groupMatches && itemMatchCount === 0;
            if (filtered && !group.hidden) {
                setCollapsed(group, false, false);
            }
        });
        if (!filtered && searchDisclosure !== null) {
            groupElements().forEach(function (group) {
                setCollapsed(group, Boolean(searchDisclosure[group.getAttribute('data-group-key')]), false);
            });
            searchDisclosure = null;
        }
        $(groups).sortable(filtered ? 'disable' : 'enable');
        $(builder.querySelectorAll('[data-structure-items]')).sortable(filtered ? 'disable' : 'enable');
        if (filterNote) {
            filterNote.hidden = !filtered;
            filterNote.textContent = filtered ? (messages.filteredSorting || 'Clear the search before reordering.') : '';
        }
    }

    function serialize() {
        return {
            groups: groupElements().map(function (group) {
                return {
                    key: group.getAttribute('data-group-key'),
                    title: groupTitle(group),
                    items: itemElements(group).map(function (item) {
                        return parseInt(item.getAttribute('data-item-id'), 10);
                    })
                };
            })
        };
    }

    function validate() {
        var invalid = groupElements().find(function (group) {
            return groupTitle(group) === '';
        });
        if (invalid) {
            setCollapsed(invalid, false);
            var input = invalid.querySelector('[data-group-title]');
            if (input) {
                input.focus();
            }
            showError(messages.groupTitle || 'Every group needs a title.');
            return false;
        }
        return true;
    }

    function save() {
        if (saving || !dirty || !validate()) {
            return;
        }
        saving = true;
        hideError();
        saveButtons.forEach(function (button) {
            button.disabled = true;
            button.classList.add('is-busy');
        });
        if (status) {
            status.textContent = messages.saving || 'Saving structure…';
        }

        var formData = new FormData();
        formData.append('action', config.action || 'tsol_library_save_structure');
        formData.append('nonce', config.nonce || '');
        formData.append('parent_id', builder.getAttribute('data-parent-id'));
        formData.append('revision', builder.getAttribute('data-revision'));
        formData.append('structure', JSON.stringify(serialize()));

        window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, data: { message: messages.saveError || 'The structure could not be saved.' } };
            });
        }).then(function (response) {
            if (!response || !response.success) {
                throw new Error(response && response.data && response.data.message ? response.data.message : (messages.saveError || 'The structure could not be saved.'));
            }
            builder.setAttribute('data-revision', response.data.revision);
            dirty = false;
            if (status) {
                status.textContent = response.data.message || messages.saved || 'Structure saved.';
            }
        }).catch(function (error) {
            dirty = true;
            showError(error.message || messages.saveError || 'The structure could not be saved.');
        }).finally(function () {
            saving = false;
            saveButtons.forEach(function (button) {
                button.classList.remove('is-busy');
                button.disabled = !dirty;
            });
        });
    }

    builder.addEventListener('click', function (event) {
        var target = event.target.closest('button');
        if (!target) {
            return;
        }
        if (target.matches('[data-structure-save]')) {
            save();
        } else if (target.matches('[data-structure-add-group]')) {
            addGroup();
        } else if (target.matches('[data-group-toggle]')) {
            var toggleGroup = target.closest('[data-structure-group]');
            setCollapsed(toggleGroup, target.getAttribute('aria-expanded') === 'true');
        } else if (target.matches('[data-structure-expand]')) {
            groupElements().forEach(function (group) { setCollapsed(group, false); });
        } else if (target.matches('[data-structure-collapse]')) {
            groupElements().forEach(function (group) { setCollapsed(group, true); });
        } else if (target.matches('[data-group-up]')) {
            moveElement(target.closest('[data-structure-group]'), -1);
        } else if (target.matches('[data-group-down]')) {
            moveElement(target.closest('[data-structure-group]'), 1);
        } else if (target.matches('[data-item-up]')) {
            moveElement(target.closest('[data-structure-item]'), -1);
        } else if (target.matches('[data-item-down]')) {
            moveElement(target.closest('[data-structure-item]'), 1);
        } else if (target.matches('[data-group-remove]')) {
            var removeGroup = target.closest('[data-structure-group]');
            if (itemElements(removeGroup).length === 0) {
                removeGroup.remove();
                refreshSortables();
                makeDirty();
            }
        }
    });

    builder.addEventListener('input', function (event) {
        if (event.target.matches('[data-group-title]')) {
            makeDirty();
        }
    });

    builder.addEventListener('change', function (event) {
        if (!event.target.matches('[data-item-group-select]')) {
            return;
        }
        var destination = groupElements().find(function (group) {
            return group.getAttribute('data-group-key') === event.target.value;
        });
        var item = event.target.closest('[data-structure-item]');
        if (destination && item) {
            destination.querySelector('[data-structure-items]').appendChild(item);
            setCollapsed(destination, false);
            makeDirty();
        }
    });

    if (search) {
        search.addEventListener('input', applySearch);
    }
    window.addEventListener('beforeunload', function (event) {
        if (!dirty || saving) {
            return;
        }
        event.preventDefault();
        event.returnValue = messages.unsaved || '';
    });

    initializeDisclosure();
    initSortables();
    refreshControls();
})(jQuery);
