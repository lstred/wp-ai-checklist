/**
 * AI Display Checklist for WPForms – Front-end script.
 *
 * Responsibilities:
 *  - Detect configured upload fields inside WPForms form elements.
 *  - Inject an "Analyze Image" button (unless auto-analyze is on).
 *  - Send the image file to the REST endpoint via FormData (nonce-authenticated).
 *  - Apply checkbox changes using exact WPForms DOM IDs.
 *  - Render a dismissible review panel.
 *  - Never submit the form automatically.
 *  - Never modify checkboxes outside the configured fields.
 *
 * @package AI_Display_Checklist_WPForms
 */

/* global aicwfData */
(function () {
    'use strict';

    var data = window.aicwfData;
    if ( ! data || ! Array.isArray( data.mappings ) || data.mappings.length === 0 ) {
        return;
    }

    // -------------------------------------------------------------------------
    // Initialise each mapping once the DOM is ready.
    // -------------------------------------------------------------------------

    function init() {
        data.mappings.forEach( initMapping );
    }

    function initMapping( mapping ) {
        var formEl = document.getElementById( 'wpforms-form-' + mapping.form_id );
        if ( ! formEl ) {
            return;
        }

        var uploadInput = document.getElementById(
            'wpforms-' + mapping.form_id + '-field_' + mapping.image_field_id
        );
        var uploadContainer = document.getElementById(
            'wpforms-' + mapping.form_id + '-field_' + mapping.image_field_id + '-container'
        );

        if ( ! uploadInput || ! uploadContainer ) {
            return;
        }

        // Create and insert the review panel immediately after the upload container.
        var reviewPanel = createReviewPanel( mapping );
        uploadContainer.parentNode.insertBefore( reviewPanel, uploadContainer.nextSibling );

        if ( mapping.auto_analyze ) {
            // Trigger automatically when a file is chosen.
            uploadInput.addEventListener( 'change', function () {
                if ( uploadInput.files && uploadInput.files.length > 0 ) {
                    runAnalysis( mapping, uploadInput, null, reviewPanel );
                }
            } );
        } else {
            // Insert the "Analyze Image" button between the container and the panel.
            var btn = createAnalyzeButton( mapping );
            uploadContainer.parentNode.insertBefore( btn, reviewPanel );

            btn.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                runAnalysis( mapping, uploadInput, btn, reviewPanel );
            } );
        }
    }

    // -------------------------------------------------------------------------
    // DOM creation helpers
    // -------------------------------------------------------------------------

    function createAnalyzeButton( mapping ) {
        var btn = document.createElement( 'button' );
        btn.type = 'button';
        btn.className = 'aicwf-analyze-btn';
        btn.textContent = mapping.button_label || data.i18n.analyzeBtn;
        btn.setAttribute( 'data-mapping-id', mapping.id );
        return btn;
    }

    function createReviewPanel( mapping ) {
        var panel = document.createElement( 'div' );
        panel.className = 'aicwf-review-panel';
        panel.id = 'aicwf-panel-' + mapping.id;
        panel.setAttribute( 'aria-live', 'polite' );
        panel.style.display = 'none';
        return panel;
    }

    // -------------------------------------------------------------------------
    // Analysis request
    // -------------------------------------------------------------------------

    function runAnalysis( mapping, uploadInput, btn, reviewPanel ) {
        if ( ! uploadInput.files || uploadInput.files.length === 0 ) {
            renderError( reviewPanel, data.i18n.noFile );
            return;
        }

        setLoading( btn, reviewPanel, true );

        var formData = new FormData();
        formData.append( 'image',      uploadInput.files[0] );
        formData.append( 'mapping_id', mapping.id );
        formData.append( 'form_id',    String( mapping.form_id ) );

        fetch( data.restUrl, {
            method:  'POST',
            headers: { 'X-WP-Nonce': data.nonce },
            body:    formData,
        } )
        .then( function ( response ) {
            return response.json().then( function ( body ) {
                if ( ! response.ok ) {
                    throw new Error( body.message || data.i18n.error );
                }
                return body;
            } );
        } )
        .then( function ( result ) {
            applyChanges( mapping, result );
            renderReviewPanel( reviewPanel, result, mapping );
        } )
        .catch( function ( err ) {
            renderError( reviewPanel, err.message || data.i18n.error );
        } )
        .finally( function () {
            setLoading( btn, reviewPanel, false, mapping );
        } );
    }

    function setLoading( btn, reviewPanel, isLoading, mapping ) {
        if ( btn ) {
            btn.disabled = isLoading;
            btn.textContent = isLoading
                ? data.i18n.analyzing
                : ( mapping ? mapping.button_label || data.i18n.analyzeBtn : data.i18n.analyzeBtn );
        }
        if ( isLoading ) {
            reviewPanel.style.display = 'none';
        }
    }

    // -------------------------------------------------------------------------
    // Apply checkbox changes
    // -------------------------------------------------------------------------

    /**
     * Update only the explicitly configured checklist fields.
     * Uses #wpforms-{form_id}-field_{field_id}_{choice_key} – the canonical
     * WPForms input ID – so we never touch unrelated DOM elements.
     *
     * @param {Object} mapping
     * @param {Object} result   Server response containing matched_checklist_items.
     */
    function applyChanges( mapping, result ) {
        var matched    = result.matched_checklist_items || [];
        var actionMode = result.action_mode || mapping.action_mode || 'check';
        var formId     = mapping.form_id;

        // Guard: only touch configured field IDs.
        var allowedFieldIds = mapping.checklist_field_ids.map( Number );

        matched.forEach( function ( item ) {
            var fieldId  = Number( item.field_id );
            var choiceKey = String( item.choice_key );

            // Safety check: this field must be in the configured list.
            if ( allowedFieldIds.indexOf( fieldId ) === -1 ) {
                return;
            }

            var inputId = 'wpforms-' + formId + '-field_' + fieldId + '_' + choiceKey;
            var input   = document.getElementById( inputId );
            if ( ! input || input.type !== 'checkbox' ) {
                return;
            }

            input.checked = ( actionMode === 'check' );

            // Trigger change event so WPForms' own listeners stay in sync.
            var evt = new Event( 'change', { bubbles: true } );
            input.dispatchEvent( evt );

            // Visual marker (removed when user changes the checkbox manually).
            var li = input.closest( 'li' );
            if ( li ) {
                li.classList.add( 'aicwf-ai-changed' );
                input.addEventListener( 'change', function () {
                    li.classList.remove( 'aicwf-ai-changed' );
                }, { once: true } );
            }
        } );
    }

    // -------------------------------------------------------------------------
    // Review panel rendering
    // -------------------------------------------------------------------------

    function renderReviewPanel( panel, result, mapping ) {
        var i18n       = data.i18n;
        var matched    = result.matched_checklist_items  || [];
        var unmatched  = result.unmatched_visible_cards  || [];
        var lowConf    = result.low_confidence           || [];
        var ignored    = result.ignored_text             || [];
        var actionMode = result.action_mode || mapping.action_mode || 'check';

        var html = '<div class="aicwf-panel-inner">'
            + '<div class="aicwf-panel-header">'
            + '<h3>' + esc( i18n.reviewTitle ) + '</h3>'
            + '<button type="button" class="aicwf-dismiss" aria-label="' + esc( i18n.dismiss ) + '">&times;</button>'
            + '</div>';

        // Matched & updated.
        if ( matched.length > 0 ) {
            var actionLabel = actionMode === 'check' ? esc( i18n.checked ) : esc( i18n.unchecked );
            html += '<div class="aicwf-section aicwf-section--matched">'
                + '<h4>' + esc( i18n.matchedTitle ) + ' <span class="aicwf-count">(' + matched.length + ')</span></h4>'
                + '<ul>';
            matched.forEach( function ( item ) {
                html += '<li>'
                    + '<span class="aicwf-label">' + esc( item.checklist_label ) + '</span>'
                    + ' <span class="aicwf-badge aicwf-badge--' + ( actionMode === 'check' ? 'check' : 'uncheck' ) + '">' + actionLabel + '</span>'
                    + ' <span class="aicwf-conf" title="' + esc( i18n.confidence ) + '">'
                    + Math.round( ( item.confidence || 0 ) * 100 ) + '%</span>'
                    + '</li>';
            } );
            html += '</ul></div>';
        } else {
            html += '<p class="aicwf-no-matches">' + esc( i18n.noMatches ) + '</p>';
        }

        // Visible cards not in checklist.
        if ( unmatched.length > 0 ) {
            html += '<div class="aicwf-section aicwf-section--unmatched">'
                + '<h4>' + esc( i18n.unmatchedTitle ) + ' <span class="aicwf-count">(' + unmatched.length + ')</span></h4>'
                + '<ul>';
            unmatched.forEach( function ( item ) {
                html += '<li>'
                    + esc( item.detected_text )
                    + ' <span class="aicwf-conf">' + Math.round( ( item.confidence || 0 ) * 100 ) + '%</span>'
                    + '</li>';
            } );
            html += '</ul></div>';
        }

        // Low confidence.
        if ( lowConf.length > 0 ) {
            html += '<div class="aicwf-section aicwf-section--low-conf">'
                + '<h4>' + esc( i18n.lowConfTitle ) + ' <span class="aicwf-count">(' + lowConf.length + ')</span></h4>'
                + '<ul>';
            lowConf.forEach( function ( item ) {
                var possible = Array.isArray( item.possible_matches ) && item.possible_matches.length > 0
                    ? ' — ' + esc( item.possible_matches.join( ', ' ) )
                    : '';
                html += '<li>'
                    + esc( item.text )
                    + possible
                    + ' <span class="aicwf-conf">' + Math.round( ( item.confidence || 0 ) * 100 ) + '%</span>'
                    + '</li>';
            } );
            html += '</ul></div>';
        }

        // Ignored text (collapsible).
        if ( ignored.length > 0 ) {
            html += '<details class="aicwf-section aicwf-section--ignored">'
                + '<summary>' + esc( i18n.ignoredTitle ) + ' (' + ignored.length + ')</summary>'
                + '<ul>';
            ignored.forEach( function ( item ) {
                html += '<li>' + esc( item.text ) + ' — <em>' + esc( item.reason ) + '</em></li>';
            } );
            html += '</ul></details>';
        }

        html += '</div>'; // .aicwf-panel-inner

        panel.innerHTML = html;
        panel.style.display = 'block';

        // Dismiss button.
        var dismissBtn = panel.querySelector( '.aicwf-dismiss' );
        if ( dismissBtn ) {
            dismissBtn.addEventListener( 'click', function () {
                panel.style.display = 'none';
            } );
        }

        // Scroll the panel into view.
        panel.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
    }

    function renderError( panel, message ) {
        panel.innerHTML = '<div class="aicwf-panel-inner aicwf-panel-inner--error">'
            + '<div class="aicwf-panel-header">'
            + '<p class="aicwf-error-msg">' + esc( message ) + '</p>'
            + '<button type="button" class="aicwf-dismiss" aria-label="Dismiss">&times;</button>'
            + '</div>'
            + '</div>';
        panel.style.display = 'block';

        var dismissBtn = panel.querySelector( '.aicwf-dismiss' );
        if ( dismissBtn ) {
            dismissBtn.addEventListener( 'click', function () {
                panel.style.display = 'none';
            } );
        }
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    /**
     * Escape a string for safe insertion into innerHTML.
     * Creates a text node and reads innerHTML – no regex substitution.
     *
     * @param {*} str
     * @return {string}
     */
    function esc( str ) {
        var div = document.createElement( 'div' );
        div.appendChild( document.createTextNode( String( str == null ? '' : str ) ) );
        return div.innerHTML;
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

}() );
