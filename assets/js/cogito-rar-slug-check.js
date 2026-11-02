(function () {
    'use strict';

    if (typeof rarSlugCheck === 'undefined') {
        return;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var field = document.querySelector('[name="rar_slug"]');
        if (!field) {
            return;
        }

        var note = document.createElement('span');
        note.className = 'rar-slug-note';
        note.style.display = 'block';
        note.style.marginTop = '4px';
        field.parentNode.appendChild(note);

        var original = field.value.trim();
        var timer = null;
        var inFlight = null;

        function setNote(message, state) {
            note.textContent = message;
            note.style.color = state === 'error' ? '#b32d2e' : (state === 'ok' ? '#1e7e34' : '#646970');
        }

        function run() {
            var slug = field.value.trim();

            if (!slug || slug === original) {
                setNote('', '');
                return;
            }

            if (inFlight) {
                inFlight.abort();
            }

            var controller = new AbortController();
            inFlight = controller;

            var body = new URLSearchParams();
            body.append('action', rarSlugCheck.action);
            body.append('nonce', rarSlugCheck.nonce);
            body.append('slug', slug);
            body.append('exclude_id', rarSlugCheck.excludeId);

            setNote('Checking…', '');

            fetch(rarSlugCheck.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    inFlight = null;

                    if (!payload || !payload.success) {
                        setNote('', '');
                        return;
                    }

                    var data = payload.data;

                    if (!data.available) {
                        if (data.conflict) {
                            setNote('Already used by "' + data.conflict.title + '" (' + data.conflict.status + '). Choose another.', 'error');
                        } else {
                            setNote('That slug cannot be used. Choose another.', 'error');
                        }
                        return;
                    }

                    if (data.content_conflict) {
                        setNote(
                            'Available, but a ' + data.content_conflict.post_type + ' already uses this slug ("'
                            + data.content_conflict.title + '"). Fine for /go/ links.',
                            ''
                        );
                        return;
                    }

                    setNote('Available.', 'ok');
                })
                .catch(function (error) {
                    if (error.name !== 'AbortError') {
                        inFlight = null;
                        setNote('', '');
                    }
                });
        }

        field.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(run, 400);
        });
    });
})();
