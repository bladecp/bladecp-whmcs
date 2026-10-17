(function () {
    'use strict';
    function chooseLocation(locations, previous) {
        return locations.find(location => String(location.id) === String(previous)) || locations[0] || null;
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = { chooseLocation };
    if (typeof document === 'undefined') return;
    document.querySelectorAll('[data-deploy-picker]').forEach(form => {
        const source = form.querySelector('[data-deploy-products]');
        const products = JSON.parse(source.textContent);
        const host = form.querySelector('[data-deploy-locations]');
        if (!host) return;
        host.addEventListener('change', () => {
            const selected = host.querySelector('input:checked');
            const summary = form.closest('.bcp-deploy-layout').querySelector('[data-deploy-summary-location]');
            if (summary && selected) summary.textContent = selected.closest('label').querySelector('span').textContent;
        });
        form.querySelectorAll('[name="product_id"]').forEach(input => input.addEventListener('change', () => {
            const product = products.find(item => String(item.id) === input.value);
            if (!product || !input.checked) return;
            const previous = form.querySelector('[name="location_id"]:checked')?.value;
            const locations = Object.values(product.locations);
            const selected = chooseLocation(locations, previous);
            form.querySelector('[data-deploy-name]').textContent = product.name;
            const badge = form.querySelector('[data-deploy-policy]');
            badge.textContent = product.policyLabel;
            badge.classList.toggle('bcp-deploy-policy-trial', product.policy.mode === 'trial');
            form.querySelector('[data-deploy-price-label]').textContent = product.priceLabel;
            ['cpu', 'ram', 'disk', 'price'].forEach(field => {
                form.querySelector('[data-deploy-value="' + field + '"]').textContent = field === 'price' ? product.price : product.specs[field];
            });
            form.querySelector('[data-deploy-setup]').textContent = product.setup;
            const icon = host.querySelector('svg')?.cloneNode(true);
            host.replaceChildren();
            locations.forEach(location => {
                const label = document.createElement('label');
                label.className = 'bcp-deploy-location-option';
                const radio = document.createElement('input');
                radio.type = 'radio'; radio.name = 'location_id'; radio.value = String(location.id); radio.required = true;
                radio.checked = location.id === selected?.id;
                const text = document.createElement('span'); text.textContent = location.name;
                label.append(radio);
                if (icon) label.append(icon.cloneNode(true));
                label.append(text); host.append(label);
            });
            const summary = form.closest('.bcp-deploy-layout');
            const summaryPlan = summary.querySelector('[data-deploy-summary-plan]');
            const summaryLocation = summary.querySelector('[data-deploy-summary-location]');
            if (summaryPlan) summaryPlan.textContent = product.name;
            if (summaryLocation) summaryLocation.textContent = selected?.name || '';

        }));
    });
})();
