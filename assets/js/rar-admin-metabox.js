// Admin-side JavaScript for RARLinks meta boxes.
// Handles weighted rotation, GEO targeting, and section visibility toggles.

jQuery(document).ready(function($) {

    // --- Weighted Rotation UI Logic ---
    const rotationContainer = $('#rar-rotation');

    // Helper to get all rotation rows
    function getRotationRows() {
        return rotationContainer.find('.rar-rotation-row');
    }

    // Helper to sync slider and number input values
    function syncRotationInputs($row, val) {
        $row.find('.weight-input').val(val);
        $row.find('.weight-slider').val(val);
    }

    // Rebalances weights across all rotation rows to sum to 100
    function rebalanceRotationRows(changedIndex) {
        const $rows = getRotationRows();
        const $changed = $rows.eq(changedIndex);
        const changedVal = parseInt($changed.find('.weight-input').val(), 10) || 0;

        const others = $rows.not($changed);
        let remaining = 100 - changedVal;
        if (remaining < 0) remaining = 0;

        const share = Math.floor(remaining / others.length);
        let leftover = remaining % others.length;

        others.each(function(i) {
            let val = share + (leftover > 0 ? 1 : 0);
            if (leftover > 0) leftover--;
            syncRotationInputs($(this), val);
        });
    }

    // Event listener for changes on weight sliders/inputs
    rotationContainer.on('input change', '.weight-slider, .weight-input', function() {
        const $row = $(this).closest('.rar-rotation-row');
        const index = $row.index();
        let value = parseInt($(this).val(), 10);
        if (isNaN(value)) value = 0;
        if (value > 100) value = 100;
        if (value < 0) value = 0;
        syncRotationInputs($row, value);
        rebalanceRotationRows(index);
    });

    // Event listener for adding new rotation rows
    $('#add-rotation').on('click', function() {
        const idx = getRotationRows().length;
        const row = `
            <div class="rar-rotation-row" data-index="${idx}">
                <label>URL:
                    <input type="url" name="rar_rotation[${idx}][url]" style="width:60%;">
                </label>
                <label>Weight:
                    <input type="range" class="weight-slider" min="0" max="100" step="1" value="0">
                    <input type="number" class="weight-input" name="rar_rotation[${idx}][weight]" value="0" min="0" max="100" style="width:60px;">
                </label>
                <a href="#" class="remove-rotation" style="margin-left:10px;">Remove</a>
            </div>`;
        rotationContainer.append(row);
        rebalanceRotationRows(idx);
    });

    // Event listener for removing rotation rows
    rotationContainer.on('click', '.remove-rotation', function(e) {
        e.preventDefault();
        $(this).closest('.rar-rotation-row').remove();
        getRotationRows().each(function(i) {
            $(this).attr('data-index', i);
            $(this).find('input[type="url"]').attr('name', `rar_rotation[${i}][url]`);
            $(this).find('.weight-input').attr('name', `rar_rotation[${i}][weight]`);
        });
        rebalanceRotationRows(0);
    });


    // --- GEO Targeting UI Logic ---
    const geoContainer = document.getElementById('rar-geo');

    // Event listener for adding new GEO rows
    if (geoContainer) { // Ensure container exists before adding listeners
        document.getElementById('add-geo').addEventListener('click', function(){
            const idx = geoContainer.children.length;
            const row = document.createElement('div');
            row.className = 'rar-geo-row';
            row.dataset.index = idx;
            row.innerHTML = `
                <label>Country:<input list="rar-country-list" name="rar_geo[${idx}][country]" style="width:25%;"></label>
                <label>URL:<input type="url" name="rar_geo[${idx}][url]" style="width:65%;"></label>
                <a href="#" class="remove-geo">Remove</a>
            `;
            geoContainer.appendChild(row);
        });

        // Event listener for removing GEO rows (using event delegation)
        geoContainer.addEventListener('click', function(e){
            if ( e.target && e.target.matches('a.remove-geo') ) {
                e.preventDefault();
                const row = e.target.closest('.rar-geo-row');
                row.remove();
                // Re-index remaining rows
                Array.from(geoContainer.children).forEach(function(r, i) {
                    r.dataset.index = i;
                    r.querySelector('input[list="rar-country-list"]').name = `rar_geo[${i}][country]`;
                    r.querySelector('input[type="url"]').name = `rar_geo[${i}][url]`;
                    const rem = r.querySelector('.remove-geo');
                    if (rem) rem.style.display = i === 0 ? 'none' : 'inline';
                });
            }
        });
    }


   // --- Section Toggle Visibility Logic (GEO/Rotation) ---
    function toggleSection(toggleId, sectionId) {
        const isChecked = $(toggleId).is(':checked');
        // Only attempt to toggle if the section element exists
        if ($(sectionId).length) {
            $(sectionId).toggle(isChecked);
        }
    }

    // Initial show/hide based on saved meta
    // Ensure elements exist before trying to toggle them
    if ($('#rar_geo_enabled').length && $('#rar-geo').length) {
        toggleSection('#rar_geo_enabled', '#rar-geo');
    }
    if ($('#rar_rotation_enabled').length && $('#rar-rotation').length) {
        toggleSection('#rar_rotation_enabled', '#rar-rotation');
    }

    // Listen for changes (these can remain as they target existing elements on change)
    $('#rar_geo_enabled').on('change', function() {
        toggleSection('#rar_geo_enabled', '#rar-geo');
    });

    $('#rar_rotation_enabled').on('change', function() {
        toggleSection('#rar_rotation_enabled', '#rar-rotation');
    });


    // --- Active Toggle UI Logic (Main Meta Box Fields) ---
    /*
     * Hides all redirect meta fields (except the toggle itself) when the RARLink is inactive.
     */
    function toggleRARActiveUI() {
        const isActive = $('#rar_active').is(':checked');
        // Only attempt to toggle if the meta fields container exists
        if ($('#rar-meta-fields').length) {
            $('#rar-meta-fields').toggle(isActive);
        }
        // Only attempt to toggle if the inactive note exists
        if ($('.rar-inactive-note').length) {
            $('.rar-inactive-note').toggle(!isActive);
        }
    }

    // Run on page load
    toggleRARActiveUI();

    // Re-run on toggle change
    $('#rar_active').on('change', toggleRARActiveUI);


    // --- Moto Partner Status Visibility ---
    /*
     * The Live/Archived status only applies when the link is flagged a Moto
     * Partner, so show those radios only while the checkbox is ticked.
     */
    function toggleMotoStatus() {
        if ($('#rar-moto-status').length) {
            $('#rar-moto-status').toggle($('#rar_moto_partner').is(':checked'));
        }
    }

    if ($('#rar_moto_partner').length) {
        toggleMotoStatus(); // Initial state on page load
        $('#rar_moto_partner').on('change', toggleMotoStatus);
    }


    // --- Track-only (no-cloak domains, e.g. Amazon) live UI reactivity ---
    /*
     * Mirrors what the server already enforces on save (see
     * Cogito_RAR_Track_Only::resolve() and class-metabox-save.php) so the
     * Redirect Type / GEO / Rotation controls grey out and the vanity URL
     * preview updates the instant a recognised URL is typed or the
     * checkbox is toggled — not only after a save/reload. The server-side
     * enforcement is still what's actually authoritative; this is purely
     * a same-page preview of it.
     */
    (function () {
        const $target   = $('#rar_target');
        const $checkbox = $('#rar_track_only');
        if (!$target.length || !$checkbox.length) return;

        const domains = (typeof rarTrackOnly !== 'undefined' && rarTrackOnly.domains) ? rarTrackOnly.domains : [];

        function hostRequiresTrackOnly(url) {
            let host;
            try {
                host = new URL($.trim(url)).hostname.toLowerCase();
            } catch (e) {
                return false; // Not a parseable absolute URL (yet) — nothing to detect.
            }
            return domains.some(function (d) {
                return host === d || host.slice(-(d.length + 1)) === '.' + d;
            });
        }

        function applyTrackOnlyUI(trackOnly, forced) {
            $checkbox.prop('checked', trackOnly).prop('disabled', forced);
            $('#rar-track-only-required-note').toggle(forced);

            // Redirect Type: disabled <select> isn't submitted by the
            // browser, so a hidden field (same id the PHP render uses)
            // carries the real value through while it's disabled.
            const $select = $('#rar_type');
            if ($select.length) {
                let $hidden = $('#rar_type_hidden');
                if (trackOnly && !$hidden.length) {
                    $('<input type="hidden" id="rar_type_hidden" name="rar_type">')
                        .val($select.val())
                        .insertAfter($select);
                } else if (!trackOnly) {
                    $hidden.remove();
                }
                $select.prop('disabled', trackOnly);
            }
            $('#rar-redirect-type-note').toggle(trackOnly);

            // GEO / Rotation: greyed out + non-interactive, same visual
            // treatment the PHP render uses, and their own toggles forced
            // off (mirroring the server forcing _rar_geo_enabled/
            // _rar_rotation_enabled to '0' on save).
            ['#rar-geo-wrapper', '#rar-rotation-wrapper'].forEach(function (sel) {
                $(sel).css({ opacity: trackOnly ? 0.5 : '', pointerEvents: trackOnly ? 'none' : '' });
            });
            $('#rar-geo-disabled-note').toggle(trackOnly);
            $('#rar-rotation-disabled-note').toggle(trackOnly);
            if (trackOnly) {
                $('#rar_geo_enabled, #rar_rotation_enabled').prop('checked', false);
            }

            // Vanity URL preview + its copy button (which copies from its
            // own data-copy attribute, not the input's live value — see
            // the admin_footer script in class-cogito-rar-admin-columns.php).
            const $vanityInput = $('#rar-vanity-url-input');
            if ($vanityInput.length) {
                const newValue = trackOnly ? $.trim($target.val()) : $vanityInput.data('go-url');
                $vanityInput.val(newValue);
                $vanityInput.siblings('.rar-copy-btn').attr('data-copy', newValue);
            }
        }

        function syncTrackOnlyUI() {
            const forced    = hostRequiresTrackOnly($target.val());
            const wasForced = $checkbox.data('wasForced') === true;
            // Forced wins outright; dropping OUT of forced (the target no
            // longer matches) reverts to unchecked rather than sticking
            // checked just because an earlier call set it that way;
            // otherwise this is a genuine manual choice — respect
            // whatever's currently checked.
            const trackOnly = forced ? true : ( wasForced ? false : $checkbox.is(':checked') );

            $checkbox.data('wasForced', forced);
            applyTrackOnlyUI(trackOnly, forced);
        }

        $target.on('input change', syncTrackOnlyUI);
        $checkbox.on('change', syncTrackOnlyUI);

        syncTrackOnlyUI(); // Reconcile with whatever the browser restored on load (back-button, crash recovery) before any typing happens.
    })();

}); // End jQuery(document).ready