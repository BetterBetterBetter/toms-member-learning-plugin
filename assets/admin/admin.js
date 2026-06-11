/* Tom's School Of Life Plugin Admin JavaScript */

(function($) {
    'use strict';

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function initPagePicker($picker) {
        var $search = $picker.find('[data-page-picker-search]');
        var $clear = $picker.find('[data-page-picker-clear]');
        var $selected = $picker.find('[data-page-picker-selected]');
        var $count = $picker.find('[data-page-picker-count]');
        var $empty = $picker.find('[data-page-picker-empty]');
        var $visibleCount = $picker.find('[data-page-picker-visible-count]');
        var $typeFilter = $picker.find('[data-page-picker-type-filter]');
        var $statusFilter = $picker.find('[data-page-picker-status-filter]');
        var $selectionFilter = $picker.find('[data-page-picker-selection-filter]');
        var $options = $picker.find('[data-page-picker-option]');

        function getSelectedOptions() {
            return $options.filter(function() {
                return $(this).find('[data-page-picker-checkbox]').is(':checked');
            });
        }

        function renderSelected() {
            var $selectedOptions = getSelectedOptions();
            var count = $selectedOptions.length;
            var selectedHtml = '';

            $count.text(count === 1 ? '1 location' : count + ' locations');

            if (!count) {
                $selected.html('<span class="tsol-page-picker__none">No display locations selected</span>');
                return;
            }

            $selectedOptions.each(function() {
                var $option = $(this);
                var pageId = $option.data('page-id');
                var title = $option.data('page-title');
                var path = $option.data('page-path');
                var typeLabel = $option.data('page-type-label');
                var statusLabel = $option.data('page-status-label');
                var statusClass = $option.data('page-status-class');

                selectedHtml += '<div class="tsol-page-picker__selected-row" data-page-picker-chip="' + escapeHtml(pageId) + '">';
                selectedHtml += '<div class="tsol-page-picker__selected-copy">';
                selectedHtml += '<strong>' + escapeHtml(title) + '</strong>';
                selectedHtml += '<small>' + escapeHtml(path) + '</small>';
                selectedHtml += '</div>';
                selectedHtml += '<div class="tsol-page-picker__selected-meta">';
                selectedHtml += '<span class="tsol-site-status tsol-page-picker__type">' + escapeHtml(typeLabel) + '</span>';
                selectedHtml += '<span class="tsol-site-status tsol-page-picker__status ' + escapeHtml(statusClass) + '">' + escapeHtml(statusLabel) + '</span>';
                selectedHtml += '</div>';
                selectedHtml += '<button type="button" class="button button-small" data-page-picker-remove="' + escapeHtml(pageId) + '">Remove</button>';
                selectedHtml += '</div>';
            });

            $selected.html(selectedHtml);
        }

        function filterOptions() {
            var term = $.trim($search.val()).toLowerCase();
            var type = $typeFilter.val();
            var status = $statusFilter.val();
            var selection = $selectionFilter.val();
            var visibleCount = 0;

            $options.each(function() {
                var $option = $(this);
                var haystack = String($option.data('page-search') || '');
                var isChecked = $option.find('[data-page-picker-checkbox]').is(':checked');
                var isVisible = true;

                if (term && haystack.indexOf(term) === -1) {
                    isVisible = false;
                }

                if (type && String($option.data('page-type')) !== type) {
                    isVisible = false;
                }

                if (status && String($option.data('page-status')) !== status) {
                    isVisible = false;
                }

                if (selection === 'selected' && !isChecked) {
                    isVisible = false;
                }

                if (selection === 'unselected' && isChecked) {
                    isVisible = false;
                }

                $option.toggle(isVisible);

                if (isVisible) {
                    visibleCount += 1;
                }
            });

            $empty.prop('hidden', visibleCount > 0);
            $visibleCount.text(visibleCount === 1 ? '1 item shown' : visibleCount + ' items shown');
        }

        $picker.on('change', '[data-page-picker-checkbox]', function() {
            $(this).closest('[data-page-picker-option]').toggleClass('is-selected', $(this).is(':checked'));
            renderSelected();
            filterOptions();
        });

        $picker.on('click', '[data-page-picker-remove]', function() {
            var pageId = String($(this).data('page-picker-remove'));
            var $checkbox = $options.filter(function() {
                return String($(this).data('page-id')) === pageId;
            }).find('[data-page-picker-checkbox]');

            $checkbox.prop('checked', false).trigger('change');
        });

        $search.on('input', filterOptions);
        $typeFilter.on('change', filterOptions);
        $statusFilter.on('change', filterOptions);
        $selectionFilter.on('change', filterOptions);

        $clear.on('click', function() {
            $search.val('');
            $typeFilter.val('');
            $statusFilter.val('');
            $selectionFilter.val('');
            filterOptions();
            $search.trigger('focus');
        });

        renderSelected();
        filterOptions();
    }

    function initQuestionBuilder($builder) {
        var $rows = $builder.find('[data-question-builder-rows]');
        var template = $builder.find('[data-question-builder-template]').html();
        var nextIndex = $rows.find('[data-question-row]').length;

        function slugify(value) {
            return $.trim(String(value || ''))
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '');
        }

        function setRowOpen($row, isOpen) {
            $row.toggleClass('is-open', isOpen);
            $row.find('[data-question-row-body]').prop('hidden', !isOpen);
            $row.find('[data-question-row-toggle]').attr('aria-expanded', isOpen ? 'true' : 'false');
        }

        function updateAutoKey($row) {
            var $keyInput = $row.find('[data-question-key]');

            if ($keyInput.data('keyLocked')) {
                return;
            }

            $keyInput.val(slugify($row.find('[data-question-title] input').val()));
        }

        function updateRowSummary($row) {
            var title = $.trim($row.find('[data-question-title] input').val());
            var $type = $row.find('[data-question-type]');
            var typeLabel = $type.find('option:selected').text();

            updateAutoKey($row);
            $row.find('[data-question-summary]').text(title || 'Untitled question');
            $row.find('[data-question-type-summary]').text(typeLabel);
        }

        function updateTypeControls($row) {
            var type = $row.find('[data-question-type]').val();
            var showsOptions = ['text', 'textarea', 'select', 'checkbox', 'radio'].indexOf(type) !== -1;
            var showsNumberSettings = ['number', 'range'].indexOf(type) !== -1;
            var showsPlaceholder = ['text', 'textarea', 'number', 'select'].indexOf(type) !== -1;

            $row.find('[data-question-options-setting]').toggle(showsOptions);
            $row.find('[data-question-number-setting]').toggle(showsNumberSettings);
            $row.find('[data-question-placeholder-setting]').toggle(showsPlaceholder);
            updateRowSummary($row);
        }

        function updateMoveButtons() {
            var $allRows = $rows.find('[data-question-row]');

            $allRows.each(function(index) {
                var $row = $(this);

                $row.find('[data-question-row-up]').prop('disabled', index === 0);
                $row.find('[data-question-row-down]').prop('disabled', index === $allRows.length - 1);
            });
        }

        function reindexRows() {
            $rows.find('[data-question-row]').each(function(index) {
                var $row = $(this);
                var $keyInput = $row.find('[data-question-key]');

                if (typeof $keyInput.data('keyLocked') === 'undefined') {
                    $keyInput.data('keyLocked', $.trim($keyInput.val()) !== '');
                }

                $row.find('[name]').each(function() {
                    this.name = this.name.replace(/\[questions]\[[^\]]+]/, '[questions][' + index + ']');
                });

                $row.find('[id]').each(function() {
                    if (this.id.indexOf('tsol-question-row-body-') === 0) {
                        this.id = 'tsol-question-row-body-' + index;
                        return;
                    }

                    this.id = this.id.replace(/tsol-question-[^-]+-/, 'tsol-question-' + index + '-');
                });

                $row.find('[for]').each(function() {
                    $(this).attr('for', $(this).attr('for').replace(/tsol-question-[^-]+-/, 'tsol-question-' + index + '-'));
                });

                $row.find('[data-question-row-toggle]').attr('aria-controls', 'tsol-question-row-body-' + index);
                updateTypeControls($row);
            });

            updateMoveButtons();
        }

        function addRow() {
            var html = template.replace(/__index__/g, String(nextIndex));
            var $row = $(html);

            nextIndex += 1;
            $rows.append($row);
            reindexRows();
            setRowOpen($row, true);
            $row.find('[name$="[title]"]').trigger('focus');
        }

        $builder.on('click', '[data-question-builder-add]', addRow);

        $builder.on('click', '[data-question-row-toggle]', function() {
            var $row = $(this).closest('[data-question-row]');

            setRowOpen($row, !$row.hasClass('is-open'));
        });

        $builder.on('click', '[data-question-row-remove]', function() {
            $(this).closest('[data-question-row]').remove();
            reindexRows();
        });

        $builder.on('click', '[data-question-row-up]', function() {
            var $row = $(this).closest('[data-question-row]');
            var $previous = $row.prev('[data-question-row]');

            if ($previous.length) {
                $row.insertBefore($previous);
                reindexRows();
            }
        });

        $builder.on('click', '[data-question-row-down]', function() {
            var $row = $(this).closest('[data-question-row]');
            var $next = $row.next('[data-question-row]');

            if ($next.length) {
                $row.insertAfter($next);
                reindexRows();
            }
        });

        $builder.on('change', '[data-question-type]', function() {
            updateTypeControls($(this).closest('[data-question-row]'));
        });

        $builder.on('input', '[data-question-title] input', function() {
            updateRowSummary($(this).closest('[data-question-row]'));
        });

        reindexRows();
    }

    function initSubmissionsManager($controls) {
        var $cards = $('[data-submission-card]');
        var $list = $('[data-submissions-list]');
        var $noResults = $('[data-submissions-no-results]');
        var $pagination = $('[data-submissions-pagination]');
        var $resultCount = $('[data-submissions-result-count]');
        var exportScript = document.querySelector('[data-submissions-export]');
        var exportRows = [];
        var currentIndexes = [];
        var currentPage = 1;

        if (!$cards.length || !exportScript) {
            return;
        }

        try {
            exportRows = JSON.parse(exportScript.textContent || '[]');
        } catch (error) {
            exportRows = [];
        }

        function getFilters() {
            return {
                search: $.trim($controls.find('[data-submissions-search]').val()).toLowerCase(),
                status: $controls.find('[data-submissions-status-filter]').val(),
                call: $controls.find('[data-submissions-call-filter]').val(),
                from: $controls.find('[data-submissions-date-from]').val(),
                to: $controls.find('[data-submissions-date-to]').val(),
                sort: $controls.find('[data-submissions-sort]').val(),
                pageSize: parseInt($controls.find('[data-submissions-page-size]').val(), 10) || 10
            };
        }

        function dateToTimestamp(value, endOfDay) {
            if (!value) {
                return 0;
            }

            var parts = value.split('-').map(function(part) {
                return parseInt(part, 10);
            });

            if (parts.length !== 3 || !parts[0] || !parts[1] || !parts[2]) {
                return 0;
            }

            var date = new Date(parts[0], parts[1] - 1, parts[2], endOfDay ? 23 : 0, endOfDay ? 59 : 0, endOfDay ? 59 : 0);

            return Math.floor(date.getTime() / 1000);
        }

        function matchesFilters($card, filters) {
            var submitted = parseInt($card.data('submission-submitted'), 10) || 0;
            var from = dateToTimestamp(filters.from, false);
            var to = dateToTimestamp(filters.to, true);
            var callIds = String($card.data('submission-call-ids') || '').split(',');

            if (filters.search && String($card.data('submission-search') || '').indexOf(filters.search) === -1) {
                return false;
            }

            if (filters.status && String($card.data('submission-status')) !== filters.status) {
                return false;
            }

            if (filters.call && callIds.indexOf(filters.call) === -1) {
                return false;
            }

            if (from && submitted < from) {
                return false;
            }

            if (to && submitted > to) {
                return false;
            }

            return true;
        }

        function compareCards($left, $right, sort) {
            var leftSubmitted = parseInt($left.data('submission-submitted'), 10) || 0;
            var rightSubmitted = parseInt($right.data('submission-submitted'), 10) || 0;
            var leftName = String($left.data('submission-name') || '');
            var rightName = String($right.data('submission-name') || '');
            var leftCalls = parseInt($left.data('submission-call-count'), 10) || 0;
            var rightCalls = parseInt($right.data('submission-call-count'), 10) || 0;
            var leftStatus = String($left.data('submission-status') || '');
            var rightStatus = String($right.data('submission-status') || '');

            if (sort === 'oldest') {
                return leftSubmitted - rightSubmitted;
            }

            if (sort === 'name_asc') {
                return leftName.localeCompare(rightName);
            }

            if (sort === 'name_desc') {
                return rightName.localeCompare(leftName);
            }

            if (sort === 'status') {
                return (leftStatus === 'review' ? 0 : 1) - (rightStatus === 'review' ? 0 : 1) || rightSubmitted - leftSubmitted;
            }

            if (sort === 'calls_desc') {
                return rightCalls - leftCalls || rightSubmitted - leftSubmitted;
            }

            return rightSubmitted - leftSubmitted;
        }

        function renderPagination(total, pageSize) {
            var totalPages = Math.max(1, Math.ceil(total / pageSize));
            var html = '';

            currentPage = Math.min(currentPage, totalPages);

            if (totalPages <= 1) {
                $pagination.empty();
                return;
            }

            html += '<button type="button" class="button" data-submissions-page="' + (currentPage - 1) + '"' + (currentPage === 1 ? ' disabled' : '') + '>Previous</button>';
            html += '<span>Page ' + currentPage + ' of ' + totalPages + '</span>';
            html += '<button type="button" class="button" data-submissions-page="' + (currentPage + 1) + '"' + (currentPage === totalPages ? ' disabled' : '') + '>Next</button>';

            $pagination.html(html);
        }

        function applyState(resetPage) {
            var filters = getFilters();
            var matched = [];
            var start;
            var end;

            if (resetPage) {
                currentPage = 1;
            }

            $cards.each(function() {
                var $card = $(this);

                if (matchesFilters($card, filters)) {
                    matched.push($card);
                }
            });

            matched.sort(function(left, right) {
                return compareCards(left, right, filters.sort);
            });

            currentIndexes = matched.map(function($card) {
                return parseInt($card.data('submission-index'), 10);
            });

            $cards.hide();

            start = (currentPage - 1) * filters.pageSize;
            end = start + filters.pageSize;

            matched.slice(start, end).forEach(function($card) {
                $list.append($card);
                $card.show();
            });

            $noResults.prop('hidden', matched.length > 0);
            $resultCount.text(matched.length === 1 ? '1 submission shown' : matched.length + ' submissions shown');
            renderPagination(matched.length, filters.pageSize);
        }

        function getRowsByIndexes(indexes) {
            var wanted = {};

            indexes.forEach(function(index) {
                wanted[index] = true;
            });

            return exportRows.filter(function(row) {
                return !!wanted[row.index];
            });
        }

        function csvEscape(value) {
            value = value === null || typeof value === 'undefined' ? '' : String(value);

            return '"' + value.replace(/"/g, '""') + '"';
        }

        function downloadCsv(rows, filename) {
            var answerLabels = [];
            var baseHeaders = ['User ID', 'Name', 'Email', 'Status', 'Submitted At', 'Occupation', 'Selected Call Count', 'Selected Calls'];
            var csvRows = [];
            var blob;
            var url;
            var link;

            rows.forEach(function(row) {
                Object.keys(row.answers || {}).forEach(function(label) {
                    if (answerLabels.indexOf(label) === -1) {
                        answerLabels.push(label);
                    }
                });
            });

            csvRows.push(baseHeaders.concat(answerLabels).map(csvEscape).join(','));

            rows.forEach(function(row) {
                var values = [
                    row.user_id,
                    row.name,
                    row.email,
                    row.status,
                    row.submitted_at,
                    row.occupation,
                    row.selected_call_count,
                    row.selected_calls
                ];

                answerLabels.forEach(function(label) {
                    values.push(row.answers && row.answers[label] ? row.answers[label] : '');
                });

                csvRows.push(values.map(csvEscape).join(','));
            });

            blob = new Blob([csvRows.join('\n')], { type: 'text/csv;charset=utf-8;' });
            url = URL.createObjectURL(blob);
            link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        $controls.on('input change', 'input, select', function() {
            applyState(true);
        });

        $controls.on('click', '[data-submissions-clear]', function() {
            $controls.find('input').val('');
            $controls.find('select').prop('selectedIndex', 0);
            applyState(true);
        });

        $controls.on('click', '[data-submissions-export-filtered]', function() {
            downloadCsv(getRowsByIndexes(currentIndexes), 'accountability-submissions-filtered.csv');
        });

        $controls.on('click', '[data-submissions-export-all]', function() {
            downloadCsv(exportRows, 'accountability-submissions-all.csv');
        });

        $pagination.on('click', '[data-submissions-page]', function() {
            currentPage = parseInt($(this).data('submissions-page'), 10) || 1;
            applyState(false);
        });

        applyState(true);
    }

    $(document).ready(function() {
        $('[data-page-picker]').each(function() {
            initPagePicker($(this));
        });

        $('[data-question-builder]').each(function() {
            initQuestionBuilder($(this));
        });

        $('[data-submissions-controls]').each(function() {
            initSubmissionsManager($(this));
        });

        $(document).trigger('tsolSitePluginAdminReady');
    });
})(jQuery);
