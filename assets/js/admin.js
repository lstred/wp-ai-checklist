/**
 * AI Display Checklist for WPForms – Admin script.
 *
 * Handles:
 *  - Tab switching.
 *  - Dynamic form/field loading for the mapping editor.
 *  - Add / Edit / Delete mappings (client-side state; saved via AJAX).
 *  - "Test AI Connection" button.
 *  - Settings save (General tab).
 *
 * @package AI_Display_Checklist_WPForms
 */

/* global aicwfAdmin, jQuery */
(function ( $ ) {
    'use strict';

    var admin  = window.aicwfAdmin;
    var nonce  = admin.nonce;
    var ajaxUrl = admin.ajaxUrl;
    var i18n   = admin.i18n;

    // In-memory mappings array (kept in sync with the server on each save).
    var mappings = Array.isArray( admin.mappings ) ? JSON.parse( JSON.stringify( admin.mappings ) ) : [];

    // -------------------------------------------------------------------------
    // Tab switching
    // -------------------------------------------------------------------------

    $( '.aicwf-tabs .nav-tab' ).on( 'click', function ( e ) {
        e.preventDefault();
        var tab = $( this ).data( 'tab' );

        $( '.aicwf-tabs .nav-tab' ).removeClass( 'nav-tab-active' );
        $( this ).addClass( 'nav-tab-active' );

        $( '.aicwf-tab-content' ).hide();
        $( '#tab-' + tab ).show();
    } );

    // -------------------------------------------------------------------------
    // API key show/hide toggle
    // -------------------------------------------------------------------------

    $( '.aicwf-toggle-key' ).on( 'click', function () {
        var targetId = $( this ).data( 'target' );
        var input    = $( '#' + targetId );
        input.attr( 'type', input.attr( 'type' ) === 'password' ? 'text' : 'password' );
    } );

    // -------------------------------------------------------------------------
    // Test AI connection
    // -------------------------------------------------------------------------

    $( '#aicwf-test-connection' ).on( 'click', function () {
        var $btn    = $( this );
        var $result = $( '#aicwf-test-result' );

        $btn.prop( 'disabled', true ).text( i18n.testingConn );
        $result.text( '' ).removeClass( 'aicwf-success aicwf-error' );

        $.post( ajaxUrl, {
            action: 'aicwf_test_connection',
            nonce:  nonce,
        } )
        .done( function ( resp ) {
            if ( resp.success ) {
                $result.text( i18n.testSuccess ).addClass( 'aicwf-success' );
            } else {
                $result.text( i18n.testFailed + ( resp.data || '' ) ).addClass( 'aicwf-error' );
            }
        } )
        .fail( function () {
            $result.text( i18n.testFailed + 'Network error.' ).addClass( 'aicwf-error' );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( 'Test AI Connection' );
        } );
    } );

    // -------------------------------------------------------------------------
    // Save general settings
    // -------------------------------------------------------------------------

    $( '#aicwf-save-general' ).on( 'click', function () {
        var $btn    = $( this );
        var $notice = $( '#aicwf-notice' );

        var settings = {
            ai_provider:          $( '#aicwf_ai_provider' ).val(),
            openai_model:         $( '#aicwf_openai_model' ).val(),
            openai_api_key:       $( '#aicwf_openai_api_key' ).val(),
            debug_logging:        $( '#aicwf_debug_logging' ).is( ':checked' ) ? '1' : '0',
            rate_limit_requests:  $( '#aicwf_rate_limit_requests' ).val(),
            rate_limit_window:    $( '#aicwf_rate_limit_window' ).val(),
            max_file_size_mb:     $( '#aicwf_max_file_size_mb' ).val(),
            confidence_threshold: $( '#aicwf_confidence_threshold' ).val(),
            mappings:             mappings,
        };

        $btn.prop( 'disabled', true ).text( i18n.saving );
        $notice.hide();

        $.post( ajaxUrl, {
            action:   'aicwf_save_settings',
            nonce:    nonce,
            settings: JSON.stringify( settings ),
        } )
        .done( function ( resp ) {
            if ( resp.success ) {
                showNotice( i18n.saved, 'success' );
                // Mask key field after successful save.
                var keyField = $( '#aicwf_openai_api_key' );
                if ( keyField.val() && keyField.val().indexOf( '\u2022' ) === -1 ) {
                    keyField.val( '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022' );
                }
            } else {
                showNotice( i18n.saveFailed + ( resp.data || '' ), 'error' );
            }
        } )
        .fail( function () {
            showNotice( i18n.saveFailed + 'Network error.', 'error' );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( 'Save Settings' );
        } );
    } );

    // -------------------------------------------------------------------------
    // Mapping editor: open for "Add"
    // -------------------------------------------------------------------------

    $( '#aicwf-add-mapping' ).on( 'click', function () {
        openEditor( null );
    } );

    // -------------------------------------------------------------------------
    // Mapping editor: open for "Edit"
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.aicwf-edit-mapping', function () {
        var id      = $( this ).data( 'id' );
        var mapping = getMappingById( id );
        if ( mapping ) {
            openEditor( mapping );
        }
    } );

    // -------------------------------------------------------------------------
    // Mapping editor: delete
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.aicwf-delete-mapping', function () {
        if ( ! window.confirm( i18n.confirmDelete ) ) {
            return;
        }
        var id = $( this ).data( 'id' );
        mappings = mappings.filter( function ( m ) { return m.id !== id; } );
        saveAndRefreshTable();
    } );

    // -------------------------------------------------------------------------
    // Mapping editor: cancel
    // -------------------------------------------------------------------------

    $( '#aicwf-cancel-mapping' ).on( 'click', function () {
        closeEditor();
    } );

    // -------------------------------------------------------------------------
    // Mapping editor: save
    // -------------------------------------------------------------------------

    $( '#aicwf-save-mapping' ).on( 'click', function () {
        var editorId = $( '#aicwf-edit-mapping-id' ).val();
        var formId   = parseInt( $( '#aicwf-map-form' ).val(), 10 );

        if ( ! formId ) {
            alert( 'Please select a WPForms form.' );
            return;
        }

        var checklistIds = [];
        $( '.aicwf-checklist-field-cb:checked' ).each( function () {
            checklistIds.push( parseInt( $( this ).val(), 10 ) );
        } );

        if ( checklistIds.length === 0 ) {
            alert( 'Please select at least one checklist field.' );
            return;
        }

        var newMapping = {
            id:                   editorId || ( 'map_' + Date.now() ),
            enabled:              $( '#aicwf-map-enabled' ).is( ':checked' ),
            label:                $( '#aicwf-map-label' ).val().trim(),
            form_id:              formId,
            image_field_id:       parseInt( $( '#aicwf-map-image-field' ).val(), 10 ) || 0,
            checklist_field_ids:  checklistIds,
            action_mode:          $( 'input[name="aicwf-map-action-mode"]:checked' ).val() || 'check',
            auto_analyze:         $( '#aicwf-map-auto-analyze' ).is( ':checked' ),
            button_label:         $( '#aicwf-map-button-label' ).val().trim() || 'Analyze Image',
            confidence_threshold: parseFloat( $( '#aicwf-map-confidence' ).val() ) || 0.65,
        };

        if ( editorId ) {
            // Replace existing.
            mappings = mappings.map( function ( m ) {
                return m.id === editorId ? newMapping : m;
            } );
        } else {
            mappings.push( newMapping );
        }

        closeEditor();
        saveAndRefreshTable();
    } );

    // -------------------------------------------------------------------------
    // Auto-analyze toggle → show/hide button label row
    // -------------------------------------------------------------------------

    $( '#aicwf-map-auto-analyze' ).on( 'change', function () {
        $( '#aicwf-button-label-row' ).toggle( ! $( this ).is( ':checked' ) );
    } );

    // -------------------------------------------------------------------------
    // Dynamic form/field loading
    // -------------------------------------------------------------------------

    $( '#aicwf-map-form' ).on( 'change', function () {
        var formId = parseInt( $( this ).val(), 10 );

        resetFieldSelectors();

        if ( ! formId ) {
            return;
        }

        $( '#aicwf-map-image-field' ).prop( 'disabled', true )
            .html( '<option>' + i18n.loadingFields + '</option>' );

        $.post( ajaxUrl, {
            action:  'aicwf_get_form_fields',
            nonce:   nonce,
            form_id: formId,
        } )
        .done( function ( resp ) {
            if ( ! resp.success || ! Array.isArray( resp.data ) ) {
                return;
            }

            var fields      = resp.data;
            var uploadFields = fields.filter( function ( f ) { return f.is_file_upload; } );
            var cbFields     = fields.filter( function ( f ) { return f.is_checkbox; } );

            // Image upload select.
            var $imgSelect = $( '#aicwf-map-image-field' );
            $imgSelect.html( '<option value="">' + i18n.selectField + '</option>' );
            if ( uploadFields.length === 0 ) {
                $imgSelect.append( '<option disabled>' + i18n.noUploadFields + '</option>' );
            } else {
                uploadFields.forEach( function ( f ) {
                    $imgSelect.append(
                        $( '<option>' ).val( f.id ).text( f.label + ' (ID ' + f.id + ')' )
                    );
                } );
                $imgSelect.prop( 'disabled', false );
            }

            // Checkbox fields checkboxes.
            var $wrap = $( '#aicwf-checklist-fields-wrap' ).empty();
            if ( cbFields.length === 0 ) {
                $wrap.html( '<em>' + i18n.noCheckboxFields + '</em>' );
            } else {
                cbFields.forEach( function ( f ) {
                    var label = $( '<label>' ).addClass( 'aicwf-cb-label' );
                    var cb    = $( '<input type="checkbox" class="aicwf-checklist-field-cb">' )
                        .val( f.id )
                        .attr( 'id', 'aicwf-cbfield-' + f.id );
                    label.append( cb ).append( ' ' + escHtml( f.label ) + ' <small>(ID ' + f.id + ')</small>' );
                    $wrap.append( label );
                } );
            }
        } )
        .fail( function () {
            $( '#aicwf-map-image-field' ).html( '<option>Error loading fields.</option>' );
        } );
    } );

    // -------------------------------------------------------------------------
    // Editor open / close
    // -------------------------------------------------------------------------

    function openEditor( mapping ) {
        var $editor = $( '#aicwf-mapping-editor' );
        $( '#aicwf-editor-title' ).text(
            mapping ? 'Edit Mapping' : 'Add Mapping'
        );

        // Reset.
        $( '#aicwf-edit-mapping-id' ).val( mapping ? mapping.id : '' );
        $( '#aicwf-map-label' ).val( mapping ? mapping.label : '' );
        $( '#aicwf-map-form' ).val( '' );
        resetFieldSelectors();
        $( 'input[name="aicwf-map-action-mode"][value="check"]' ).prop( 'checked', true );
        $( '#aicwf-map-auto-analyze' ).prop( 'checked', false );
        $( '#aicwf-map-button-label' ).val( 'Analyze Image' );
        $( '#aicwf-map-confidence' ).val( '0.65' );
        $( '#aicwf-map-enabled' ).prop( 'checked', true );
        $( '#aicwf-button-label-row' ).show();

        if ( mapping ) {
            $( '#aicwf-map-form' ).val( String( mapping.form_id ) );
            $( '#aicwf-map-action-mode-' + mapping.action_mode ).prop( 'checked', true );
            $( 'input[name="aicwf-map-action-mode"][value="' + mapping.action_mode + '"]' ).prop( 'checked', true );
            $( '#aicwf-map-auto-analyze' ).prop( 'checked', !! mapping.auto_analyze );
            $( '#aicwf-map-button-label' ).val( mapping.button_label || 'Analyze Image' );
            $( '#aicwf-map-confidence' ).val( mapping.confidence_threshold || 0.65 );
            $( '#aicwf-map-enabled' ).prop( 'checked', !! mapping.enabled );
            $( '#aicwf-button-label-row' ).toggle( ! mapping.auto_analyze );

            // Load fields for the pre-selected form, then mark saved selections.
            var savedImageFieldId    = mapping.image_field_id;
            var savedChecklistIds    = mapping.checklist_field_ids || [];

            // Trigger form change to load fields, then restore saved selections.
            $( '#aicwf-map-form' ).trigger( 'change' );

            // After the AJAX completes, restore field selections.
            // We poll briefly since the AJAX is async – this is acceptable for an admin-only page.
            var attempts = 0;
            var interval = setInterval( function () {
                attempts++;
                var $imgSelect = $( '#aicwf-map-image-field' );
                if ( $imgSelect.find( 'option[value="' + savedImageFieldId + '"]' ).length || attempts > 20 ) {
                    clearInterval( interval );
                    $imgSelect.val( String( savedImageFieldId ) );
                    savedChecklistIds.forEach( function ( fid ) {
                        $( '#aicwf-cbfield-' + fid ).prop( 'checked', true );
                    } );
                }
            }, 200 );
        }

        $editor.slideDown( 200 );
        $editor[0].scrollIntoView( { behavior: 'smooth' } );
    }

    function closeEditor() {
        $( '#aicwf-mapping-editor' ).slideUp( 200 );
    }

    function resetFieldSelectors() {
        $( '#aicwf-map-image-field' )
            .prop( 'disabled', true )
            .html( '<option value="">' + i18n.selectField + '</option>' );
        $( '#aicwf-checklist-fields-wrap' )
            .html( '<em>Select a form to see available checkbox fields.</em>' );
    }

    // -------------------------------------------------------------------------
    // Save and refresh table
    // -------------------------------------------------------------------------

    function saveAndRefreshTable() {
        var $notice = $( '#aicwf-notice' );

        // Read current general settings to bundle with mappings.
        var settings = {
            ai_provider:          $( '#aicwf_ai_provider' ).val(),
            openai_model:         $( '#aicwf_openai_model' ).val(),
            openai_api_key:       $( '#aicwf_openai_api_key' ).val(),
            debug_logging:        $( '#aicwf_debug_logging' ).is( ':checked' ) ? '1' : '0',
            rate_limit_requests:  $( '#aicwf_rate_limit_requests' ).val(),
            rate_limit_window:    $( '#aicwf_rate_limit_window' ).val(),
            max_file_size_mb:     $( '#aicwf_max_file_size_mb' ).val(),
            confidence_threshold: $( '#aicwf_confidence_threshold' ).val(),
            mappings:             mappings,
        };

        $.post( ajaxUrl, {
            action:   'aicwf_save_settings',
            nonce:    nonce,
            settings: JSON.stringify( settings ),
        } )
        .done( function ( resp ) {
            if ( resp.success ) {
                showNotice( i18n.saved, 'success' );
                rebuildMappingsTable();
            } else {
                showNotice( i18n.saveFailed + ( resp.data || '' ), 'error' );
            }
        } )
        .fail( function () {
            showNotice( i18n.saveFailed + 'Network error.', 'error' );
        } );
    }

    // -------------------------------------------------------------------------
    // Rebuild the mappings table from in-memory state
    // -------------------------------------------------------------------------

    function rebuildMappingsTable() {
        var $wrap = $( '#aicwf-mappings-table-wrap' );

        if ( mappings.length === 0 ) {
            $wrap.html( '<p class="aicwf-no-mappings">No mappings configured yet. Click &quot;Add New Mapping&quot; to get started.</p>' );
            return;
        }

        var html = '<table class="wp-list-table widefat fixed striped aicwf-mappings-table">'
            + '<thead><tr>'
            + '<th>Label</th><th>Form ID</th><th>Action Mode</th><th>Auto-Analyze</th><th>Status</th><th>Actions</th>'
            + '</tr></thead><tbody>';

        mappings.forEach( function ( m ) {
            html += '<tr data-mapping-id="' + escAttr( m.id ) + '">'
                + '<td>' + escHtml( m.label || '(unnamed)' ) + '</td>'
                + '<td>' + escHtml( String( m.form_id ) ) + '</td>'
                + '<td>' + escHtml( m.action_mode === 'uncheck' ? 'Uncheck detected' : 'Check detected' ) + '</td>'
                + '<td>' + ( m.auto_analyze ? 'Yes' : 'No' ) + '</td>'
                + '<td><span class="aicwf-status aicwf-status-' + ( m.enabled ? 'active' : 'inactive' ) + '">'
                + ( m.enabled ? 'Active' : 'Inactive' ) + '</span></td>'
                + '<td>'
                + '<button type="button" class="button button-small aicwf-edit-mapping" data-id="' + escAttr( m.id ) + '">Edit</button> '
                + '<button type="button" class="button button-small aicwf-delete-mapping" data-id="' + escAttr( m.id ) + '">Delete</button>'
                + '</td>'
                + '</tr>';
        } );

        html += '</tbody></table>';
        $wrap.html( html );
    }

    // -------------------------------------------------------------------------
    // Helper: load forms into the form selector on page load
    // -------------------------------------------------------------------------

    function loadForms() {
        var $formSelect = $( '#aicwf-map-form' );
        if ( $formSelect.length === 0 ) {
            return;
        }

        $.post( ajaxUrl, { action: 'aicwf_get_forms', nonce: nonce } )
        .done( function ( resp ) {
            if ( ! resp.success || ! Array.isArray( resp.data ) ) {
                return;
            }
            $formSelect.html( '<option value="">' + i18n.selectForm + '</option>' );
            resp.data.forEach( function ( form ) {
                $formSelect.append(
                    $( '<option>' ).val( form.id ).text( form.title + ' (ID ' + form.id + ')' )
                );
            } );
        } );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function getMappingById( id ) {
        return mappings.find( function ( m ) { return m.id === id; } ) || null;
    }

    function showNotice( message, type ) {
        var $notice = $( '#aicwf-notice' );
        $notice
            .removeClass( 'notice-success notice-error' )
            .addClass( type === 'success' ? 'notice-success' : 'notice-error' )
            .html( '<p>' + escHtml( message ) + '</p>' )
            .show();

        setTimeout( function () { $notice.fadeOut(); }, 4000 );
    }

    function escHtml( str ) {
        return String( str == null ? '' : str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' );
    }

    function escAttr( str ) {
        return escHtml( str );
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    $( function () {
        loadForms();
    } );

}( jQuery ) );
