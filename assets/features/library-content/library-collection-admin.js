(function ($) {
    'use strict';

    $(function () {
        $('[data-collection-image]').each(function () {
            var $control = $(this);
            var $input = $control.find('[data-collection-image-id]');
            var $preview = $control.find('[data-collection-image-preview]');
            var $remove = $control.find('[data-collection-image-remove]');
            var frame = null;

            $control.find('[data-collection-image-choose]').on('click', function () {
                if (frame) {
                    frame.open();
                    return;
                }
                frame = wp.media({
                    title: tsolLibraryCollectionAdmin.frameTitle,
                    button: { text: tsolLibraryCollectionAdmin.useImage },
                    library: { type: 'image' },
                    multiple: false
                });
                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    var source = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                    $input.val(attachment.id);
                    $preview.html($('<img>', { src: source, alt: '' }));
                    $remove.removeClass('hidden');
                });
                frame.open();
            });

            $remove.on('click', function () {
                $input.val('');
                $preview.empty();
                $remove.addClass('hidden');
            });
        });

        $('[data-collection-colors]').each(function () {
            var $control = $(this);
            var $enabled = $control.find('[data-collection-colors-enabled]');
            var $fields = $control.find('[data-collection-colors-fields]');

            function luminance(hex) {
                var value = String(hex).replace('#', '');
                var channels = [0, 2, 4].map(function (offset) {
                    var channel = parseInt(value.slice(offset, offset + 2), 16) / 255;
                    return channel <= 0.04045 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4);
                });
                return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
            }

            function update() {
                var enabled = $enabled.is(':checked');
                $fields.toggle(enabled).find('input').prop('disabled', !enabled);
                $control.find('[data-collection-color-pair]').each(function () {
                    var $pair = $(this);
                    var $background = $pair.find('[data-collection-color="background"]');
                    var $foreground = $pair.find('[data-collection-color="foreground"]');
                    var backgroundLuminance = luminance($background.val());
                    var foregroundLuminance = luminance($foreground.val());
                    var ratio = (Math.max(backgroundLuminance, foregroundLuminance) + 0.05) / (Math.min(backgroundLuminance, foregroundLuminance) + 0.05);
                    var passes = ratio >= 4.5;
                    $pair.find('[data-collection-contrast]')
                        .text(ratio.toFixed(2) + ':1 — ' + (passes ? tsolLibraryCollectionAdmin.contrastPass : tsolLibraryCollectionAdmin.contrastFail))
                        .css({ backgroundColor: $background.val(), color: $foreground.val() });
                    $background.get(0).setCustomValidity(enabled && !passes ? tsolLibraryCollectionAdmin.contrastError : '');
                });
            }

            $enabled.on('change', update);
            $control.on('input change', '[data-collection-color]', update);
            update();
        });
    });
})(jQuery);
