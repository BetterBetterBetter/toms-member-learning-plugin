(function () {
    'use strict';

    var dialog = document.getElementById('tsol-content-access-dialog');
    if (!dialog) {
        return;
    }

    var title = dialog.querySelector('#tsol-content-access-dialog-title');
    var details = dialog.querySelector('[data-tsol-content-access-details]');
    var closeButton = dialog.querySelector('[data-tsol-content-access-close]');
    var opener = null;

    function closeDialog() {
        if (typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
        if (opener) {
            opener.focus();
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-tsol-content-access]');
        if (!button) {
            return;
        }

        var template = document.getElementById(button.getAttribute('data-template-id'));
        if (!template || !details) {
            return;
        }

        opener = button;
        details.replaceChildren(template.content.cloneNode(true));
        if (title) {
            title.textContent = dialog.getAttribute('data-title-prefix') + ' ' + button.getAttribute('data-content-title');
        }

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', 'open');
        }
        if (closeButton) {
            closeButton.focus();
        }
    });

    if (closeButton) {
        closeButton.addEventListener('click', closeDialog);
    }

    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            closeDialog();
        }
    });

    dialog.addEventListener('close', function () {
        if (opener) {
            opener.focus();
        }
    });
}());
