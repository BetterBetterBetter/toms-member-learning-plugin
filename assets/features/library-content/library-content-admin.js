/* Member Library content editor */

(function($) {
    'use strict';

    function config() {
        return window.tsolLibraryContentAdmin || { strings: {} };
    }

    function strings() {
        return config().strings || {};
    }

    function providerConfig(provider) {
        return (config().mediaProviders || {})[provider] || {};
    }

    function closeMediaPreview($row) {
        $row.find('[data-media-test-player]').prop('hidden', true).empty();
        $row.find('[data-media-test]').text(strings().testPlayback || 'Test playback').attr('aria-expanded', 'false');
    }

    function updateMediaProviderUi($row) {
        var provider = String($row.find('[data-media-provider]').val() || 'vimeo');
        var providerUi = providerConfig(provider);

        $row.find('[data-media-provider-help]').text(providerUi.help || '');
        $row.find('[data-media-url-label]').text(providerUi.urlLabel || 'Media URL');
        $row.find('[data-media-url]').attr('placeholder', providerUi.placeholder || 'https://…');
        $row.find('[data-media-url-help]').text(providerUi.urlHelp || '');
        $row.find('[data-media-library]').prop('hidden', provider !== 'wordpress');
    }

    function updateMediaReplacementWarning($row) {
        var originalUrl = $.trim(String($row.attr('data-original-url') || ''));
        var currentUrl = $.trim(String($row.find('[data-media-url]').val() || ''));
        $row.find('[data-media-replacement-warning]').prop('hidden', !originalUrl || originalUrl === currentUrl);
    }

    function replaceIndex(value, index) {
        return String(value || '').replace(/__index__/g, String(index));
    }

    function updateMoveButtons($container, rowSelector) {
        var $rows = $container.children(rowSelector);

        $rows.each(function(index) {
            var $row = $(this);
            $row.find('[data-row-up]').prop('disabled', index === 0);
            $row.find('[data-row-down]').prop('disabled', index === $rows.length - 1);
        });
    }

    function reindexRows($container, rowSelector, groupName) {
        $container.children(rowSelector).each(function(index) {
            $(this).find('[name]').each(function() {
                this.name = this.name.replace(
                    new RegExp('(tsol_library\\[' + groupName + '\\]\\[)[^\\]]+(\\])'),
                    '$1' + index + '$2'
                );
            });
        });

        updateMoveButtons($container, rowSelector);
    }

    function moveRow($row, direction) {
        var $target = direction === 'up' ? $row.prev() : $row.next();

        if (!$target.length) {
            return;
        }

        if (direction === 'up') {
            $row.insertBefore($target);
        } else {
            $row.insertAfter($target);
        }
    }

    function createMediaFrame(options) {
        if (!window.wp || !window.wp.media) {
            return null;
        }

        return window.wp.media({
            title: options.title,
            button: { text: options.button },
            multiple: false,
            library: options.library || {},
        });
    }

    function renderMediaEmpty($row) {
        $row.removeClass('is-normalized has-media-error is-checking');
        $row.removeData('normalizedMedia');
        $row.find('[data-media-url]').removeAttr('aria-invalid');
        $row.find('[data-media-test]').prop('disabled', true);
        closeMediaPreview($row);
        $row.find('[data-media-result]')
            .removeClass('is-error is-success')
            .html('<span>' + $('<div>').text(strings().empty || 'Paste a media URL.').html() + '</span>');
    }

    function renderMediaError($row, message) {
        $row.removeClass('is-normalized is-checking').addClass('has-media-error');
        $row.removeData('normalizedMedia');
        $row.find('[data-media-url]').attr('aria-invalid', 'true');
        $row.find('[data-media-test]').prop('disabled', true);
        closeMediaPreview($row);
        $row.find('[data-media-result]')
            .removeClass('is-success').addClass('is-error')
            .html('<span class="dashicons dashicons-warning" aria-hidden="true"></span><span>' + $('<div>').text(message || strings().error || 'Invalid media URL.').html() + '</span>');
    }

    function renderMediaSuccess($row, media) {
        var html = '<span class="tsol-library-provider-badge">' + $('<div>').text(media.provider_label || media.provider).html() + '</span>';
        var copy = strings();

        if (media.provider_id) {
            html += '<span>' + $('<div>').text(copy.providerId || 'ID').html() + ' <code>' + $('<div>').text(media.provider_id).html() + '</code></span>';
        }
        if (media.has_privacy_hash) {
            html += '<span><span class="dashicons dashicons-lock" aria-hidden="true"></span> ' + $('<div>').text(copy.privateVimeo || 'Private Vimeo reference detected').html() + '</span>';
        }
        if (media.attachment_id) {
            html += '<span>' + $('<div>').text(copy.wordpressAttachment || 'WordPress attachment').html() + ' <code>#' + Number(media.attachment_id) + '</code></span>';
        }

        $row.removeClass('has-media-error is-checking').addClass('is-normalized');
        $row.data('normalizedMedia', media);
        $row.find('[data-media-url]').removeAttr('aria-invalid');
        $row.find('[data-media-test]').prop('disabled', !media.preview_url);
        $row.find('[data-media-result]').removeClass('is-error').addClass('is-success').html(html);
        $row.find('[data-media-summary]').text(media.provider_label || media.provider || 'Untitled media');
    }

    function normalizeMediaRow($row) {
        var url = $.trim($row.find('[data-media-url]').val());
        var requestNumber = Number($row.data('mediaRequest') || 0) + 1;

        $row.data('mediaRequest', requestNumber);
        window.clearTimeout($row.data('mediaTimer'));

        if (!url) {
            renderMediaEmpty($row);
            return;
        }

        $row.removeClass('has-media-error').addClass('is-checking');
        $row.find('[data-media-result]')
            .removeClass('is-error')
            .html('<span class="spinner is-active" aria-hidden="true"></span><span>' + $('<div>').text(strings().checking || 'Checking URL…').html() + '</span>');

        $.ajax({
            url: config().ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: config().action,
                nonce: config().nonce,
                post_id: config().postId,
                url: url,
            },
        }).done(function(response) {
            if (requestNumber !== Number($row.data('mediaRequest'))) {
                return;
            }

            if (!response || !response.success || !response.data) {
                renderMediaError($row, response && response.data ? response.data.message : '');
                return;
            }

            var selectedProvider = String($row.find('[data-media-provider]').val() || '');
            if (selectedProvider && selectedProvider !== response.data.provider) {
                var actualLabel = response.data.provider_label || response.data.provider;
                var expectedLabel = providerConfig(selectedProvider).label || selectedProvider;
                var mismatch = strings().providerMismatch || 'This URL resolves to %1$s. Choose that source type or enter a %2$s URL.';
                renderMediaError($row, mismatch.replace('%1$s', actualLabel).replace('%2$s', expectedLabel));
                return;
            }

            renderMediaSuccess($row, response.data);
        }).fail(function(xhr) {
            var response = xhr && xhr.responseJSON;

            if (requestNumber !== Number($row.data('mediaRequest'))) {
                return;
            }

            renderMediaError($row, response && response.data ? response.data.message : '');
        });
    }

    function initMediaEditor($editor) {
        var $rows = $editor.find('[data-media-rows]');
        var template = $editor.find('[data-media-template]').html();
        var nextIndex = $rows.children('[data-media-row]').length;

        function reindex() {
            reindexRows($rows, '[data-media-row]', 'media_assets');
            $rows.children('[data-media-row]').each(function(index) {
                var $row = $(this);
                var provider = $.trim($row.find('.tsol-library-provider-badge').text());

                if (provider) {
                    $row.find('[data-media-summary]').text(provider);
                }
                $row.find('[data-media-role]')
                    .toggleClass('is-primary', index === 0)
                    .text(index === 0 ? (strings().primaryMedia || 'Primary playback source') : (strings().secondaryMedia || 'Additional playback source'));
            });
        }

        $editor.on('click', '[data-media-add]', function() {
            var $row = $(replaceIndex(template, nextIndex));

            nextIndex += 1;
            $rows.append($row);
            updateMediaProviderUi($row);
            reindex();
            $row.find('[data-media-url]').trigger('focus');
        });

        $editor.on('click', '[data-media-remove]', function() {
            var $row = $(this).closest('[data-media-row]');

            if ($rows.children('[data-media-row]').length === 1) {
                $row.find('input[type="text"], input[type="url"], input[type="number"], input[type="hidden"]').val('');
                $row.find('input[type="checkbox"]').prop('checked', false);
                renderMediaEmpty($row);
                updateMediaReplacementWarning($row);
            } else {
                $row.remove();
            }
            reindex();
        });

        $editor.on('click', '[data-row-up], [data-row-down]', function() {
            var $button = $(this);
            moveRow($button.closest('[data-media-row]'), $button.is('[data-row-up]') ? 'up' : 'down');
            reindex();
        });

        $editor.on('input', '[data-media-url]', function() {
            var $row = $(this).closest('[data-media-row]');

            $row.removeData('normalizedMedia');
            $row.find('[data-media-test]').prop('disabled', true);
            closeMediaPreview($row);
            updateMediaReplacementWarning($row);
            window.clearTimeout($row.data('mediaTimer'));
            $row.data('mediaTimer', window.setTimeout(function() {
                normalizeMediaRow($row);
            }, 450));
        });
        $editor.on('blur', '[data-media-url]', function() {
            normalizeMediaRow($(this).closest('[data-media-row]'));
        });
        $editor.on('change', '[data-media-provider]', function() {
            var $row = $(this).closest('[data-media-row]');
            updateMediaProviderUi($row);
            closeMediaPreview($row);
            normalizeMediaRow($row);
        });

        $editor.on('click', '[data-media-library]', function() {
            var $row = $(this).closest('[data-media-row]');
            var frame = createMediaFrame({
                title: 'Choose Library media',
                button: 'Use this media',
                library: { type: ['video', 'audio'] },
            });

            if (!frame) {
                return;
            }
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $row.find('[data-media-provider]').val('wordpress');
                updateMediaProviderUi($row);
                $row.find('[data-media-url]').val(attachment.url || '').trigger('input');
            });
            frame.open();
        });

        $editor.on('click', '[data-media-test]', function() {
            var $button = $(this);
            var $row = $button.closest('[data-media-row]');
            var $player = $row.find('[data-media-test-player]');
            var media = $row.data('normalizedMedia');

            if (!$player.prop('hidden')) {
                closeMediaPreview($row);
                return;
            }
            if (!media || !media.preview_url) {
                return;
            }

            var $output;
            if (media.preview_type === 'iframe') {
                $output = $('<iframe>', {
                    src: media.preview_url,
                    title: strings().previewTitle || 'Media playback test',
                    allow: 'autoplay; encrypted-media; fullscreen; picture-in-picture',
                    allowfullscreen: 'allowfullscreen',
                });
            } else if (media.preview_type === 'audio') {
                $output = $('<audio>', { src: media.preview_url, controls: 'controls', preload: 'metadata' });
            } else {
                $output = $('<video>', { src: media.preview_url, controls: 'controls', preload: 'metadata', playsinline: 'playsinline' });
            }
            $player.empty().append($output).prop('hidden', false);
            $button.text(strings().hidePlayback || 'Hide test player').attr('aria-expanded', 'true');
        });

        $rows.children('[data-media-row]').each(function() {
            var $row = $(this);
            updateMediaProviderUi($row);
            updateMediaReplacementWarning($row);
            if ($.trim($row.find('[data-media-url]').val())) {
                normalizeMediaRow($row);
            }
        });
        reindex();
    }

    function initAvailabilityEditor($editor) {
        var $select = $editor.find('[data-library-availability-select]');
        var $releaseField = $editor.find('[data-library-release-field]');

        if (!$select.length || !$releaseField.length) {
            return;
        }

        function updateReleaseField() {
            var comingSoon = $select.val() === 'coming_soon';
            $releaseField.prop('hidden', !comingSoon);
            $releaseField.find('input').prop('disabled', !comingSoon);
        }

        $select.on('change', updateReleaseField);
        updateReleaseField();
    }

    function initResourceEditor($editor) {
        var $rows = $editor.find('[data-resource-rows]');
        var template = $editor.find('[data-resource-template]').html();
        var nextIndex = $rows.children('[data-resource-row]').length;

        function reindex() {
            reindexRows($rows, '[data-resource-row]', 'resources');
            $rows.children('[data-resource-row]').each(function() {
                var $row = $(this);
                $row.find('[data-resource-summary]').text($.trim($row.find('[data-resource-label]').val()) || 'Untitled resource');
            });
        }

        $editor.on('click', '[data-resource-add]', function() {
            var $row = $(replaceIndex(template, nextIndex));
            nextIndex += 1;
            $rows.append($row);
            reindex();
            $row.find('[data-resource-label]').trigger('focus');
        });

        $editor.on('click', '[data-resource-remove]', function() {
            var $row = $(this).closest('[data-resource-row]');
            if ($rows.children('[data-resource-row]').length === 1) {
                $row.find('input').val('');
                $row.find('select').val('link');
            } else {
                $row.remove();
            }
            reindex();
        });

        $editor.on('click', '[data-row-up], [data-row-down]', function() {
            var $button = $(this);
            moveRow($button.closest('[data-resource-row]'), $button.is('[data-row-up]') ? 'up' : 'down');
            reindex();
        });
        $editor.on('input', '[data-resource-label]', reindex);

        $editor.on('click', '[data-resource-library]', function() {
            var $row = $(this).closest('[data-resource-row]');
            var frame = createMediaFrame({ title: 'Choose a Library resource', button: 'Use this file' });

            if (!frame) {
                return;
            }
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $row.find('[data-resource-url]').val(attachment.url || '');
                $row.find('[data-resource-attachment]').val(attachment.id || 0);
                if (!$.trim($row.find('[data-resource-label]').val())) {
                    $row.find('[data-resource-label]').val(attachment.title || '').trigger('input');
                }
            });
            frame.open();
        });

        reindex();
    }

    function initCoursePageEditor($editor) {
        var $rows = $editor.find('[data-outcome-rows]');
        var template = $editor.find('[data-outcome-template]').html();
        var nextIndex = $rows.children('[data-outcome-row]').length;
        var maximumOutcomes = 12;

        function reindex() {
            reindexRows($rows, '[data-outcome-row]', 'learning_outcomes');
            $rows.children('[data-outcome-row]').each(function(index) {
                var $row = $(this);
                var position = index + 1;

                $row.find('[data-outcome-position]').text(position);
                $row.find('[data-outcome-handle]').attr('aria-label', 'Drag outcome ' + position + ' to reorder');
                $row.find('[data-row-up]').attr('aria-label', 'Move outcome ' + position + ' up');
                $row.find('[data-row-down]').attr('aria-label', 'Move outcome ' + position + ' down');
                $row.find('[data-outcome-remove]').attr('aria-label', 'Remove outcome ' + position);
            });
            $editor.find('[data-outcome-add]').prop('disabled', $rows.children('[data-outcome-row]').length >= maximumOutcomes);
        }

        $editor.on('click', '[data-outcome-add]', function() {
            if ($rows.children('[data-outcome-row]').length >= maximumOutcomes) {
                return;
            }

            var $row = $(replaceIndex(template, nextIndex));

            nextIndex += 1;
            $rows.append($row);
            if ($rows.hasClass('ui-sortable')) {
                $rows.sortable('refresh');
            }
            reindex();
            $row.find('[data-outcome-title]').trigger('focus');
        });

        $editor.on('click', '[data-outcome-remove]', function() {
            var $row = $(this).closest('[data-outcome-row]');

            if ($rows.children('[data-outcome-row]').length === 1) {
                $row.find('[data-outcome-title], [data-outcome-description]').val('');
            } else {
                $row.remove();
            }
            reindex();
        });

        $editor.on('click', '[data-row-up], [data-row-down]', function() {
            var $button = $(this);

            moveRow($button.closest('[data-outcome-row]'), $button.is('[data-row-up]') ? 'up' : 'down');
            reindex();
        });

        if ($.fn.sortable) {
            $rows.sortable({
                cancel: 'input, textarea, select, option',
                handle: '[data-outcome-handle]',
                items: '> [data-outcome-row]',
                placeholder: 'tsol-library-outcome-row--placeholder',
                forcePlaceholderSize: true,
                tolerance: 'pointer',
                update: reindex,
            });
        }

        reindex();
    }

    function arrangeCourseEditorialFlow() {
        if (config().postType !== 'tsol_library_course') {
            return;
        }

        var $bodyEditor = $('#postdivrich');
        var $excerpt = $('#postexcerpt');
        var $outcomes = $('#tsol-library-course-page-content');
        var $curriculum = $('#tsol-library-curriculum');

        if (!$bodyEditor.length || !$excerpt.length || !$outcomes.length || !$curriculum.length) {
            return;
        }

        var copy = strings();
        var headingId = 'tsol-library-course-body-heading';
        var $flow = $('<div>', {
            'class': 'tsol-library-course-editorial-flow',
            'data-course-editorial-flow': '',
        });
        var $bodySection = $('<section>', {
            'class': 'tsol-library-course-body-section',
            'aria-labelledby': headingId,
            'data-course-body-section': '',
        });
        var $bodyHeading = $('<h2>', {
            id: headingId,
            text: copy.courseBodyTitle || 'About this course',
        });
        $flow.insertBefore($bodyEditor);
        $flow.append($excerpt, $outcomes, $curriculum);
        $bodySection.append($bodyHeading, $bodyEditor);
        $flow.append($bodySection);
    }

    function arrangeSeriesEditorialFlow() {
        if (config().postType !== 'tsol_library_series') {
            return;
        }

        var $bodyEditor = $('#postdivrich');
        var $excerpt = $('#postexcerpt');
        var $settings = $('#tsol-library-series-settings');
        var $episodes = $('#tsol-library-series-episodes');

        if (!$bodyEditor.length || !$excerpt.length || !$settings.length || !$episodes.length) {
            return;
        }

        var copy = strings();
        var headingId = 'tsol-library-series-body-heading';
        var $flow = $('<div>', {
            'class': 'tsol-library-editorial-flow tsol-library-series-editorial-flow',
            'data-series-editorial-flow': '',
        });
        var $bodySection = $('<section>', {
            'class': 'tsol-library-editorial-body-section',
            'aria-labelledby': headingId,
            'data-series-body-section': '',
        });
        var $bodyHeading = $('<h2>', {
            id: headingId,
            text: copy.seriesBodyTitle || 'Description',
        });

        $flow.insertBefore($bodyEditor);
        $flow.append($excerpt, $settings, $episodes);
        $bodySection.append($bodyHeading, $bodyEditor);
        $flow.append($bodySection);
    }

    function arrangeContentEditorialFlow() {
        if (config().postType !== 'tsol_library_item') {
            return;
        }

        var $bodyEditor = $('#postdivrich');
        var $excerpt = $('#postexcerpt');
        var $placement = $('#tsol-library-placement');
        var $media = $('#tsol-library-media');
        var $resources = $('#tsol-library-resources');

        if (!$bodyEditor.length || !$excerpt.length || !$placement.length || !$media.length || !$resources.length) {
            return;
        }

        var copy = strings();
        var headingId = 'tsol-library-content-body-heading';
        var $flow = $('<div>', {
            'class': 'tsol-library-editorial-flow tsol-library-content-editorial-flow',
            'data-content-editorial-flow': '',
        });
        var $bodySection = $('<section>', {
            'class': 'tsol-library-editorial-body-section',
            'aria-labelledby': headingId,
            'data-content-body-section': '',
        });
        var $bodyHeading = $('<h2>', {
            id: headingId,
            text: copy.contentBodyTitle || 'Description',
        });

        $flow.insertAfter($placement);
        $flow.append($media, $excerpt);
        $bodySection.append($bodyHeading, $bodyEditor);
        $flow.append($bodySection, $resources);
    }

    function initExcerptGuidance() {
        var $box = $('#postexcerpt');
        var $field = $box.find('#excerpt');

        if (!$box.length || !$field.length) {
            return;
        }

        var copy = strings();
        var recommendedLength = 160;
        var countId = 'tsol-library-excerpt-count';
        var warningId = 'tsol-library-excerpt-warning';
        var $description = $box.find('.inside > p').first();
        var $status = $('<div>', {
            'class': 'tsol-library-excerpt-status',
            'data-library-excerpt-status': '',
        });
        var $count = $('<span>', {
            id: countId,
            'class': 'tsol-library-excerpt-status__count',
            'data-library-excerpt-count': '',
        });
        var $warning = $('<span>', {
            id: warningId,
            'class': 'tsol-library-excerpt-status__warning',
            'data-library-excerpt-warning': '',
            role: 'status',
            hidden: true,
            text: copy.excerptLongWarning || 'Search engines may truncate longer descriptions.',
        });

        function normalizedLength() {
            var value = $.trim(String($field.val() || '')).replace(/\s+/g, ' ');

            return Array.from(value).length;
        }

        function countLabel(length) {
            return String(copy.excerptCountTemplate || '%1$d / %2$d recommended')
                .replace('%1$d', String(length))
                .replace('%2$d', String(recommendedLength));
        }

        function update() {
            var length = normalizedLength();
            var isLong = length > recommendedLength;

            $count.text(countLabel(length));
            $status.toggleClass('is-over-recommended', isLong);
            $warning.prop('hidden', !isLong);
        }

        if ($description.length) {
            $description.text(
                copy.excerptDescription || 'Used as the short introduction on Library cards and pages, and as the preferred search description.'
            );
        }

        $status.append($count, $warning);
        $field.after($status);
        $field.attr('aria-describedby', $.trim([$field.attr('aria-describedby'), countId, warningId].filter(Boolean).join(' ')));
        $field.on('input', update);
        update();
    }

    function speakerProfile($option) {
        return {
            id: String($option.val() || ''),
            name: String($option.attr('data-speaker-name') || $.trim($option.text())),
            jobTitle: String($option.attr('data-speaker-job-title') || ''),
            organization: String($option.attr('data-speaker-organization') || ''),
            status: String($option.attr('data-speaker-status') || ''),
            statusLabel: String($option.attr('data-speaker-status-label') || ''),
            image: String($option.attr('data-speaker-image') || ''),
            editUrl: String($option.attr('data-speaker-edit-url') || ''),
        };
    }

    function speakerInitials(name) {
        var words = $.trim(name).split(/\s+/).filter(Boolean);

        if (!words.length) {
            return '?';
        }

        return (words[0].charAt(0) + (words.length > 1 ? words[words.length - 1].charAt(0) : '')).toUpperCase();
    }

    function appendSpeakerAvatar($target, profile) {
        var $avatar = $('<span>', {
            'class': 'tsol-library-speaker-picker__avatar',
            'aria-hidden': 'true',
        });

        if (profile.image) {
            $avatar.append($('<img>', { src: profile.image, alt: '' }));
        } else {
            $avatar.text(speakerInitials(profile.name));
        }
        $target.append($avatar);
    }

    function appendSpeakerIdentity($target, profile) {
        var $identity = $('<span>', { 'class': 'tsol-library-speaker-picker__identity' });

        $identity.append($('<strong>').text(profile.name));
        if (profile.jobTitle) {
            $identity.append($('<span>', { 'class': 'tsol-library-speaker-picker__job-title' }).text(profile.jobTitle));
        }
        if (profile.organization) {
            $identity.append($('<span>', { 'class': 'tsol-library-speaker-picker__organization' }).text(profile.organization));
        }
        if (profile.statusLabel) {
            $identity.append($('<span>', { 'class': 'tsol-library-speaker-picker__status' }).text(profile.statusLabel));
        }
        $target.append($identity);
    }

    function initSpeakerPicker($picker) {
        var $select = $picker.find('[data-speaker-native] select');
        var $enhanced = $picker.find('[data-speaker-enhanced]');
        var $modeInputs = $picker.find('[data-speaker-mode]');
        var $inheritMode = $picker.find('[data-speaker-inherit-mode]');
        var $inheritedPanel = $picker.find('[data-speaker-inherited-panel]');
        var $inheritedPreview = $picker.find('[data-speaker-inherited-preview]');
        var $inheritedRefresh = $picker.find('[data-speaker-inherited-refresh]');
        var $directPanel = $picker.find('[data-speaker-direct-panel]');
        var $nonePanel = $picker.find('[data-speaker-none-panel]');
        var $search = $enhanced.find('[data-speaker-search]');
        var $results = $enhanced.find('[data-speaker-results]');
        var $selected = $enhanced.find('[data-speaker-selected]');
        var $none = $enhanced.find('[data-speaker-none]');
        var $count = $enhanced.find('[data-speaker-count]');
        var $announcer = $enhanced.find('[data-speaker-announcer]');
        var activeIndex = -1;

        function selectedParentId() {
            var courseId = String($('#tsol-library-course-id').val() || '');
            var seriesId = String($('#tsol-library-series-id').val() || '');

            return courseId !== '0' && courseId ? courseId : (seriesId !== '0' ? seriesId : '');
        }

        function updateSpeakerModePanels() {
            if (!$modeInputs.length) {
                $directPanel.prop('hidden', false);
                return;
            }

            var parentId = selectedParentId();
            var savedParentId = String($picker.attr('data-speaker-saved-parent-id') || '');
            var hasExplicitMode = String($picker.attr('data-speaker-mode-explicit') || '') === '1';
            var mode = String($modeInputs.filter(':checked').val() || 'none');
            var hasParent = Boolean(parentId);
            var parentChanged = hasParent && parentId !== savedParentId;

            $inheritMode.prop('disabled', !hasParent);
            if (hasParent && !savedParentId && !hasExplicitMode && mode === 'none') {
                $inheritMode.prop('checked', true);
                mode = 'inherit';
            }
            if (!hasParent && mode === 'inherit') {
                $modeInputs.filter('[value="none"]').prop('checked', true);
                mode = 'none';
            }
            $inheritedPanel.prop('hidden', mode !== 'inherit');
            $directPanel.prop('hidden', mode !== 'direct');
            $nonePanel.prop('hidden', mode !== 'none');
            $inheritedPreview.prop('hidden', parentChanged);
            $inheritedRefresh.prop('hidden', !parentChanged);
        }

        $modeInputs.on('change', updateSpeakerModePanels);
        $('#tsol-library-course-id, #tsol-library-series-id').on('change', updateSpeakerModePanels);
        updateSpeakerModePanels();

        if (!$select.length || !$enhanced.length) {
            $picker.addClass('is-enhanced');
            return;
        }

        function optionById(id) {
            return $select.children('option').filter(function() {
                return String($(this).val()) === String(id);
            }).first();
        }

        function selectedIdsFromCards() {
            return $selected.children('[data-speaker-selected-item]').map(function() {
                return String($(this).attr('data-speaker-id'));
            }).get();
        }

        function orderNativeSelect(selectedIds) {
            var allOptions = $select.children('option').get();
            var optionsById = {};
            var selectedLookup = {};

            allOptions.forEach(function(option) {
                optionsById[String(option.value)] = option;
            });
            selectedIds.forEach(function(id) {
                selectedLookup[String(id)] = true;
                if (optionsById[String(id)]) {
                    $(optionsById[String(id)]).prop('selected', true).appendTo($select);
                }
            });
            allOptions
                .filter(function(option) {
                    return !selectedLookup[String(option.value)];
                })
                .sort(function(left, right) {
                    return speakerProfile($(left)).name.localeCompare(speakerProfile($(right)).name);
                })
                .forEach(function(option) {
                    $(option).prop('selected', false).appendTo($select);
                });
        }

        function announce(message) {
            $announcer.text('');
            window.setTimeout(function() {
                $announcer.text(message);
            }, 20);
        }

        function updateSelectedControls() {
            var $cards = $selected.children('[data-speaker-selected-item]');
            var hasMultipleSpeakers = $cards.length > 1;

            $picker.toggleClass('has-multiple-speakers', hasMultipleSpeakers);

            $cards.each(function(index) {
                $(this).find('[data-speaker-up]').prop('disabled', index === 0);
                $(this).find('[data-speaker-down]').prop('disabled', index === $cards.length - 1);
            });
        }

        function createSelectedCard(profile) {
            var copy = strings();
            var $card = $('<li>', {
                'class': 'tsol-library-speaker-picker__card',
                'data-speaker-selected-item': '',
                'data-speaker-id': profile.id,
            });
            var $handle = $('<span>', {
                'class': 'tsol-library-speaker-picker__drag',
                title: copy.speakerDrag || 'Drag to reorder',
                'aria-hidden': 'true',
                'data-speaker-drag': '',
            }).append($('<span>', { 'class': 'dashicons dashicons-menu' }));
            var $actions = $('<span>', { 'class': 'tsol-library-speaker-picker__actions' });

            $card.append($handle);
            appendSpeakerAvatar($card, profile);
            appendSpeakerIdentity($card, profile);

            if (profile.editUrl) {
                $actions.append($('<a>', {
                    href: profile.editUrl,
                    target: '_blank',
                    rel: 'noopener noreferrer',
                    text: copy.speakerEdit || 'Edit',
                    'aria-label': (copy.speakerEdit || 'Edit') + ' ' + profile.name + ' (opens in a new tab)',
                }));
            }
            $actions.append($('<button>', {
                type: 'button',
                'class': 'button-link',
                'data-speaker-up': '',
                'aria-label': (copy.speakerMoveUp || 'Move up') + ' ' + profile.name,
                title: copy.speakerMoveUp || 'Move up',
            }).append($('<span>', { 'class': 'dashicons dashicons-arrow-up-alt2', 'aria-hidden': 'true' })));
            $actions.append($('<button>', {
                type: 'button',
                'class': 'button-link',
                'data-speaker-down': '',
                'aria-label': (copy.speakerMoveDown || 'Move down') + ' ' + profile.name,
                title: copy.speakerMoveDown || 'Move down',
            }).append($('<span>', { 'class': 'dashicons dashicons-arrow-down-alt2', 'aria-hidden': 'true' })));
            $actions.append($('<button>', {
                type: 'button',
                'class': 'button-link button-link-delete',
                'data-speaker-remove': '',
                text: copy.speakerRemove || 'Remove',
                'aria-label': (copy.speakerRemove || 'Remove') + ' ' + profile.name,
            }));
            $card.append($actions);
            return $card;
        }

        function renderSelected(message) {
            var copy = strings();
            var $selectedOptions = $select.children('option:selected');
            var count = $selectedOptions.length;

            $selected.empty();
            $selectedOptions.each(function() {
                $selected.append(createSelectedCard(speakerProfile($(this))));
            });
            $none.prop('hidden', count > 0);
            $count.text(String(copy.speakerSelectedCount || '%d selected').replace('%d', String(count)));
            updateSelectedControls();
            if ($selected.data('ui-sortable')) {
                $selected.sortable('refresh');
            }
            if (message) {
                announce(message);
            }
        }

        function matchingProfiles() {
            var query = $.trim(String($search.val() || '')).toLowerCase();

            return $select.children('option:not(:selected)').map(function() {
                return speakerProfile($(this));
            }).get().filter(function(profile) {
                var haystack = [profile.name, profile.jobTitle, profile.organization].join(' ').toLowerCase();
                return !query || haystack.indexOf(query) !== -1;
            }).slice(0, 50);
        }

        function setActiveResult(index) {
            var $items = $results.children('[data-speaker-result]');

            if (!$items.length) {
                activeIndex = -1;
                $search.removeAttr('aria-activedescendant');
                return;
            }
            activeIndex = Math.max(0, Math.min(index, $items.length - 1));
            $items.removeClass('is-active');
            $items.eq(activeIndex).addClass('is-active');
            $search.attr('aria-activedescendant', $items.eq(activeIndex).attr('id'));
        }

        function renderResults() {
            var copy = strings();
            var profiles = matchingProfiles();

            activeIndex = -1;
            $search.removeAttr('aria-activedescendant');
            $results.empty();
            if (!profiles.length) {
                $results.append($('<div>', {
                    'class': 'tsol-library-speaker-picker__no-results',
                    role: 'status',
                    text: copy.speakerNoResults || 'No speakers match that search.',
                }));
            } else {
                profiles.forEach(function(profile, index) {
                    var $result = $('<div>', {
                        id: $results.attr('id') + '-option-' + profile.id,
                        'class': 'tsol-library-speaker-picker__result',
                        role: 'option',
                        'aria-selected': 'false',
                        'data-speaker-result': profile.id,
                    });

                    appendSpeakerAvatar($result, profile);
                    appendSpeakerIdentity($result, profile);
                    $result.append($('<span>', {
                        'class': 'tsol-library-speaker-picker__add-indicator',
                        title: copy.speakerAdd || 'Add speaker',
                        'aria-hidden': 'true',
                    }).append($('<span>', {
                        'class': 'dashicons dashicons-plus-alt2',
                    })));
                    $results.append($result);
                });
            }
            $results.prop('hidden', false);
            $search.attr('aria-expanded', 'true');
        }

        function hideResults() {
            activeIndex = -1;
            $results.prop('hidden', true);
            $search.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
        }

        function addSpeaker(id) {
            var copy = strings();
            var $option = optionById(id);
            var selectedIds = selectedIdsFromCards();

            if (!$option.length || $option.prop('selected')) {
                return;
            }
            selectedIds.push(String(id));
            orderNativeSelect(selectedIds);
            renderSelected(copy.speakerAdded || 'Speaker added.');
            $search.val('').trigger('focus');
            renderResults();
        }

        $search.on('focus input', renderResults);
        $search.on('keydown', function(event) {
            var $items = $results.children('[data-speaker-result]');

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if ($results.prop('hidden')) {
                    renderResults();
                    $items = $results.children('[data-speaker-result]');
                }
                if ($items.length) {
                    setActiveResult(event.key === 'ArrowDown' ? activeIndex + 1 : (activeIndex < 0 ? $items.length - 1 : activeIndex - 1));
                }
                return;
            }
            if (event.key === 'Enter' && activeIndex >= 0) {
                event.preventDefault();
                addSpeaker($items.eq(activeIndex).attr('data-speaker-result'));
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                hideResults();
            }
        });

        $results.on('mousedown', '[data-speaker-result]', function(event) {
            event.preventDefault();
        });
        $results.on('click', '[data-speaker-result]', function() {
            addSpeaker($(this).attr('data-speaker-result'));
        });
        $results.on('mousemove', '[data-speaker-result]', function() {
            setActiveResult($(this).index());
        });

        $selected.on('click', '[data-speaker-remove]', function() {
            var copy = strings();
            var $card = $(this).closest('[data-speaker-selected-item]');
            var id = String($card.attr('data-speaker-id'));
            var selectedIds = selectedIdsFromCards().filter(function(selectedId) {
                return selectedId !== id;
            });

            orderNativeSelect(selectedIds);
            renderSelected(copy.speakerRemoved || 'Speaker removed.');
            renderResults();
            $search.trigger('focus');
        });
        $selected.on('click', '[data-speaker-up], [data-speaker-down]', function() {
            var copy = strings();
            var $card = $(this).closest('[data-speaker-selected-item]');
            var movingUp = $(this).is('[data-speaker-up]');
            var $target = movingUp ? $card.prev() : $card.next();

            if (!$target.length) {
                return;
            }
            if (movingUp) {
                $card.insertBefore($target);
            } else {
                $card.insertAfter($target);
            }
            orderNativeSelect(selectedIdsFromCards());
            updateSelectedControls();
            announce(copy.speakerMoved || 'Speaker order updated.');
        });

        if ($.fn.sortable) {
            $selected.sortable({
                axis: 'y',
                containment: 'parent',
                handle: '[data-speaker-drag]',
                items: '> [data-speaker-selected-item]',
                tolerance: 'pointer',
                update: function() {
                    var copy = strings();
                    orderNativeSelect(selectedIdsFromCards());
                    updateSelectedControls();
                    announce(copy.speakerMoved || 'Speaker order updated.');
                },
            });
        }

        $select.on('change', function() {
            var selectedIds = $select.children('option:selected').map(function() {
                return String($(this).val());
            }).get();

            orderNativeSelect(selectedIds);
            renderSelected();
            renderResults();
        });

        $(document).on('click.tsolSpeakerPicker', function(event) {
            if (!$(event.target).closest($picker).length) {
                hideResults();
            }
        });

        $picker.addClass('is-enhanced');
        $select.attr({ 'aria-hidden': 'true', tabindex: '-1' });
        renderSelected();
    }

    $(document).ready(function() {
        arrangeCourseEditorialFlow();
        arrangeSeriesEditorialFlow();
        arrangeContentEditorialFlow();
        initExcerptGuidance();
        $('[data-library-media-editor]').each(function() {
            var $editor = $(this);
            initAvailabilityEditor($editor);
            initMediaEditor($editor);
        });
        $('[data-library-resource-editor]').each(function() {
            initResourceEditor($(this));
        });
        $('[data-library-course-page-editor]').each(function() {
            initCoursePageEditor($(this));
        });
        $('[data-speaker-picker]').each(function() {
            initSpeakerPicker($(this));
        });

        $('#post').on('submit', function(event) {
            var $firstError = $('[data-media-row].has-media-error').first();

            if (!$firstError.length) {
                return;
            }

            event.preventDefault();
            $firstError.get(0).scrollIntoView({ behavior: 'smooth', block: 'center' });
            $firstError.find('[data-media-url]').trigger('focus');
        });
    });
})(jQuery);
