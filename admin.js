/**
 * Vista API Admin — progress bar for the "Importar Imóveis Agora" button.
 *
 * When the form is submitted normally the PHP import runs synchronously and
 * can time out on large catalogs. This script leaves the form submission intact
 * (standard POST) but polls the vista_import_progress AJAX endpoint every 2
 * seconds to show a live progress bar while the server is working.
 *
 * The progress transient is written by Vista_Importer::run_full().
 */
jQuery( function ( $ ) {
	var $form    = $( '#vista-import-form' );
	var $btn     = $( '#vista-import-btn' );
	var $wrap    = $( '#vista-progress-wrap' );
	var $bar     = $( '#vista-progress-bar' );
	var $msg     = $( '#vista-progress-msg' );
	var pollTimer = null;
	var i18n     = ( typeof vistaAdmin !== 'undefined' ) ? vistaAdmin.i18n : {};

	if ( ! $form.length ) {
		return;
	}

	function stopPolling() {
		if ( pollTimer ) {
			clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	function setProgress( pct, message ) {
		$bar.css( 'width', pct + '%' );
		$msg.text( message || '' );
	}

	function pollProgress() {
		$.post(
			vistaAdmin.ajaxurl,
			{
				action: 'vista_import_progress',
				nonce:  vistaAdmin.nonce_progress,
			},
			function ( response ) {
				if ( ! response.success ) {
					return;
				}
				var data = response.data;
				setProgress( data.pct || 0, data.message || '' );

				if ( 'done' === data.status ) {
					stopPolling();
					$btn.prop( 'disabled', false ).text( i18n.done || 'Concluído!' );
				}
			}
		).fail( function () {
			// Network error — show a note but keep polling; the import is likely still running.
			$msg.text( i18n.error || 'Erro ao verificar progresso.' );
		} );
	}

	$form.on( 'submit', function () {
		// Show progress UI and disable button to prevent double-submit.
		$wrap.show();
		$btn.prop( 'disabled', true ).text( i18n.importing || 'Importando...' );
		setProgress( 0, i18n.importing || 'Importando...' );

		// Start polling after a short delay so the server has time to begin.
		setTimeout( function () {
			pollProgress();
			pollTimer = setInterval( pollProgress, 2000 );
		}, 1500 );

		// Allow the form to submit normally (synchronous PHP import).
		return true;
	} );
} );
