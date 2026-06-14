document.addEventListener('DOMContentLoaded', function () {

    // --- Flag-as-bot Logic (clicks list table) ---
    // Handles the row action panel: open/close, and the AJAX flag request.
    const wrapper = document.querySelector('.rarlinks-table-wrapper');

    if ( ! wrapper ) return;

    // One delegated listener covers every row, including future pagination loads
    wrapper.addEventListener('click', function ( e ) {

        // Open / close the signal panel from the row action link
        const toggle = e.target.closest('.rar-flag-bot-toggle');
        if ( toggle ) {
            e.preventDefault();
            const panel = toggle.closest('td').querySelector('.rar-flag-bot-panel');
            if ( panel ) panel.hidden = ! panel.hidden;
            return;
        }

        // Cancel / Close. After a successful flag the panel is in its success
        // state and the row no longer belongs on this human-only table, so
        // Close removes the row; otherwise it just hides the panel.
        const cancel = e.target.closest('.rar-flag-bot-cancel');
        if ( cancel ) {
            const cancelPanel = cancel.closest('.rar-flag-bot-panel');
            if ( cancelPanel.classList.contains('rar-flag-bot-success') ) {
                const doneRow = cancelPanel.closest('tr');
                if ( doneRow && doneRow.parentNode ) doneRow.parentNode.removeChild(doneRow);
            } else {
                cancelPanel.hidden = true;
            }
            return;
        }

        // Mark as unknown: reclassify immediately (bot_or_not = 2) and remove
        // the row — it moves to Bot Cleanup.
        const unknownLink = e.target.closest('.rar-row-unknown');
        if ( unknownLink ) {
            e.preventDefault();

            const ubody = new URLSearchParams();
            ubody.append('action', 'rar_mark_unknown');
            ubody.append('nonce', unknownLink.getAttribute('data-nonce'));
            ubody.append('click_id', unknownLink.getAttribute('data-click-id'));

            unknownLink.style.pointerEvents = 'none';

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: ubody.toString()
            })
            .then(function ( response ) { return response.json(); })
            .then(function ( result ) {
                if ( ! result.success ) {
                    alert('Could not reclassify: ' + ( result.data && result.data.message ? result.data.message : 'Unknown error' ));
                    unknownLink.style.pointerEvents = '';
                    return;
                }
                const row = unknownLink.closest('tr');
                if ( row && row.parentNode ) row.parentNode.removeChild(row);
            })
            .catch(function () {
                alert('Request failed. Please try again.');
                unknownLink.style.pointerEvents = '';
            });
            return;
        }

        // Confirm: flag the row, sending only the TICKED signal types
        const confirmBtn = e.target.closest('.rar-flag-bot-confirm');
        if ( ! confirmBtn ) return;

        const panel = confirmBtn.closest('.rar-flag-bot-panel');

        // Build the AJAX request body
        const body = new URLSearchParams();
        body.append('action', 'rar_flag_bot');
        body.append('nonce', panel.getAttribute('data-nonce'));
        body.append('click_id', panel.getAttribute('data-click-id'));
        panel.querySelectorAll('input[type="checkbox"]:checked').forEach(function ( box ) {
            body.append('signals[]', box.value);
        });

        confirmBtn.disabled = true; // Prevent double submission

        // WordPress exposes the admin-ajax URL globally as ajaxurl on admin pages
        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function ( response ) { return response.json(); })
        .then(function ( result ) {
            if ( ! result.success ) {
                alert('Could not flag: ' + ( result.data && result.data.message ? result.data.message : 'Unknown error' ));
                confirmBtn.disabled = false;
                return;
            }

            // Success — the click is no longer human, so it leaves this table.
            // Show what (if anything) was added to the live list; the Close
            // button then removes the row (handled by the cancel branch above).
            const labels = { ip: 'IP', hostname: 'Hostname', org: 'Org', ua: 'User agent' };
            const added  = ( result.data && result.data.added ) ? result.data.added : [];

            let message = 'Click flagged as bot and moved to Bot Cleanup.';
            if ( added.length ) {
                message += ' Added to live bot list: ' + added.map(function ( type ) {
                    return labels[type] || type;
                }).join(', ') + '.';
            }

            panel.classList.add('rar-flag-bot-success');
            panel.innerHTML = '<p class="rar-flag-bot-intro">✓ ' + message + '</p>' +
                '<div class="rar-flag-bot-actions">' +
                '<button type="button" class="button button-primary rar-flag-bot-cancel">Close</button>' +
                '</div>';
        })
        .catch(function () {
            alert('Request failed. Please try again.');
            confirmBtn.disabled = false;
        });
    });
});
