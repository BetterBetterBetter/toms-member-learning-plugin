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
    });
})(jQuery);
