/* Member Library canonical URL editor */

(function() {
    'use strict';

    function slugify(value) {
        var normalized = String(value || '').trim().toLowerCase();

        if (normalized.normalize) {
            normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }

        return normalized
            .replace(/[’']/g, '')
            .replace(/[^\p{L}\p{N}]+/gu, '-')
            .replace(/^-+|-+$/g, '')
            .replace(/-{2,}/g, '-');
    }

    function selectedParentSlug(kind) {
        var select = document.querySelector('[data-placement-parent="' + kind + '"]');
        var option = select && select.options ? select.options[select.selectedIndex] : null;

        return option ? String(option.getAttribute('data-library-parent-slug') || '') : '';
    }

    function routePrefix(editor) {
        var postType = editor.getAttribute('data-library-post-type');

        if (postType === 'tsol_library_course') {
            return '/courses';
        }
        if (postType === 'tsol_library_series') {
            return '/series';
        }
        if (postType === 'tsol_library_speaker') {
            return '/speakers';
        }
        if (postType !== 'tsol_library_item') {
            return '';
        }

        var placement = document.querySelector('[data-placement-type]');
        var placementType = placement ? String(placement.value || '') : '';
        var parentSlug = '';

        if (placementType === 'course') {
            parentSlug = selectedParentSlug('course');
            return '/courses/' + (parentSlug || '[select-course]');
        }
        if (placementType === 'series') {
            parentSlug = selectedParentSlug('series');
            return '/series/' + (parentSlug || '[select-series]');
        }
        return '/recordings';
    }

    function initEditor(editor) {
        var input = editor.querySelector('[data-library-slug]');
        var prefix = editor.querySelector('[data-library-url-prefix]');
        var slugText = editor.querySelector('[data-library-slug-text]');
        var viewControls = editor.querySelector('[data-library-slug-view-controls]');
        var editControls = editor.querySelector('[data-library-slug-edit-controls]');
        var editButton = editor.querySelector('[data-library-slug-edit]');
        var confirmButton = editor.querySelector('[data-library-slug-confirm]');
        var cancelButton = editor.querySelector('[data-library-slug-cancel]');
        var warning = editor.querySelector('[data-library-slug-warning]');
        var title = document.getElementById('title');
        var followsTitle = editor.getAttribute('data-library-auto-slug') === '1';
        var savedSlug = input ? String(input.value || '') : '';
        var acceptedSlug = savedSlug;

        if (!input || !prefix || !slugText || !viewControls || !editControls) {
            return;
        }

        function updatePath() {
            var pathPrefix = routePrefix(editor);
            var slug = slugify(input.value);

            prefix.textContent = pathPrefix + '/';
            slugText.textContent = slug;
            if (warning) {
                warning.hidden = !savedSlug || slug === savedSlug;
            }
        }

        function setEditing(isEditing) {
            viewControls.hidden = isEditing;
            editControls.hidden = !isEditing;
            if (isEditing) {
                input.focus();
                input.select();
            }
        }

        input.addEventListener('input', function() {
            followsTitle = false;
            updatePath();
        });

        if (title) {
            title.addEventListener('input', function() {
                if (!followsTitle) {
                    return;
                }
                input.value = slugify(title.value);
                acceptedSlug = input.value;
                updatePath();
            });
        }

        if (editButton) {
            editButton.addEventListener('click', function() {
                acceptedSlug = String(input.value || '');
                setEditing(true);
            });
        }

        if (confirmButton) {
            confirmButton.addEventListener('click', function() {
                input.value = slugify(input.value) || slugify(title ? title.value : '');
                acceptedSlug = input.value;
                updatePath();
                setEditing(false);
                editButton.focus();
            });
        }

        if (cancelButton) {
            cancelButton.addEventListener('click', function() {
                input.value = acceptedSlug;
                updatePath();
                setEditing(false);
                editButton.focus();
            });
        }

        input.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                confirmButton.click();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                cancelButton.click();
            }
        });

        document.querySelectorAll('[data-placement-type], [data-placement-parent]').forEach(function(control) {
            control.addEventListener('change', updatePath);
        });

        updatePath();
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-library-url-editor]').forEach(initEditor);
    });
})();
