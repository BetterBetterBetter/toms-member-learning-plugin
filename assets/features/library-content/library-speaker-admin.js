/* Member Library Speaker profile editor */

(function($, wp, settings) {
    'use strict';

    var headshotFrame = null;

    function cropOptions(attachment, controller) {
        var width = Number(attachment.get('width')) || 0;
        var height = Number(attachment.get('height')) || 0;
        var edge = Math.min(width, height);
        var x1 = Math.max(0, (width - edge) / 2);
        var y1 = Math.max(0, (height - edge) / 2);

        controller.set('canSkipCrop', false);
        controller.set('hasRequiredAspectRatio', false);

        return {
            aspectRatio: '1:1',
            handles: true,
            imageHeight: height,
            imageWidth: width,
            instance: true,
            keys: true,
            minHeight: Math.min(edge, 80),
            minWidth: Math.min(edge, 80),
            persistent: true,
            x1: x1,
            x2: x1 + edge,
            y1: y1,
            y2: y1 + edge
        };
    }

    function createHeadshotFrame() {
        var cropSize = Math.max(1, Number(settings.cropSize) || 640);
        var strings = settings.strings || {};
        var HeadshotCropper = wp.media.controller.Cropper.extend({
            doCrop: function(attachment) {
                var cropDetails = $.extend({}, attachment.get('cropDetails'));
                var edge = Math.min(cropSize, cropDetails.width, cropDetails.height);

                cropDetails.dst_width = edge;
                cropDetails.dst_height = edge;

                return wp.ajax.post('crop-image', {
                    context: 'tsol-library-speaker-headshot',
                    cropDetails: cropDetails,
                    id: attachment.get('id'),
                    nonce: attachment.get('nonces').edit
                });
            }
        });

        var frame = wp.media({
            button: {
                close: false,
                text: strings.selectAndCrop || 'Select and crop'
            },
            states: [
                new wp.media.controller.Library({
                    date: false,
                    library: wp.media.query({ type: 'image' }),
                    multiple: false,
                    priority: 20,
                    suggestedHeight: cropSize,
                    suggestedWidth: cropSize,
                    title: strings.frameTitle || 'Select and crop headshot'
                }),
                new HeadshotCropper({
                    imgSelectOptions: cropOptions
                })
            ]
        });

        frame.on('select', function() {
            frame.setState('cropper');
        });
        frame.on('cropped', function(croppedImage) {
            wp.media.featuredImage.set(croppedImage.id);
        });

        return frame;
    }

    function enableHeadshotCropper() {
        if (!wp || !wp.media || !wp.media.featuredImage || !wp.media.controller.Cropper) {
            return;
        }

        wp.media.featuredImage.frame = function() {
            if (!headshotFrame) {
                headshotFrame = createHeadshotFrame();
            }
            wp.media.frame = headshotFrame;
            return headshotFrame;
        };
    }

    function reindexRows($rows) {
        $rows.children('[data-speaker-social-row]').each(function(index) {
            $(this).find('[name]').each(function() {
                this.name = this.name.replace(
                    /(tsol_speaker_profile\[social_links\]\[)[^\]]+(\])/,
                    '$1' + index + '$2'
                );
            });
        });
    }

    function arrangeEditorialFlow() {
        var $details = $('#tsol-library-speaker-details');
        var $aboutHeading = $('.tsol-speaker-about-heading').first();
        var $bodyEditor = $('#postdivrich');

        if (!$details.length || !$aboutHeading.length || !$bodyEditor.length) {
            return;
        }

        $details.insertBefore($aboutHeading);
        $details.attr('data-speaker-details-section', '');
        $aboutHeading.add($bodyEditor).wrapAll($('<div>', {
            'class': 'tsol-speaker-about-section',
            'data-speaker-about-section': '',
        }));
    }

    function initShortBioGuidance() {
        var $field = $('#tsol-library-speaker-details #excerpt');

        if (!$field.length) {
            return;
        }

        var strings = settings.strings || {};
        var recommendedLength = 160;
        var countId = 'tsol-library-speaker-short-bio-count';
        var warningId = 'tsol-library-speaker-short-bio-warning';
        var $status = $('<span>', {
            'class': 'tsol-speaker-short-bio-status',
            'data-speaker-short-bio-status': '',
        });
        var $count = $('<span>', {
            id: countId,
            'class': 'tsol-speaker-short-bio-status__count',
            'data-speaker-short-bio-count': '',
        });
        var $warning = $('<span>', {
            id: warningId,
            'class': 'tsol-speaker-short-bio-status__warning',
            'data-speaker-short-bio-warning': '',
            role: 'status',
            hidden: true,
            text: strings.shortBioLongWarning || 'Longer bios may be shortened in compact Library displays.',
        });

        function normalizedLength() {
            var value = $.trim(String($field.val() || '')).replace(/\s+/g, ' ');

            return Array.from(value).length;
        }

        function countLabel(length) {
            return String(strings.shortBioCountTemplate || '%1$d / %2$d recommended')
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

        $status.append($count, $warning);
        $field.after($status);
        $field.attr('aria-describedby', $.trim([$field.attr('aria-describedby'), countId, warningId].filter(Boolean).join(' ')));
        $field.on('input', update);
        update();
    }

    $(document).ready(function() {
        enableHeadshotCropper();
        arrangeEditorialFlow();
        initShortBioGuidance();

        $('[data-speaker-social-editor]').each(function() {
            var $editor = $(this);
            var $rows = $editor.find('[data-speaker-social-rows]');
            var template = $editor.find('[data-speaker-social-template]').html();

            $editor.on('click', '[data-speaker-social-add]', function() {
                var index = $rows.children('[data-speaker-social-row]').length;
                var $row = $(String(template || '').replace(/__index__/g, String(index)));
                $rows.append($row);
                reindexRows($rows);
                $row.find('[data-speaker-social-platform]').trigger('focus');
            });

            $editor.on('click', '[data-speaker-social-remove]', function() {
                var $row = $(this).closest('[data-speaker-social-row]');
                if ($rows.children('[data-speaker-social-row]').length === 1) {
                    $row.find('[data-speaker-social-platform]').val('linkedin');
                    $row.find('[data-speaker-social-url]').val('');
                } else {
                    $row.remove();
                }
                reindexRows($rows);
            });

            reindexRows($rows);
        });
    });
})(jQuery, window.wp, window.tsolLibrarySpeakerAdmin || {});
