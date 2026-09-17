document.addEventListener('DOMContentLoaded', function () {

    // --- Conversions: self-service tracked-events repeater ---
    // Every action here (create/remove an event, add/remove a tracked
    // class, save parameter names) is AJAX — see
    // class-cogito-rar-tracked-events-ajax.php — so nothing in this
    // section is a field posted by the page's main Save Settings form.
    // An event's name is never editable once created; it's the lookup
    // key every AJAX action below uses.
    const eventsRepeater = document.getElementById('rar-events-repeater');
    const addEventBtn    = document.getElementById('rar-add-event');

    const FIELD_NAME_LABELS = {
        destination_url:  'Destination URL',
        link_text:        'Link text',
        link_classes:     'Link classes',
        event_source_url: 'Page/Referrer URL'
    };
    const FIELD_PLACEHOLDERS = {
        destination_url:  'destination_url',
        link_text:        'link_text',
        link_classes:     'link_classes',
        event_source_url: 'not sent unless named'
    };

    function rarEventsAjax( action, data ) {
        const body = new URLSearchParams( Object.assign( {
            action: action,
            nonce: eventsRepeater ? eventsRepeater.dataset.nonce : ''
        }, data ) );

        return fetch( ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        } ).then( function ( r ) { return r.json(); } );
    }

    function buildGroupPill( group ) {
        const pill = document.createElement( 'span' );
        pill.className = 'rar-chip rar-chip--removable';
        pill.dataset.group = group.join( ' ' );
        pill.textContent = group.join( ' + ' ) + ' ';
        const remove = document.createElement( 'button' );
        remove.type = 'button';
        remove.className = 'rar-remove-group';
        remove.setAttribute( 'aria-label', 'Remove' );
        remove.innerHTML = '&times;';
        pill.appendChild( remove );
        return pill;
    }

    function buildEventRow( eventName ) {
        const row = document.createElement( 'div' );
        row.className = 'rar-event-row';
        row.dataset.eventName = eventName;

        let fieldsHtml = '';
        Object.keys( FIELD_NAME_LABELS ).forEach( function ( signal ) {
            fieldsHtml +=
                '<label class="rar-event-field rar-event-field--param">' + FIELD_NAME_LABELS[ signal ] + '<br>' +
                '<input type="text" class="rar-field-input" data-field="' + signal + '" value="" placeholder="' + FIELD_PLACEHOLDERS[ signal ] + '" style="width:100%; font-family:monospace;"></label>';
        } );

        row.innerHTML =
            '<div class="rar-event-row-header">' +
            '<strong class="rar-event-name"></strong>' +
            '<button type="button" class="button-link rar-remove-event">Remove this event</button>' +
            '</div>' +
            '<div class="rar-chip-row rar-tracked-groups"></div>' +
            '<div class="rar-add-group-row">' +
            '<input type="text" class="rar-add-group-input" placeholder="e.g. affi_btn or rl_wrap rl_drift">' +
            '<button type="button" class="button rar-add-group-btn">Add</button>' +
            '</div>' +
            '<label class="rar-event-notes-label">Notes<br>' +
            '<textarea class="rar-notes-textarea" rows="2" style="width:100%;" placeholder="e.g. which page/placement this covers, why these classes were chosen…"></textarea></label>' +
            '<details class="rar-event-field-names"><summary>Custom parameter names (optional)</summary>' +
            '<div class="rar-event-row-fields">' + fieldsHtml + '</div>' +
            '<p class="description">The custom_data field name(s) this event sends to Meta &mdash; matches how a GA4 event tag in GTM lets you name each parameter. Leave any blank to use the default shown as its placeholder; &quot;Page/Referrer URL&quot; is not sent at all unless named (it\'s already sent separately as a required standard field either way).</p>' +
            '</details>' +
            '<p><button type="button" class="button rar-save-fields-btn">Save</button> <span class="rar-save-status"></span></p>';

        row.querySelector( '.rar-event-name' ).textContent = eventName;
        return row;
    }

    function showNewEventForm() {
        if ( eventsRepeater.querySelector( '.rar-new-event-form' ) ) {
            return;
        }
        const form = document.createElement( 'div' );
        form.className = 'rar-new-event-form';
        form.innerHTML =
            '<input type="text" class="rar-new-event-name" placeholder="e.g. NewsletterClick" style="font-family:monospace;">' +
            '<button type="button" class="button button-primary rar-create-event-btn">Create event</button>' +
            '<button type="button" class="button rar-cancel-new-event">Cancel</button>' +
            '<span class="rar-save-status"></span>';
        eventsRepeater.appendChild( form );
        addEventBtn.style.display = 'none';
        form.querySelector( '.rar-new-event-name' ).focus();
    }

    function hideNewEventForm() {
        const form = eventsRepeater.querySelector( '.rar-new-event-form' );
        if ( form ) form.remove();
        addEventBtn.style.display = '';
    }

    function createEvent( form ) {
        const input  = form.querySelector( '.rar-new-event-name' );
        const status = form.querySelector( '.rar-save-status' );
        const name   = input.value.trim();
        if ( '' === name ) {
            input.focus();
            return;
        }
        status.textContent = 'Saving…';
        rarEventsAjax( 'rar_create_tracked_event', { name: name } ).then( function ( res ) {
            if ( ! res.success ) {
                status.textContent = res.data && res.data.message ? res.data.message : 'Could not create event.';
                return;
            }
            const row = buildEventRow( res.data.name );
            eventsRepeater.insertBefore( row, form );
            hideNewEventForm();
        } ).catch( function () {
            status.textContent = 'Could not create event — check your connection.';
        } );
    }

    if ( eventsRepeater && addEventBtn ) {
        addEventBtn.addEventListener( 'click', showNewEventForm );

        eventsRepeater.addEventListener( 'keydown', function ( e ) {
            if ( 'Enter' !== e.key ) return;
            if ( e.target.classList.contains( 'rar-new-event-name' ) ) {
                e.preventDefault();
                createEvent( e.target.closest( '.rar-new-event-form' ) );
            } else if ( e.target.classList.contains( 'rar-add-group-input' ) ) {
                e.preventDefault();
                e.target.nextElementSibling.click();
            }
        } );

        eventsRepeater.addEventListener( 'click', function ( e ) {
            const target = e.target;

            if ( target.classList.contains( 'rar-create-event-btn' ) ) {
                createEvent( target.closest( '.rar-new-event-form' ) );
                return;
            }

            if ( target.classList.contains( 'rar-cancel-new-event' ) ) {
                hideNewEventForm();
                return;
            }

            const row       = target.closest( '.rar-event-row' );
            const eventName = row ? row.dataset.eventName : '';

            if ( target.classList.contains( 'rar-remove-event' ) ) {
                if ( ! window.confirm( 'Remove the "' + eventName + '" event and all its tracked classes?' ) ) return;
                rarEventsAjax( 'rar_delete_tracked_event', { event_name: eventName } ).then( function ( res ) {
                    if ( res.success ) row.remove();
                } );
                return;
            }

            if ( target.classList.contains( 'rar-add-group-btn' ) ) {
                const input = row.querySelector( '.rar-add-group-input' );
                const value = input.value.trim();
                if ( '' === value ) { input.focus(); return; }
                rarEventsAjax( 'rar_add_tracked_group', { event_name: eventName, group: value } ).then( function ( res ) {
                    if ( ! res.success ) {
                        window.alert( res.data && res.data.message ? res.data.message : 'Could not add that class.' );
                        return;
                    }
                    row.querySelector( '.rar-tracked-groups' ).appendChild( buildGroupPill( res.data.group ) );
                    input.value = '';
                } );
                return;
            }

            if ( target.classList.contains( 'rar-remove-group' ) ) {
                const pill = target.closest( '.rar-chip' );
                rarEventsAjax( 'rar_remove_tracked_group', { event_name: eventName, group: pill.dataset.group } ).then( function ( res ) {
                    if ( res.success ) pill.remove();
                } );
                return;
            }

            if ( target.classList.contains( 'rar-save-fields-btn' ) ) {
                const status = row.querySelector( '.rar-save-status' );
                const data   = { event_name: eventName };
                row.querySelectorAll( '.rar-field-input' ).forEach( function ( input ) {
                    data[ 'field_' + input.dataset.field ] = input.value.trim();
                } );
                const notesInput = row.querySelector( '.rar-notes-textarea' );
                if ( notesInput ) data.notes = notesInput.value.trim();
                status.textContent = 'Saving…';
                rarEventsAjax( 'rar_save_tracked_event_fields', data ).then( function ( res ) {
                    status.textContent = res.success ? 'Saved.' : ( res.data && res.data.message ? res.data.message : 'Could not save.' );
                } ).catch( function () {
                    status.textContent = 'Could not save — check your connection.';
                } );
            }
        } );
    }

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

    // --- Bot Cleanup custom-range toggle ---
    // Show/hide the From/To date inputs when the Custom Range switch is ticked.
    const bcToggle = document.getElementById('bcCustomRangeToggle');
    const bcFields = document.getElementById('bcCustomRangeFields');
    if ( bcToggle && bcFields ) {
        bcToggle.addEventListener('change', function () {
            bcFields.style.display = bcToggle.checked ? 'inline-block' : 'none';
        });
    }

    // --- Bot Cleanup Logic ---
    // Verify-before-delete plus a "select all across pages" affordance.
    const cleanupForm = document.querySelector('.rar-bot-cleanup-form');

    if ( cleanupForm ) {
        const banner    = cleanupForm.querySelector('.rar-select-all-banner');
        const flagInput = cleanupForm.querySelector('.rar-select-all-flag');
        const selectAllLink = cleanupForm.querySelector('.rar-select-all-link');
        let   total     = banner ? parseInt(banner.getAttribute('data-total'), 10) : 0;

        function checkedCount() {
            return cleanupForm.querySelectorAll('input[name="bulk-select[]"]:checked').length;
        }
        function rowCount() {
            return cleanupForm.querySelectorAll('input[name="bulk-select[]"]').length;
        }

        // Keep the cross-page total and its banner/link text in sync after an
        // AJAX row removal
        function setTotal( n ) {
            total = parseInt(n, 10) || 0;
            if ( banner ) banner.setAttribute('data-total', total);
            if ( selectAllLink ) {
                selectAllLink.textContent = 'Select all ' + total.toLocaleString() + ' across all pages';
            }
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

        // Per-row Mark as human / Mark as unknown / Delete — AJAX, no reload.
        // The links' href remains a working fallback if this never attaches.
        cleanupForm.addEventListener('click', function ( e ) {
            const link = e.target.closest('.rar-row-human, .rar-row-unknown, .rar-row-bot, .rar-row-delete');
            if ( ! link ) return;
            e.preventDefault();

            const isDelete = link.classList.contains('rar-row-delete');
            if ( isDelete && ! confirm('Permanently delete this click? This cannot be undone.') ) {
                return;
            }

            const body = new URLSearchParams();
            body.append('action', 'rar_bot_cleanup_row');
            body.append('row_action', link.getAttribute('data-action'));
            body.append('click_id', link.getAttribute('data-click-id'));
            body.append('nonce', link.getAttribute('data-nonce'));

            link.style.pointerEvents = 'none'; // guard against a double-click

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(function ( r ) { return r.json(); })
            .then(function ( result ) {
                if ( ! result.success ) {
                    alert('Action failed: ' + ( result.data && result.data.message ? result.data.message : 'Unknown error' ));
                    link.style.pointerEvents = '';
                    return;
                }

                const row    = link.closest('tr');
                const action = result.data.action;

                if ( action === 'mark_unknown' || action === 'flag_bot' ) {
                    // Row stays in this table (both bot and unknown are shown) —
                    // update it in place and flip its row-action state.
                    const rowActions = row ? row.querySelector('.row-actions') : null;
                    const icon       = row ? row.querySelector('.column-type .dashicons') : null;
                    const nameCell   = row ? row.querySelector('.column-bot_name') : null;

                    if ( action === 'mark_unknown' ) {
                        if ( rowActions ) rowActions.setAttribute('data-state', 'unknown');
                        if ( icon ) {
                            icon.className = 'dashicons dashicons-editor-help';
                            icon.setAttribute('title', 'Click from unknown traffic type – further investigation required');
                        }
                        if ( nameCell ) nameCell.textContent = 'n/a';
                    } else {
                        if ( rowActions ) rowActions.setAttribute('data-state', 'bot');
                        if ( icon ) {
                            icon.className = 'dashicons dashicons-welcome-view-site';
                            icon.setAttribute('title', 'Known Bot');
                        }
                        if ( nameCell ) nameCell.textContent = 'Manually flagged';
                    }
                    link.style.pointerEvents = '';
                } else {
                    // Mark as human / Delete: the row leaves this table
                    if ( row && row.parentNode ) row.parentNode.removeChild(row);
                }

                setTotal(result.data.remaining);
                refreshBanner();
            })
            .catch(function () {
                alert('Request failed. Please try again.');
                link.style.pointerEvents = '';
            });
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

            if ( action !== 'delete' && action !== 'mark_human' && action !== 'mark_unknown' && action !== 'flag_bot' ) {
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

    // --- Re-scan all clicks with current detection rules ---
    // Loops batches over AJAX so a large table can't time out.
    const rescanBtn = document.querySelector('.rar-rescan-btn');

    if ( rescanBtn ) {
        const status = document.querySelector('.rar-rescan-status');
        const total  = parseInt(rescanBtn.getAttribute('data-total'), 10) || 0;
        const nonce  = rescanBtn.getAttribute('data-nonce');

        rescanBtn.addEventListener('click', function () {
            if ( ! confirm('Re-scan and reclassify all ' + total.toLocaleString() + ' clicks with the current rules? This overwrites every row\'s classification, including ones you set by hand.') ) {
                return;
            }

            rescanBtn.disabled = true;
            let done    = 0;
            let afterId = 0;

            function runBatch() {
                const body = new URLSearchParams();
                body.append('action', 'rar_rescan_batch');
                body.append('nonce', nonce);
                body.append('after_id', afterId);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function ( r ) { return r.json(); })
                .then(function ( result ) {
                    if ( ! result.success ) {
                        status.textContent = 'Error: ' + ( result.data && result.data.message ? result.data.message : 'unknown' );
                        rescanBtn.disabled = false;
                        return;
                    }
                    done   += result.data.processed;
                    afterId = result.data.last_id;
                    if ( result.data.done ) {
                        status.textContent = 'Done — re-scanned ' + done.toLocaleString() + ' clicks. Reloading…';
                        setTimeout(function () { location.reload(); }, 1200);
                    } else {
                        status.textContent = 'Re-scanned ' + done.toLocaleString() + ( total ? ' of ' + total.toLocaleString() : '' ) + '…';
                        runBatch();
                    }
                })
                .catch(function () {
                    status.textContent = 'Request failed. Please try again.';
                    rescanBtn.disabled = false;
                });
            }

            runBatch();
        });
    }
});
