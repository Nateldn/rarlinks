document.addEventListener('DOMContentLoaded', function () {

    // --- Moto Partner List Logic ---
    // Handles AJAX disabling of individual partners on the Reports settings tab.
    const motoPanel = document.querySelector('.rar-moto-panel');

    if ( motoPanel ) {
        const countSpan = motoPanel.querySelector('.rar-moto-count');
        const nonce     = motoPanel.getAttribute('data-nonce');
        const list      = motoPanel.querySelector('.rar-moto-list');

        if ( list ) {
            // Disable a partner via AJAX (event delegation on the list)
            list.addEventListener('click', function ( e ) {
                if ( ! e.target.classList.contains('rar-moto-disable') ) return;

                const listItem = e.target.closest('li');
                const postId   = listItem ? listItem.getAttribute('data-post-id') : null;
                if ( ! postId ) return;

                // Build the AJAX request body
                const body = new URLSearchParams();
                body.append('action', 'rar_disable_moto_partner');
                body.append('nonce', nonce);
                body.append('post_id', postId);

                // WordPress exposes the admin-ajax URL globally as ajaxurl on admin pages
                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function ( response ) { return response.json(); })
                .then(function ( result ) {
                    if ( result.success ) {
                        listItem.remove(); // Remove the disabled item from the list
                        if ( countSpan ) {
                            countSpan.textContent = result.data.remaining; // Update the count
                        }
                    } else {
                        alert('Could not disable: ' + ( result.data && result.data.message ? result.data.message : 'Unknown error' ));
                    }
                })
                .catch(function () {
                    alert('Request failed. Please try again.');
                });
            });
        }
    }

    // --- Bot Cleanup Logic ---
    // Verify-before-delete: block useless submissions and require explicit
    // confirmation before the permanent bulk delete goes through.
    const cleanupForm = document.querySelector('.rar-bot-cleanup-form');

    if ( cleanupForm ) {
        cleanupForm.addEventListener('submit', function ( e ) {
            const topAction    = cleanupForm.querySelector('select[name="action"]');
            const bottomAction = cleanupForm.querySelector('select[name="action2"]');
            const isDelete     = ( topAction && topAction.value === 'delete' ) ||
                                 ( bottomAction && bottomAction.value === 'delete' );

            const checked = cleanupForm.querySelectorAll('input[name="bulk-select[]"]:checked').length;

            if ( ! isDelete ) {
                e.preventDefault();
                alert('Choose "Delete" from the Bulk actions menu first.');
                return;
            }

            if ( checked === 0 ) {
                e.preventDefault();
                alert('Tick at least one row to delete.');
                return;
            }

            const noun = checked === 1 ? 'row' : 'rows';
            if ( ! confirm('Permanently delete ' + checked + ' selected ' + noun + '? This cannot be undone.') ) {
                e.preventDefault();
            }
        });
    }
});
