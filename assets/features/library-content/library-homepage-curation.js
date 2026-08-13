(function($) {
    'use strict';

    $(function() {
        var root = $('[data-homepage-curation]');
        if (!root.length) {
            return;
        }

        var lists = root.find('[data-homepage-list]');
        var status = root.find('[data-homepage-status]');

        function sync() {
            lists.each(function() {
                var list = $(this);
                var rail = String(list.data('rail') || '');
                var records = list.children('[data-homepage-record]');
                records.each(function(index) {
                    var record = $(this);
                    var input = record.find('[data-homepage-input]');
                    var select = record.find('[data-homepage-rail-select]');
                    select.val(rail);
                    if (rail) {
                        input.prop('disabled', false).attr('name', 'tsol_library_homepage[rails][' + rail + '][]');
                        record.find('[data-homepage-position]').text('· ' + (index + 1) + ' of ' + records.length);
                    } else {
                        input.prop('disabled', true).removeAttr('name');
                        record.find('[data-homepage-position]').text('');
                    }
                });
                list.children('[data-homepage-empty]').prop('hidden', records.length > 0);
            });
            status.text('Homepage order has unsaved changes.');
        }

        function moveToRail(record, rail) {
            var destination = lists.filter(function() {
                return String($(this).data('rail') || '') === rail;
            }).first();
            if (destination.length) {
                record.insertBefore(destination.children('[data-homepage-empty]').first());
                sync();
            }
        }

        lists.sortable({
            connectWith: '[data-homepage-list]',
            handle: '[data-homepage-handle]',
            cancel: 'input, textarea, select, option',
            items: '> [data-homepage-record]',
            placeholder: 'tsol-library-homepage-record tsol-library-homepage-record--placeholder',
            forcePlaceholderSize: true,
            update: sync,
        });

        root.on('change', '[data-homepage-rail-select]', function() {
            moveToRail($(this).closest('[data-homepage-record]'), String($(this).val() || ''));
        });

        root.on('click', '[data-homepage-move]', function() {
            var button = $(this);
            var record = button.closest('[data-homepage-record]');
            var direction = button.data('homepage-move');
            var sibling = 'up' === direction
                ? record.prevAll('[data-homepage-record]:visible').first()
                : record.nextAll('[data-homepage-record]:visible').first();
            if (!sibling.length) {
                return;
            }
            if ('up' === direction) {
                record.insertBefore(sibling);
            } else {
                record.insertAfter(sibling);
            }
            sync();
            record.find('[data-homepage-move="' + direction + '"]').trigger('focus');
        });

        root.on('input', '[data-homepage-search]', function() {
            var query = String($(this).val() || '').trim().toLowerCase();
            root.find('[data-homepage-record]').each(function() {
                var record = $(this);
                record.prop('hidden', query && String(record.data('title') || '').indexOf(query) === -1);
            });
        });

        root.find('[data-homepage-form]').on('submit', function() {
            sync();
            status.text('Saving homepage…');
        });

        sync();
        status.text('');
    });
})(jQuery);
