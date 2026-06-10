// Dashboard UI: controls filter interactions and custom date range toggle.

document.addEventListener('DOMContentLoaded', function () {

    // --- Quick Range Select Logic ---
    // Clears custom date fields and submits when a quick range is selected.
    const rangeSelect = document.getElementById('range');
    if (rangeSelect) {
        rangeSelect.addEventListener('change', function () {
            const form = this.form;
            if (form.from) form.from.value = '';
            if (form.to) form.to.value = '';
            form.submit();
        });
    }

    // --- Custom Date Submit Logic ---
    // Clears the quick range dropdown when custom dates are submitted.
    const form = document.querySelector('form');
    if (form) {
        const from  = form.querySelector('[name="from"]');
        const to    = form.querySelector('[name="to"]');
        const range = form.querySelector('[name="range"]');

        form.addEventListener('submit', function () {
            if ((from && from.value) || (to && to.value)) {
                if (range) range.value = '';
            }
        });
    }

    // --- Custom Date Range Toggle Logic ---
    // Shows/hides the date picker fields when the toggle is clicked.
    const toggle = document.getElementById('customRangeToggle');
    const fields = document.getElementById('customRangeFields');

    if (toggle && fields) {
        toggle.addEventListener('change', function () {
            if (this.checked) {
                fields.style.display = 'inline-block';
            } else {
                fields.style.display = 'none';
                const from = fields.querySelector('[name="from"]');
                const to   = fields.querySelector('[name="to"]');
                if (from) from.value = '';
                if (to)   to.value   = '';
            }
        });
    }

// --- RARLink Live Search Logic ---
// Deferred slightly to ensure filter form elements are fully rendered before attaching listeners.
setTimeout(function () {
    const linkSearch  = document.getElementById('rar-link-search');
    const postIdInput = document.getElementById('rar-post-id-input');
    const datalist    = document.getElementById('rar-link-list');

    if ( ! linkSearch || ! postIdInput || ! datalist ) return;

    linkSearch.addEventListener('input', function () {
        const searchVal = this.value.trim();

        // Clear post_id if the field is empty
        if ( ! searchVal ) {
            postIdInput.value = '';
            return;
        }

        // Find a matching option in the datalist by exact value match
        const options = datalist.querySelectorAll('option');
        let matched   = false;

        options.forEach(function ( option ) {
            if ( option.value === searchVal ) {
                postIdInput.value = option.getAttribute('data-id');
                matched = true;
            }
        });

        // Clear post_id if no exact match found
        if ( ! matched ) {
            postIdInput.value = '';
        }
    });
}, 100);


        // --- Moto Partner Panel Logic ---
    // Handles expand/collapse of the panel and AJAX disabling of individual partners.
    const motoPanel = document.querySelector('.rar-moto-panel');
    
    if ( motoPanel ) {
        const toggleBtn = motoPanel.querySelector('.rar-moto-panel-toggle');
        const panelBody = motoPanel.querySelector('.rar-moto-panel-body');
        const countSpan = motoPanel.querySelector('.rar-moto-count');
        const nonce     = motoPanel.getAttribute('data-nonce');
    
        // Expand / collapse the panel
        if ( toggleBtn && panelBody ) {
            toggleBtn.addEventListener('click', function () {
                panelBody.style.display = ( panelBody.style.display === 'none' ) ? 'block' : 'none';
            });
        }
    
        // Disable a partner via AJAX (event delegation on the panel body)
        if ( panelBody ) {
            panelBody.addEventListener('click', function ( e ) {
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
                        // Remove the disabled item from the list
                        listItem.remove();
                        // Update the count badge
                        if ( countSpan ) {
                            countSpan.textContent = result.data.remaining;
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

});