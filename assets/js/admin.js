/* global jQuery, wp, rmAdmin */
jQuery( function ( $ ) {
	'use strict';

	// --- Fotogalerie -------------------------------------------------------
	var frame;

	$( document ).on( 'click', '.rm-gallery-add', function ( e ) {
		e.preventDefault();

		if ( ! window.wp || ! wp.media ) {
			return;
		}

		if ( ! frame ) {
			frame = wp.media( {
				title: rmAdmin.chooseImages,
				button: { text: rmAdmin.addToGallery },
				library: { type: 'image' },
				multiple: true
			} );

			frame.on( 'select', function () {
				var list = $( '#rm-gallery .rm-gallery-list' );

				frame.state().get( 'selection' ).each( function ( attachment ) {
					var data = attachment.toJSON();

					if ( list.find( 'input[value="' + data.id + '"]' ).length ) {
						return;
					}

					var thumb = ( data.sizes && data.sizes.thumbnail ) ? data.sizes.thumbnail.url : data.url;

					list.append(
						$( '<li/>' )
							.append( $( '<input/>', { type: 'hidden', name: 'rm_gallery_ids[]', value: data.id } ) )
							.append( $( '<img/>', { src: thumb, alt: '' } ) )
							.append( $( '<button/>', { type: 'button', 'class': 'button-link rm-gallery-remove', html: '&times;' } ) )
					);
				} );
			} );
		}

		frame.open();
	} );

	$( document ).on( 'click', '.rm-gallery-remove', function ( e ) {
		e.preventDefault();
		$( this ).closest( 'li' ).remove();
	} );

	// --- Direkt-Upload -----------------------------------------------------
	function rmAppendGalleryItem( id, thumbUrl ) {
		$( '#rm-gallery .rm-gallery-list' ).append(
			$( '<li/>' )
				.append( $( '<input/>', { type: 'hidden', name: 'rm_gallery_ids[]', value: id } ) )
				.append( $( '<img/>', { src: thumbUrl, alt: '' } ) )
				.append( $( '<button/>', { type: 'button', 'class': 'button-link rm-gallery-remove', html: '&times;' } ) )
		);
	}

	$( document ).on( 'click', '.rm-upload-add', function ( e ) {
		e.preventDefault();
		$( '#rm-upload-input' ).trigger( 'click' );
	} );

	$( document ).on( 'change', '#rm-upload-input', function () {
		var files = this.files;
		if ( ! files || ! files.length ) {
			return;
		}

		var $spinner = $( '.rm-upload-spinner' );
		var pending = files.length;
		$spinner.addClass( 'is-active' );

		Array.prototype.forEach.call( files, function ( file ) {
			var formData = new FormData();
			formData.append( 'action', 'rm_upload_photo' );
			formData.append( 'nonce', $( '#rm_upload_nonce' ).val() );
			formData.append( 'post_id', $( '#post_ID' ).val() );
			formData.append( 'rm_photo', file );

			$.ajax( {
				url: window.ajaxurl,
				method: 'POST',
				data: formData,
				processData: false,
				contentType: false
			} ).done( function ( response ) {
				if ( response && response.success && response.data ) {
					rmAppendGalleryItem( response.data.id, response.data.thumb );
				} else {
					window.alert( ( response && response.data && response.data.message ) || rmAdmin.uploadError );
				}
			} ).fail( function () {
				window.alert( rmAdmin.uploadError );
			} ).always( function () {
				pending -= 1;
				if ( pending < 1 ) {
					$spinner.removeClass( 'is-active' );
				}
			} );
		} );

		this.value = '';
	} );

	// --- Tierart-Wechsel: Genetik-Felder live aktualisieren ----------------
	$( document ).on( 'change', '#rm_species', function () {
		var $spinner = $( '.rm-species-spinner' );
		$spinner.addClass( 'is-active' );

		$.post( window.ajaxurl, {
			action: 'rm_species_genes',
			nonce: $( '#rm_species_genes_nonce' ).val(),
			post_id: $( '#post_ID' ).val(),
			term_id: $( this ).val()
		} ).done( function ( response ) {
			if ( response && response.success && response.data && response.data.html ) {
				$( '#rm-genetics-inner' ).html( response.data.html );
			}
		} ).always( function () {
			$spinner.removeClass( 'is-active' );
		} );
	} );

	// --- Fütterung: „Alle Tiere“-Umschalter --------------------------------
	$( document ).on( 'change', '.rm-check-all', function () {
		$( this )
			.closest( '.rm-choice-group' )
			.find( '.rm-choice-cb' )
			.prop( 'checked', this.checked );
	} );

	$( document ).on( 'change', '.rm-choice-cb', function () {
		var group = $( this ).closest( '.rm-choice-group' );
		var all = group.find( '.rm-choice-cb' ).length;
		var checked = group.find( '.rm-choice-cb:checked' ).length;
		group.find( '.rm-check-all' ).prop( 'checked', all > 0 && all === checked );
	} );

	// --- Gelege (Verpaarung) -----------------------------------------------
	function rmRenumberClutches() {
		$( '#rm-clutch-table tbody tr' ).each( function ( i ) {
			$( this ).find( '.rm-clutch-no' ).text( i + 1 );
		} );
	}

	function rmClutchCell( name ) {
		return $( '<td/>' ).append(
			$( '<input/>', { type: 'number', 'class': 'rm-narrow', name: name, min: '0' } )
		);
	}

	$( '#rm-clutch-add' ).on( 'click', function () {
		var row = $( '<tr/>' )
			.append( $( '<td/>', { 'class': 'rm-clutch-no' } ) )
			.append( $( '<td/>' ).append( $( '<input/>', { type: 'date', name: 'rm_clutch_lay[]' } ) ) )
			.append( rmClutchCell( 'rm_clutch_eggs[]' ) )
			.append( $( '<td/>' ).append( $( '<input/>', { type: 'number', 'class': 'rm-narrow', name: 'rm_clutch_temp[]', min: '0', step: '0.1' } ) ) )
			.append( rmClutchCell( 'rm_clutch_hatched[]' ) )
			.append( rmClutchCell( 'rm_clutch_infertile[]' ) )
			.append( rmClutchCell( 'rm_clutch_died[]' ) )
			.append( rmClutchCell( 'rm_clutch_died_day[]' ) )
			.append( $( '<td/>' ).append( $( '<button/>', { type: 'button', 'class': 'button rm-clutch-remove', text: 'Entf.' } ) ) );

		$( '#rm-clutch-table tbody' ).append( row );
		rmRenumberClutches();
	} );

	$( document ).on( 'click', '.rm-clutch-remove', function () {
		$( this ).closest( 'tr' ).remove();
		rmRenumberClutches();
	} );

	// --- Gewichtsverlauf ---------------------------------------------------
	$( '#rm-weight-add' ).on( 'click', function () {
		var row = $( '<tr/>' )
			.append( $( '<td/>' ).append( $( '<input/>', { type: 'date', name: 'rm_weight_date[]' } ) ) )
			.append( $( '<td/>' ).append( $( '<input/>', { type: 'number', name: 'rm_weight_grams[]', step: '1', min: '0' } ) ) )
			.append( $( '<td/>' ).append( $( '<button/>', { type: 'button', 'class': 'button rm-weight-remove', text: 'Entfernen' } ) ) );

		$( '#rm-weight-table tbody' ).append( row );
	} );

	$( document ).on( 'click', '.rm-weight-remove', function () {
		$( this ).closest( 'tr' ).remove();
	} );

	// --- Beitrags-Vorlagen -------------------------------------------------
	function rmIsBlockEditor() {
		return !! ( window.wp && wp.data && wp.data.select && wp.data.select( 'core/editor' ) && wp.blocks );
	}

	function rmCurrentContent() {
		if ( rmIsBlockEditor() ) {
			return wp.data.select( 'core/editor' ).getEditedPostContent() || '';
		}
		if ( window.tinymce && tinymce.get( 'content' ) && ! tinymce.get( 'content' ).isHidden() ) {
			return tinymce.get( 'content' ).getContent() || '';
		}
		return $( '#content' ).val() || '';
	}

	function rmInsertContent( html ) {
		if ( rmIsBlockEditor() ) {
			wp.data.dispatch( 'core/editor' ).resetEditorBlocks( wp.blocks.parse( html ) );
			return;
		}
		if ( window.tinymce && tinymce.get( 'content' ) ) {
			tinymce.get( 'content' ).setContent( html );
		}
		$( '#content' ).val( html );
	}

	function rmCollectTemplateData() {
		var data = {
			action: 'rm_render_template',
			nonce: $( '#rm_template_nonce' ).val(),
			post_id: $( '#post_ID' ).val(),
			template: $( '#rm_template_choice' ).val(),
			title: $( '#title' ).val() || '',
			featured_id: $( '#_thumbnail_id' ).val() || 0,
			rm_sex: $( '#rm_sex' ).val() || '',
			rm_birth: $( '#rm_birth' ).val() || '',
			rm_origin: $( '#rm_origin' ).val() || '',
			rm_acquired: $( '#rm_acquired' ).val() || '',
			rm_identifier: $( '#rm_identifier' ).val() || '',
			rm_length: $( '#rm_length' ).val() || '',
			rm_food_notes: $( '#rm_food_notes' ).val() || '',
			rm_parent_pairing: $( '#rm_parent_pairing' ).val() || '',
			rm_clutch: $( '#rm_clutch' ).val() || '',
			rm_genes: {},
			rm_weight_date: [],
			rm_weight_grams: [],
			rm_gallery_ids: []
		};

		$( 'select[name^="rm_genes["]' ).each( function () {
			var match = $( this ).attr( 'name' ).match( /rm_genes\[([^\]]+)\]/ );
			if ( match ) {
				data.rm_genes[ match[ 1 ] ] = $( this ).val();
			}
		} );

		$( 'input[name="rm_weight_date[]"]' ).each( function () {
			data.rm_weight_date.push( $( this ).val() );
		} );
		$( 'input[name="rm_weight_grams[]"]' ).each( function () {
			data.rm_weight_grams.push( $( this ).val() );
		} );
		$( '#rm-gallery input[name="rm_gallery_ids[]"]' ).each( function () {
			data.rm_gallery_ids.push( $( this ).val() );
		} );

		if ( rmIsBlockEditor() ) {
			var editor = wp.data.select( 'core/editor' );
			data.title = editor.getEditedPostAttribute( 'title' ) || data.title;
			data.featured_id = editor.getEditedPostAttribute( 'featured_media' ) || data.featured_id;
		}

		return data;
	}

	$( document ).on( 'click', '#rm-template-insert', function () {
		var $btn = $( this );
		var $spinner = $btn.parent().find( '.spinner' );

		if ( rmCurrentContent().trim().length && ! window.confirm( rmAdmin.replaceConfirm ) ) {
			return;
		}

		$spinner.addClass( 'is-active' );
		$btn.prop( 'disabled', true );

		$.post( window.ajaxurl, rmCollectTemplateData() )
			.done( function ( response ) {
				if ( response && response.success && response.data && response.data.content ) {
					rmInsertContent( response.data.content );
				} else {
					window.alert( rmAdmin.templateError );
				}
			} )
			.fail( function () {
				window.alert( rmAdmin.templateError );
			} )
			.always( function () {
				$spinner.removeClass( 'is-active' );
				$btn.prop( 'disabled', false );
			} );
	} );
} );
