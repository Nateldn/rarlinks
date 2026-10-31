(function () {
    'use strict';

    if (typeof rarAffiliate === 'undefined') {
        return;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var target = document.getElementById('rar_target');
        if (!target) {
            return;
        }

        var wrap = document.createElement('p');
        wrap.className = 'rar-affiliate-wrap';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button';
        button.id = 'rar-affiliate-generate';
        button.textContent = 'Generate affiliate link';

        var network = document.createElement('select');
        network.id = 'rar-affiliate-network';
        network.style.marginLeft = '8px';

        var auto = document.createElement('option');
        auto.value = '';
        auto.textContent = rarAffiliate.networks.length > 1 ? 'Detect network' : 'Detect';
        network.appendChild(auto);

        rarAffiliate.networks.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.label;
            network.appendChild(option);
        });

        var advertiser = document.createElement('input');
        advertiser.type = 'text';
        advertiser.id = 'rar-affiliate-advertiser';
        advertiser.placeholder = 'Merchant ID (auto)';
        advertiser.title = 'The advertiser/merchant ID. Detected from the URL — only set this to override.';
        advertiser.style.width = '150px';
        advertiser.style.marginLeft = '8px';

        var subId = document.createElement('input');
        subId.type = 'text';
        subId.id = 'rar-affiliate-sub-id';
        subId.placeholder = rarAffiliate.subIdLabel || 'Sub ID';
        subId.title = 'Sub ID passed to the network for your own reporting.';
        subId.value = rarAffiliate.defaultSubId || '';
        subId.style.width = '140px';
        subId.style.marginLeft = '8px';

        var status = document.createElement('span');
        status.className = 'rar-affiliate-status';
        status.style.marginLeft = '10px';

        wrap.appendChild(button);
        wrap.appendChild(network);
        wrap.appendChild(advertiser);
        wrap.appendChild(subId);
        wrap.appendChild(status);
        target.parentNode.parentNode.insertBefore(wrap, target.parentNode.nextSibling);

        function setStatus(message, state) {
            status.textContent = message;
            status.style.color = state === 'error' ? '#b32d2e' : (state === 'ok' ? '#1e7e34' : '');
        }

        function fieldByName(name) {
            return document.querySelector('[name="' + name + '"]');
        }

        button.addEventListener('click', function () {
            var url = target.value.trim();

            if (!url) {
                setStatus('Paste the merchant URL into Target URL first.', 'error');
                return;
            }

            var titleField = document.getElementById('title');
            var body = new URLSearchParams();
            body.append('action', rarAffiliate.action);
            body.append('nonce', rarAffiliate.nonce);
            body.append('url', url);
            body.append('network', network.value);
            body.append('advertiser_id', advertiser.value.trim());
            body.append('sub_id', subId.value.trim());
            body.append('name', titleField ? titleField.value : '');

            button.disabled = true;
            setStatus('Generating…', '');

            fetch(rarAffiliate.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        throw new Error('Server returned ' + response.status);
                    });
                })
                .then(function (payload) {
                    button.disabled = false;

                    if (!payload || !payload.success) {
                        setStatus((payload && payload.data && payload.data.message) || 'Link generation failed.', 'error');
                        return;
                    }

                    target.value = payload.data.deep_link;

                    var networkField = fieldByName('rar_network');
                    if (networkField) {
                        networkField.value = payload.data.network;
                    }

                    var brand = fieldByName('rar_brand');
                    if (brand && !brand.value.trim() && payload.data.advertiser_name) {
                        brand.value = payload.data.advertiser_name;
                    }

                    var who = payload.data.advertiser_name || payload.data.advertiser_id;
                    var how = payload.data.confidence === 'name'
                        ? ' (matched by brand name, different domain)'
                        : (payload.data.confidence === 'manual' ? ' (merchant you supplied)' : '');

                    var sub = payload.data.sub_id ? ', sub ID ' + payload.data.sub_id : '';

                    setStatus(
                        payload.data.network + ': ' + who + ' (merchant ID ' + payload.data.advertiser_id + ')'
                        + how + sub + '. Save to keep it.',
                        'ok'
                    );
                })
                .catch(function (error) {
                    button.disabled = false;
                    setStatus(error.message || 'Could not reach the server.', 'error');
                });
        });
    });
})();
