(function () {
    'use strict';
    document.querySelectorAll('[data-bcp-commercial-form]').forEach(function (form) {
        var mode = form.querySelector('[data-bcp-policy-mode]');
        var expiry = form.querySelector('[data-bcp-policy-expiry]');
        if (!mode || !expiry) return;
        function update() {
            form.querySelectorAll('[data-bcp-policy-show]').forEach(function (field) {
                field.hidden = field.dataset.bcpPolicyShow.split(' ').indexOf(mode.value) === -1;
            });
            form.querySelectorAll('[data-bcp-expiry-show]').forEach(function (field) {
                field.hidden = field.dataset.bcpExpiryShow !== expiry.value;
            });
            form.querySelectorAll('[data-bcp-policy-show] input, [data-bcp-policy-show] select').forEach(function (control) {
                control.disabled = Boolean(control.closest('[hidden]'));
            });
        }
        mode.addEventListener('change', update);
        expiry.addEventListener('change', update);
        update();
    });
})();
