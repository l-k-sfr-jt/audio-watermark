/**
 * admin.js — Audio Watermark for WooCommerce
 *
 * Drives the multi-file master upload on the product edit page, and the
 * "Test connection" button on the WooCommerce settings page.
 *
 * Upload flow (product edit page):
 *  1. User clicks #audio-wm-upload-btn  → hidden file input is clicked.
 *  2. User picks a file                 → onChange fires.
 *  3. AJAX POST to WordPress            → PHP calls watermark service → returns { upload_url, s3_key }.
 *  4. fetch() PUT to presigned S3 URL   → file goes directly to S3 (never through WordPress).
 *  5. On success: append s3_key to the hidden JSON list; add a row to #audio-wm-files-list.
 *  6. On any error: show error message in #audio-wm-upload-status.
 *  Remove: clicking "Remove" on a list row drops that key from the JSON and removes the row.
 *
 * Depends on `window.AudioWM` being localised by Audio_WM_Product_Panel::enqueue_scripts():
 *   { ajax_url: string, nonce: string, product_id: number }
 *
 * Vanilla JS — no jQuery dependency.
 */

( function () {
    'use strict';

    /**
     * Wait for the DOM to be ready before wiring up event listeners.
     */
    document.addEventListener( 'DOMContentLoaded', function () {
        var uploadBtn    = document.getElementById( 'audio-wm-upload-btn' );
        var fileInput    = document.getElementById( 'audio-wm-file' );
        var statusDiv    = document.getElementById( 'audio-wm-upload-status' );
        var s3KeysInput  = document.getElementById( '_audio_wm_s3_keys' );
        var filesList    = document.getElementById( 'audio-wm-files-list' );

        // Guard: elements must all exist (we're on a product edit page).
        if ( ! uploadBtn || ! fileInput || ! statusDiv || ! s3KeysInput || ! filesList ) {
            return;
        }

        // Guard: localised data must be available.
        if ( typeof window.AudioWM === 'undefined' ) {
            return;
        }

        var ajaxUrl   = window.AudioWM.ajax_url;
        var nonce     = window.AudioWM.nonce;
        var productId = window.AudioWM.product_id;

        // ── Helpers ──────────────────────────────────────────────────────────

        function setStatus( message, color ) {
            statusDiv.textContent = message;
            statusDiv.style.color = color || '';
        }

        function setBusy( disabled ) {
            uploadBtn.disabled    = disabled;
            uploadBtn.textContent = disabled ? 'Uploading…' : 'Add master audio file';
        }

        /** Read the current list of S3 keys from the hidden input. */
        function getKeys() {
            try { return JSON.parse( s3KeysInput.value || '[]' ) || []; } catch ( e ) { return []; }
        }

        /** Write an updated key array back to the hidden input and fire a change event. */
        function setKeys( keys ) {
            s3KeysInput.value = JSON.stringify( keys );
            s3KeysInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
        }

        /** Append one file row to the visible list. */
        function addFileRow( key ) {
            var li   = document.createElement( 'li' );
            li.setAttribute( 'data-key', key );
            li.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:4px;';

            var code = document.createElement( 'code' );
            code.style.flex   = '1';
            code.textContent  = key.split( '/' ).pop();

            var btn = document.createElement( 'button' );
            btn.type      = 'button';
            btn.className = 'button-link audio-wm-remove-file';
            btn.style.color = '#a00';
            btn.textContent = 'Remove';

            li.appendChild( code );
            li.appendChild( btn );
            filesList.appendChild( li );
        }

        // ── Remove handler (event delegation — works for rows added dynamically) ──

        filesList.addEventListener( 'click', function ( e ) {
            if ( ! e.target.classList.contains( 'audio-wm-remove-file' ) ) {
                return;
            }
            var li  = e.target.closest( 'li' );
            var key = li ? li.getAttribute( 'data-key' ) : null;
            if ( ! key ) {
                return;
            }
            setKeys( getKeys().filter( function ( k ) { return k !== key; } ) );
            if ( li.parentNode ) {
                li.parentNode.removeChild( li );
            }
        } );

        // ── Step 1: button click → open file picker ──────────────────────────

        uploadBtn.addEventListener( 'click', function () {
            fileInput.click();
        } );

        // ── Step 2 → 5: file selected → full upload flow ─────────────────────

        fileInput.addEventListener( 'change', function () {
            var file = fileInput.files && fileInput.files[0];
            if ( ! file ) {
                return;
            }

            setBusy( true );
            setStatus( 'Requesting upload URL…', '#666' );

            // ── Step 3: Ask WordPress/PHP for a presigned PUT URL ─────────────

            var formData = new FormData();
            formData.append( 'action',       'audio_wm_get_upload_url' );
            formData.append( 'nonce',        nonce );
            formData.append( 'product_id',   productId );
            formData.append( 'filename',     file.name );
            formData.append( 'content_type', file.type || 'application/octet-stream' );

            fetch( ajaxUrl, {
                method: 'POST',
                body:   formData,
            } )
            .then( function ( response ) {
                if ( ! response.ok ) {
                    return Promise.reject( new Error( 'WordPress AJAX error: ' + response.status ) );
                }
                return response.json();
            } )
            .then( function ( data ) {
                if ( ! data.success ) {
                    var msg = ( data.data && data.data.message )
                        ? data.data.message
                        : 'Unknown error from server.';
                    return Promise.reject( new Error( msg ) );
                }

                var uploadUrl = data.data.upload_url;
                var s3Key     = data.data.s3_key;

                if ( ! uploadUrl || ! s3Key ) {
                    return Promise.reject( new Error( 'Server returned an incomplete response.' ) );
                }

                setStatus( 'Uploading file to S3…', '#666' );

                // ── Step 4: PUT file directly to S3 via presigned URL ─────────

                return fetch( uploadUrl, {
                    method:  'PUT',
                    headers: { 'Content-Type': file.type || 'application/octet-stream' },
                    body:    file,
                } )
                .then( function ( s3Response ) {
                    if ( ! s3Response.ok ) {
                        return Promise.reject(
                            new Error( 'S3 upload failed with status ' + s3Response.status )
                        );
                    }
                    // ── Step 5: Append key to list; prompt to save ────────────
                    var keys = getKeys();
                    if ( keys.indexOf( s3Key ) === -1 ) {
                        keys.push( s3Key );
                        setKeys( keys );
                        addFileRow( s3Key );
                    }
                    setStatus( '✔ Upload complete! Save the product to persist.', 'green' );
                } );
            } )
            .catch( function ( error ) {
                setStatus( '✖ ' + ( error.message || 'Upload failed.' ), '#c00' );
            } )
            .finally( function () {
                setBusy( false );
                fileInput.value = '';
            } );
        } );
    } );

    // ── Settings page: "Test connection" button ──────────────────────────────

    document.addEventListener( 'DOMContentLoaded', function () {
        var testBtn    = document.getElementById( 'audio-wm-test-btn' );
        var testResult = document.getElementById( 'audio-wm-test-result' );

        if ( ! testBtn || ! testResult ) {
            return;
        }
        if ( typeof window.AudioWMSettings === 'undefined' ) {
            return;
        }

        testBtn.addEventListener( 'click', function ( e ) {
            e.preventDefault();
            testBtn.disabled    = true;
            testResult.textContent = 'Testing…';
            testResult.style.color = '#666';

            var fd = new FormData();
            fd.append( 'action', 'audio_wm_test_connection' );
            fd.append( 'nonce',  window.AudioWMSettings.nonce );

            fetch( window.AudioWMSettings.ajax_url, { method: 'POST', body: fd } )
                .then( function ( r ) { return r.json(); } )
                .then( function ( data ) {
                    if ( data.success ) {
                        testResult.textContent = '✔ ' + data.data;
                        testResult.style.color = 'green';
                    } else {
                        testResult.textContent = '✖ ' + ( data.data || 'Connection failed.' );
                        testResult.style.color = '#c00';
                    }
                } )
                .catch( function ( err ) {
                    testResult.textContent = '✖ ' + ( err.message || 'Request failed.' );
                    testResult.style.color = '#c00';
                } )
                .finally( function () {
                    testBtn.disabled = false;
                } );
        } );
    } );

} )();
