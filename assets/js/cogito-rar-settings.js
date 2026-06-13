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
    // Verify-before-delete plus a "select all across pages" affordance.
    const cleanupForm = document.querySelector('.rar-bot-cleanup-form');

    if ( cleanupForm ) {
        const banner    = cleanupForm.querySelector('.rar-select-all-banner');
        const flagInput = cleanupForm.querySelector('.rar-select-all-flag');
        const selectAllLink = cleanupForm.querySelector('.rar-select-all-link');
        const total     = banner ? parseInt(banner.getAttribute('data-total'), 10) : 0;

        function checkedCount() {
            return cleanupForm.querySelectorAll('input[name="bulk-select[]"]:checked').length;
        }
        function rowCount() {
            return cleanupForm.querySelectorAll('input[name="bulk-select[]"]').length;
        }

        // Reset the cross-page flag back to per-page selection
        function clearSelectAll() {
            if ( flagInput ) flagInput.value = '0';
            if ( selectAllLink ) selectAllLink.hidden = false;
        }

        // Show the banner only when the whole page is ticked and more rows
        // exist on other pages
        function refreshBanner() {
            if ( ! banner ) return;
            const rows = rowCount();
            const sel  = checkedCount();
            if ( rows > 0 && sel === rows && total > rows ) {
                banner.hidden = false;
                if ( ! flagInput || flagInput.value !== '1' ) {
                    banner.querySelector('.rar-select-all-msg').textContent =
                        'All ' + sel + ' on this page selected. ';
                }
            } else {
                banner.hidden = true;
                clearSelectAll();
            }
        }

        cleanupForm.addEventListener('change', function ( e ) {
            if ( e.target.matches('input[name="bulk-select[]"], #cb-select-all-1, #cb-select-all-2') ) {
                refreshBanner();
            }
        });

        if ( selectAllLink ) {
            selectAllLink.addEventListener('click', function ( e ) {
                e.preventDefault();
                if ( flagInput ) flagInput.value = '1';
                banner.querySelector('.rar-select-all-msg').textContent =
                    'All ' + total + ' across all pages selected. ';
                selectAllLink.hidden = true;
            });
        }

        // Per-row Delete link: confirm before following
        cleanupForm.addEventListener('click', function ( e ) {
            const del = e.target.closest('.rar-row-delete');
            if ( del && ! confirm('Permanently delete this click? This cannot be undone.') ) {
                e.preventDefault();
            }
        });

        // Bulk submit guard
        cleanupForm.addEventListener('submit', function ( e ) {
            const topAction    = cleanupForm.querySelector('select[name="action"]');
            const bottomAction = cleanupForm.querySelector('select[name="action2"]');
            let   action       = topAction && topAction.value !== '-1' ? topAction.value : '';
            if ( ! action && bottomAction && bottomAction.value !== '-1' ) {
                action = bottomAction.value;
            }

            const allPages = flagInput && flagInput.value === '1';
            const checked  = checkedCount();

            if ( action !== 'delete' && action !== 'mark_human' ) {
                e.preventDefault();
                alert('Choose an action from the Bulk actions menu first.');
                return;
            }
            if ( checked === 0 ) {
                e.preventDefault();
                alert('Tick at least one row first.');
                return;
            }

            // Only deletion is irreversible — marking human needs no confirm
            const count = allPages ? total : checked;
            const noun  = count === 1 ? 'row' : 'rows';
            if ( action === 'delete' && ! confirm('Permanently delete ' + count + ' ' + noun + '? This cannot be undone.') ) {
                e.preventDefault();
            }
        });
    }
});
