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

        // Cancel simply hides the panel, leaving checkboxes as they were
        const cancel = e.target.closest('.rar-flag-bot-cancel');
        if ( cancel ) {
            cancel.closest('.rar-flag-bot-panel').hidden = true;
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

            const row = panel.closest('tr');

            // Swap the Type column icon to the bot icon
            const icon = row.querySelector('.column-type .dashicons');
            if ( icon ) {
                icon.className = 'dashicons dashicons-welcome-view-site';
                icon.setAttribute('title', 'Known Bot');
            }

            // Update the Bot Name cell unless detection had already named it
            const nameCell = row.querySelector('.column-bot_name');
            if ( nameCell && nameCell.textContent.trim() === 'n/a' ) {
                nameCell.textContent = 'Manually flagged';
            }

            // Replace the row action with a static confirmation
            const action = row.querySelector('.rar-flag-bot');
            if ( action ) {
                action.innerHTML = '<span class="rar-flag-bot-flagged">Flagged as bot</span>';
            }

            // Show a success message IN the panel rather than hiding it —
            // collapsing the panel here would shift the rows below mid-read.
            // The Close button reuses the cancel class, so the delegated
            // cancel handler above dismisses it when the user is ready.
            const labels = { ip: 'IP', hostname: 'Hostname', org: 'Org', ua: 'User agent' };
            const added  = ( result.data && result.data.added ) ? result.data.added : [];

            let message = 'Click flagged as bot.';
            if ( added.length ) {
                message += ' Added to live bot list: ' + added.map(function ( type ) {
                    return labels[type] || type;
                }).join(', ') + '.';
            }

            panel.classList.add('rar-flag-bot-success');
            panel.innerHTML = '<p class="rar-flag-bot-intro">✓ ' + message + '</p>' +
                '<div class="rar-flag-bot-actions">' +
                '<button type="button" class="button rar-flag-bot-cancel">Close</button>' +
                '</div>';
        })
        .catch(function () {
            alert('Request failed. Please try again.');
            confirmBtn.disabled = false;
        });
    });
});
