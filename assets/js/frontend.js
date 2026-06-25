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

    // ---- Diagnostics: visible in browser DevTools console ----
    if ( typeof window.aicwfData === 'undefined' ) {
        console.warn( '[AICWF] aicwfData is not defined. The plugin script loaded but received no data from WordPress. Check that the plugin files are up to date on the server and that at least one mapping is enabled.' );
        return;
    }

    var data = window.aicwfData;

    if ( ! Array.isArray( data.mappings ) || data.mappings.length === 0 ) {
        console.warn( '[AICWF] aicwfData.mappings is empty. No active mappings found.' );
        return;
    }

    console.log( '[AICWF] Loaded. Mappings:', data.mappings.length, data.mappings );

    // -------------------------------------------------------------------------
    // Initialise each mapping once the DOM is ready.
    // -------------------------------------------------------------------------

    function init() {
        data.mappings.forEach( initMapping );
    }

    /**
     * Find the WPForms form element using several fallback selectors.
     * WPForms renders: <form id="wpforms-form-{id}"> OR <div class="wpforms-container"> containing it.
     */
    function findFormEl( formId ) {
        return document.getElementById( 'wpforms-form-' + formId )
            || document.querySelector( '[data-formid="' + formId + '"]' )
            || document.querySelector( '.wpforms-form[data-formid="' + formId + '"]' )
            || null;
    }

    /**
     * Find the upload input for a given field ID within a form.
     * WPForms file-upload fields may be:
     *   - A standard <input type="file" id="wpforms-{form}-field_{field}">
     *   - Inside a Dropzone wrapper where the input is hidden; we find the
     *     nearest real file input inside the field container.
     */
    function findUploadInput( formEl, formId, fieldId ) {
        // Try canonical ID first.
        var byId = document.getElementById( 'wpforms-' + formId + '-field_' + fieldId );
        if ( byId ) {
            return byId;
        }

        // Fall back: find any file input inside the field container.
        var container = findFieldContainer( formEl, formId, fieldId );
        if ( container ) {
            var fileInput = container.querySelector( 'input[type="file"]' );
            if ( fileInput ) {
                return fileInput;
            }
        }

        return null;
    }

    /**
     * Find the outermost container div for a field.
     * WPForms uses: <div id="wpforms-{form}-field_{field}-container" class="wpforms-field ...">
     * or just a div with the field class containing the field.
     */
    function findFieldContainer( formEl, formId, fieldId ) {
        // Canonical container ID.
        var byContainerId = document.getElementById(
            'wpforms-' + formId + '-field_' + fieldId + '-container'
        );
        if ( byContainerId ) {
            return byContainerId;
        }

        // Some WPForms versions / themes use a div without -container suffix.
        // Search inside the form for the field wrapper.
        if ( formEl ) {
            var candidate = formEl.querySelector(
                '[id="wpforms-' + formId + '-field_' + fieldId + '"]'
            );
            if ( candidate ) {
                // Walk up to the .wpforms-field wrapper.
                var parent = candidate.parentElement;
                while ( parent && parent !== formEl ) {
                    if ( parent.classList.contains( 'wpforms-field' ) ) {
                        return parent;
                    }
                    parent = parent.parentElement;
                }
            }
        }

        return null;
    }

    function initMapping( mapping ) {
        console.log( '[AICWF] initMapping for form', mapping.form_id, 'image field', mapping.image_field_id );

        var formEl = findFormEl( mapping.form_id );
        if ( ! formEl ) {
            console.warn( '[AICWF] Form element not found for form_id', mapping.form_id,
                '— tried #wpforms-form-' + mapping.form_id + ' and data-formid selectors.' );
            return;
        }
        console.log( '[AICWF] Found form element:', formEl.id || formEl );

        // Find the field container for the upload field.
        var uploadContainer = findFieldContainer( formEl, mapping.form_id, mapping.image_field_id );

        if ( ! uploadContainer ) {
            // Last-resort: any element whose ID contains the field ID pattern.
            var anyField = formEl.querySelector( '[id*="field_' + mapping.image_field_id + '"]' );
            if ( anyField ) {
                uploadContainer = anyField.closest( '.wpforms-field' ) || anyField.parentElement;
            }
        }

        if ( ! uploadContainer ) {
            console.warn( '[AICWF] Upload field container not found for field_id', mapping.image_field_id );
            return;
        }
        console.log( '[AICWF] Found upload container:', uploadContainer.id || uploadContainer );

        // Insert the button and review panel directly on the <form> element,
        // before the submit container. This is outside any inner wrapper divs
        // that might have overflow:hidden or other layout constraints.
        var reviewPanel  = createReviewPanel( mapping );
        var submitWrap   = formEl.querySelector( '.wpforms-submit-container' );

        if ( submitWrap ) {
            formEl.insertBefore( reviewPanel, submitWrap );
        } else {
            formEl.appendChild( reviewPanel );
        }

        if ( mapping.auto_analyze ) {
            // Listen on any native file input inside the container.
            var fileInputs = uploadContainer.querySelectorAll( 'input[type="file"]' );
            fileInputs.forEach( function ( inp ) {
                inp.addEventListener( 'change', function () {
                    if ( inp.files && inp.files.length > 0 ) {
                        runAnalysis( mapping, inp, null, reviewPanel );
                    }
                } );
            } );

            // WPForms Dropzone fires change on the hidden input after async upload.
            // Also watch for value changes on the hidden WPForms field input.
            observeHiddenInput( formEl, mapping, reviewPanel, null );

        } else {
            // Insert the "Analyze Image" button right before the review panel.
            var btn = createAnalyzeButton( mapping );
            reviewPanel.parentNode.insertBefore( btn, reviewPanel );
            console.log( '[AICWF] Analyze button inserted:', btn );

            btn.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                // Resolve the upload input at click-time (Dropzone may have populated it by now).
                var uploadInput = findUploadInput( formEl, mapping.form_id, mapping.image_field_id );
                runAnalysis( mapping, uploadInput, btn, reviewPanel );
            } );
        }
    }

    /**
     * Watch the hidden WPForms field input for value changes (Dropzone async uploads).
     * When a value appears, trigger analysis automatically.
     *
     * @param {HTMLElement} formEl
     * @param {Object}      mapping
     * @param {HTMLElement} reviewPanel
     * @param {HTMLElement|null} btn
     */
    function observeHiddenInput( formEl, mapping, reviewPanel, btn ) {
        var selectors = [
            'input[name="wpforms[fields][' + mapping.image_field_id + ']"]',
            'input[name="wpforms[fields][' + mapping.image_field_id + '][]"]',
        ];

        var found = null;
        for ( var i = 0; i < selectors.length; i++ ) {
            found = formEl.querySelector( selectors[i] );
            if ( found ) break;
        }

        if ( ! found ) {
            // The hidden input may not exist yet (WPForms creates it after first upload).
            // Use MutationObserver to wait for it.
            if ( window.MutationObserver ) {
                var obs = new MutationObserver( function ( mutations ) {
                    for ( var j = 0; j < selectors.length; j++ ) {
                        var el = formEl.querySelector( selectors[j] );
                        if ( el ) {
                            obs.disconnect();
                            watchInputValue( el, mapping, reviewPanel, btn );
                            return;
                        }
                    }
                } );
                obs.observe( formEl, { childList: true, subtree: true } );
            }
            return;
        }

        watchInputValue( found, mapping, reviewPanel, btn );
    }

    /**
     * Fire analysis when the hidden WPForms input value changes to a non-empty string.
     */
    function watchInputValue( input, mapping, reviewPanel, btn ) {
        var lastValue = input.value;

        // If it already has a value (e.g. page reload with stored value), don't auto-fire.
        var handler = function () {
            if ( input.value && input.value !== lastValue ) {
                lastValue = input.value;
                runAnalysis( mapping, null, btn, reviewPanel );
            }
        };

        input.addEventListener( 'change', handler );
        input.addEventListener( 'input',  handler );

        // Also poll briefly in case the event doesn't fire (some Dropzone versions).
        var polls = 0;
        var interval = setInterval( function () {
            polls++;
            if ( polls > 60 ) {
                clearInterval( interval );
                return;
            }
            if ( input.value && input.value !== lastValue ) {
                lastValue = input.value;
                clearInterval( interval );
                runAnalysis( mapping, null, btn, reviewPanel );
            }
        }, 500 );
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

    /**
     * Find the Dropzone instance attached to the upload field and return
     * the first accepted File object.  Dropzone keeps file objects in memory
     * even after the async upload completes, so the File is always available.
     *
     * @param {HTMLElement} formEl
     * @param {number}      fieldId
     * @return {File|null}
     */
    function getDropzoneFile( formEl, fieldId ) {
        if ( ! formEl ) {
            return null;
        }

        // WPForms Modern uploader: Dropzone is initialised on .wpforms-uploader
        // inside the field container.  The Dropzone instance is stored on the
        // element as .dropzone property by Dropzone.js.
        var uploaderSelectors = [
            '[id*="field_' + fieldId + '-container"] .wpforms-uploader',
            '[id*="field_' + fieldId + '"] .wpforms-uploader',
            '.wpforms-field-file-upload .wpforms-uploader',
        ];

        for ( var s = 0; s < uploaderSelectors.length; s++ ) {
            var el = formEl.querySelector( uploaderSelectors[s] );
            if ( ! el ) continue;

            var dz = el.dropzone || ( el.getAttribute && el.getAttribute( 'data-dz' ) );
            if ( el.dropzone ) {
                var accepted = el.dropzone.getAcceptedFiles ? el.dropzone.getAcceptedFiles() : [];
                if ( accepted.length > 0 ) {
                    console.log( '[AICWF] Got file from Dropzone.getAcceptedFiles:', accepted[0].name );
                    return accepted[0];
                }
                // Dropzone may list it only in .files (queued/uploaded)
                var allFiles = el.dropzone.files || [];
                if ( allFiles.length > 0 ) {
                    console.log( '[AICWF] Got file from Dropzone.files:', allFiles[0].name );
                    return allFiles[0];
                }
            }
        }

        // Fallback: check every element inside the form that has a .dropzone property.
        var allEls = formEl.querySelectorAll( '*' );
        for ( var i = 0; i < allEls.length; i++ ) {
            if ( allEls[i].dropzone ) {
                var f = allEls[i].dropzone.getAcceptedFiles ? allEls[i].dropzone.getAcceptedFiles() : allEls[i].dropzone.files || [];
                if ( f.length > 0 ) {
                    console.log( '[AICWF] Got file from fallback Dropzone scan:', f[0].name );
                    return f[0];
                }
            }
        }

        return null;
    }

    /**
     * Get the file to analyze — tries all possible sources in priority order.
     *
     * 1. Native file input (WPForms Classic uploader)
     * 2. Dropzone in-memory File object (WPForms Modern uploader)
     * 3. Hidden input value set by WPForms after async upload
     *
     * @param {HTMLElement}      formEl
     * @param {HTMLInputElement|null} uploadInput
     * @param {Object}           mapping
     * @return {{ file: File|null, tempUrl: string|null }}
     */
    function getUploadedFile( formEl, uploadInput, mapping ) {
        // 1. Native file input (Classic uploader).
        if ( uploadInput && uploadInput.files && uploadInput.files.length > 0 ) {
            console.log( '[AICWF] File source: native input' );
            return { file: uploadInput.files[0], tempUrl: null };
        }

        // 2. Dropzone in-memory file object (Modern uploader).
        var dzFile = getDropzoneFile( formEl, mapping.image_field_id );
        if ( dzFile ) {
            return { file: dzFile, tempUrl: null };
        }

        // 3. Hidden input WPForms writes after async upload.
        if ( formEl ) {
            var hiddenSelectors = [
                'input[name="wpforms[fields][' + mapping.image_field_id + ']"]',
                'input[name="wpforms[fields][' + mapping.image_field_id + '][]"]',
            ];
            for ( var i = 0; i < hiddenSelectors.length; i++ ) {
                var hidden = formEl.querySelector( hiddenSelectors[i] );
                if ( ! hidden || ! hidden.value ) continue;

                var val = hidden.value.trim();
                if ( val.charAt(0) === '[' || val.charAt(0) === '{' ) {
                    try {
                        var parsed = JSON.parse( val );
                        if ( Array.isArray( parsed ) && parsed[0] ) {
                            val = parsed[0].file_url || parsed[0].url || parsed[0] || val;
                        } else if ( parsed && ( parsed.file_url || parsed.url ) ) {
                            val = parsed.file_url || parsed.url;
                        }
                    } catch ( e ) { /* use val as-is */ }
                }
                if ( val ) {
                    console.log( '[AICWF] File source: hidden input URL', val );
                    return { file: null, tempUrl: String( val ) };
                }
            }

            // Last resort: Dropzone preview data-url attribute.
            var preview = formEl.querySelector( '.dz-preview [data-dz-name]' );
            if ( preview ) {
                var previewUrl = preview.getAttribute( 'data-url' ) || preview.getAttribute( 'data-src' );
                if ( previewUrl ) {
                    return { file: null, tempUrl: previewUrl };
                }
            }
        }

        console.warn( '[AICWF] getUploadedFile: no file found. Dropzone scan result was null.' );
        return { file: null, tempUrl: null };
    }

    function runAnalysis( mapping, uploadInput, btn, reviewPanel ) {
        var formEl = findFormEl( mapping.form_id );
        var source = getUploadedFile( formEl, uploadInput, mapping );

        if ( ! source.file && ! source.tempUrl ) {
            renderError( reviewPanel, data.i18n.noFile );
            return;
        }

        setLoading( btn, reviewPanel, true );

        var formData = new FormData();
        formData.append( 'mapping_id', mapping.id );
        formData.append( 'form_id',    String( mapping.form_id ) );

        if ( source.file ) {
            formData.append( 'image', source.file );
        } else {
            formData.append( 'image_url', source.tempUrl );
        }

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
