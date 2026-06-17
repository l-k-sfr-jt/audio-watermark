/**
 * admin.js — Audio Watermark for WooCommerce
 *
 * Drives the "Upload master audio" button on the product edit page.
 *
 * Flow:
 *  1. User clicks #audio-wm-upload-btn  → hidden file input is clicked.
 *  2. User picks a file                 → onChange fires.
 *  3. AJAX POST to WordPress            → PHP calls watermark service → returns { upload_url, s3_key }.
 *  4. fetch() PUT to presigned S3 URL   → file goes directly to S3 (never through WordPress).
 *  5. On success: populate _audio_wm_s3_key input; show success message.
 *  6. On any error: show error message in #audio-wm-upload-status.
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
        var s3KeyInput   = document.getElementById( '_audio_wm_s3_key' );

        // Guard: elements must all exist (we're on a product edit page).
        if ( ! uploadBtn || ! fileInput || ! statusDiv || ! s3KeyInput ) {
            return;
        }

        // Guard: localised data must be available.
        if ( typeof window.AudioWM === 'undefined' ) {
            return;
        }

        var ajaxUrl    = window.AudioWM.ajax_url;
        var nonce      = window.AudioWM.nonce;
        var productId  = window.AudioWM.product_id;

        // ── Helpers ──────────────────────────────────────────────────────────

        /**
         * Set the status message and optionally a CSS colour.
         *
         * @param {string} message  Text to display.
         * @param {string} [color]  CSS colour string, e.g. 'green' or '#c00'.
         */
        function setStatus( message, color ) {
            statusDiv.textContent = message;
            statusDiv.style.color = color || '';
        }

        /**
         * Disable / enable the upload button during async operations.
         *
         * @param {boolean} disabled
         */
        function setBusy( disabled ) {
            uploadBtn.disabled = disabled;
            uploadBtn.textContent = disabled
                ? 'Uploading…'     // "Uploading…"
                : 'Upload master audio';
        }

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
                // WordPress wraps success/error in { success: bool, data: {} }.
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
                    headers: {
                        'Content-Type': file.type || 'application/octet-stream',
                    },
                    body: file,
                } )
                .then( function ( s3Response ) {
                    if ( ! s3Response.ok ) {
                        return Promise.reject(
                            new Error( 'S3 upload failed with status ' + s3Response.status )
                        );
                    }
                    // ── Step 5: Update the S3 key field and show success ──────
                    s3KeyInput.value = s3Key;
                    // Trigger the WooCommerce change event so the "Save product"
                    // button becomes aware that the field has changed.
                    var changeEvent = new Event( 'change', { bubbles: true } );
                    s3KeyInput.dispatchEvent( changeEvent );

                    setStatus(
                        '✔ Upload complete! Save the product to persist the S3 key.',
                        'green'
                    );
                } );
            } )
            .catch( function ( error ) {
                // ── Step 6: Surface any error ─────────────────────────────────
                setStatus( '✖ ' + ( error.message || 'Upload failed.' ), '#c00' );
            } )
            .finally( function () {
                setBusy( false );
                // Reset the file input so the same file can be re-uploaded.
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
