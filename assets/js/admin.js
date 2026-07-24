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
} );
