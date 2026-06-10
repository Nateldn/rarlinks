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

}); // End jQuery(document).ready