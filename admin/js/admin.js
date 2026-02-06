(function ($) {
	'use strict';

	var pollTimer = null;

	function runChunk() {
		return $.post( piratezIO.ajaxUrl, {
			action: 'piratez_io_run_chunk',
			nonce: piratezIO.nonce
		} );
	}

	function refreshStatus() {
		return $.post( piratezIO.ajaxUrl, {
			action: 'piratez_io_batch_status',
			nonce: piratezIO.nonce
		} );
	}

	function updateUI( data ) {
		var state = data.state || {};
		var stats = data.stats || {};
		var needing = data.needing_work != null ? data.needing_work : 0;
		$('#ssw-mb-saved').text( (stats.mb_saved || 0).toFixed(1) );
		$('#ssw-images-optimized').text( (stats.images_optimized || 0).toLocaleString() );
		$('#ssw-progress').text( (state.processed || 0) + ' / ' + (state.total || 0) );
		$('#ssw-needing-work').text( needing );
		if ( state.status === 'running' ) {
			$('#ssw-start-batch').prop( 'disabled', true );
			$('#ssw-pause-batch').prop( 'disabled', false );
			$('#ssw-batch-message').text( piratezIO.strings.running ).css( 'color', '' );
		} else if ( state.status === 'paused' ) {
			$('#ssw-start-batch').prop( 'disabled', false );
			$('#ssw-pause-batch').prop( 'disabled', true );
			$('#ssw-batch-message').text( piratezIO.strings.paused ).css( 'color', '#646970' );
		} else {
			$('#ssw-start-batch').prop( 'disabled', false );
			$('#ssw-pause-batch').prop( 'disabled', true );
			if ( state.processed > 0 && state.status === 'idle' ) {
				$('#ssw-batch-message').text( piratezIO.strings.done ).css( 'color', '#00a32a' );
			} else {
				$('#ssw-batch-message').text( '' );
			}
		}
	}

	function poll() {
		runChunk().done( function ( res ) {
			if ( res.success && res.data ) {
				updateUI( res.data );
				if ( res.data.state && res.data.state.status === 'running' ) {
					pollTimer = setTimeout( poll, 800 );
				} else {
					pollTimer = null;
				}
			} else {
				pollTimer = null;
			}
		} ).fail( function () {
			$('#ssw-batch-message').text( piratezIO.strings.error ).css( 'color', '#d63638' );
			pollTimer = null;
		} );
	}

	$('#ssw-start-batch').on( 'click', function () {
		var $btn = $( this );
		if ( $btn.prop( 'disabled' ) ) return;
		$btn.prop( 'disabled', true );
		$('#ssw-batch-message').text( piratezIO.strings.starting ).css( 'color', '' );
		$.post( piratezIO.ajaxUrl, {
			action: 'piratez_io_start_batch',
			nonce: piratezIO.nonce
		} ).done( function ( res ) {
			if ( res.success ) {
				poll();
			} else {
				$('#ssw-batch-message').text( res.data && res.data.message ? res.data.message : piratezIO.strings.error ).css( 'color', '#d63638' );
				$btn.prop( 'disabled', false );
			}
		} ).fail( function () {
			$('#ssw-batch-message').text( piratezIO.strings.error ).css( 'color', '#d63638' );
			$btn.prop( 'disabled', false );
		} );
	} );

	$('#ssw-pause-batch').on( 'click', function () {
		var $btn = $( this );
		if ( $btn.prop( 'disabled' ) ) return;
		$.post( piratezIO.ajaxUrl, {
			action: 'piratez_io_pause_batch',
			nonce: piratezIO.nonce
		} ).done( function () {
			if ( pollTimer ) clearTimeout( pollTimer );
			pollTimer = null;
			refreshStatus().done( function ( res ) {
				if ( res.success && res.data ) updateUI( res.data );
			} );
		} );
	} );
})( jQuery );
