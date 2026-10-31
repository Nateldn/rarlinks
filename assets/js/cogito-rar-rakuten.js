(function () {
    'use strict';

    if (typeof rarRakuten === 'undefined') {
        return;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var target = document.getElementById('rar_target');
        if (!target) {
            return;
        }

        var wrap = document.createElement('p');
        wrap.className = 'rar-rakuten-wrap';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button';
        button.id = 'rar-rakuten-generate';
        button.textContent = 'Generate Rakuten link';

        var advertiser = document.createElement('input');
        advertiser.type = 'text';
        advertiser.id = 'rar-rakuten-advertiser';
        advertiser.placeholder = 'Advertiser ID (optional)';
        advertiser.style.width = '180px';
        advertiser.style.marginLeft = '8px';

        var status = document.createElement('span');
        status.className = 'rar-rakuten-status';
        status.style.marginLeft = '10px';

        wrap.appendChild(button);
        wrap.appendChild(advertiser);
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
            body.append('action', rarRakuten.action);
            body.append('nonce', rarRakuten.nonce);
            body.append('url', url);
            body.append('name', titleField ? titleField.value : '');
            body.append('advertiser_id', advertiser.value.trim());

            button.disabled = true;
            setStatus('Generating…', '');

            fetch(rarRakuten.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (payload) {
                    button.disabled = false;

                    if (!payload || !payload.success) {
                        setStatus((payload && payload.data && payload.data.message) || 'Link generation failed.', 'error');
                        return;
                    }

                    target.value = payload.data.deep_link;

                    var network = fieldByName('rar_network');
                    if (network) {
                        network.value = rarRakuten.network;
                    }

                    var brand = fieldByName('rar_brand');
                    if (brand && !brand.value.trim() && payload.data.advertiser_name) {
                        brand.value = payload.data.advertiser_name;
                    }

                    var who = payload.data.advertiser_name || payload.data.advertiser_id;
                    var how = payload.data.confidence === 'name'
                        ? ' (matched by brand name, different domain)'
                        : (payload.data.confidence === 'manual' ? ' (advertiser you supplied)' : '');

                    setStatus('Matched ' + who + how + '. Save to keep it.', 'ok');
                })
                .catch(function () {
                    button.disabled = false;
                    setStatus('Could not reach the server.', 'error');
                });
        });
    });
})();
