/**
 * Reptilien Manager – Frontend-Verwaltung ([reptilien-verwaltung]).
 *
 * - lädt die Genetik-Felder nach, wenn die Tierart gewechselt wird
 * - „Alle Tiere“-Schalter im Fütterungs-Formular
 *
 * Bewusst ohne jQuery, damit der Shortcode auf jeder Seite läuft.
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	/**
	 * Genetik-Felder bei Artwechsel neu laden.
	 */
	function initSpeciesSwitch() {
		var select = document.getElementById( 'rm-fe-species' );
		var target = document.getElementById( 'rm-fe-genes' );
		var nonceEl = document.getElementById( 'rm_fe_genes_nonce' );
		var urlEl = document.getElementById( 'rm_fe_ajaxurl' );

		if ( ! select || ! target || ! nonceEl || ! urlEl ) {
			return;
		}

		select.addEventListener( 'change', function () {
			var animalField = document.querySelector( '.rm-fe-form input[name="rm_animal_id"]' );
			var body = new URLSearchParams();

			body.append( 'action', 'rm_fe_genes' );
			body.append( 'nonce', nonceEl.value );
			body.append( 'term_id', select.value );
			body.append( 'animal_id', animalField ? animalField.value : '0' );

			target.classList.add( 'rm-is-loading' );

			window
				.fetch( urlEl.value, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
					},
					body: body.toString()
				} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					if ( result && result.success && result.data && result.data.html ) {
						target.innerHTML = result.data.html;
					}
				} )
				.catch( function () {
					/* Netzwerkfehler: bestehende Felder bleiben stehen. */
				} )
				.then( function () {
					target.classList.remove( 'rm-is-loading' );
				} );
		} );
	}

	/**
	 * „Alle Tiere“-Schalter im Fütterungs-Formular.
	 */
	function initCheckAll() {
		var toggles = document.querySelectorAll( '.rm-fe-form .rm-check-all' );

		Array.prototype.forEach.call( toggles, function ( toggle ) {
			var group = toggle.closest( '.rm-choice-group' );
			if ( ! group ) {
				return;
			}

			var boxes = group.querySelectorAll( '.rm-choice-cb' );

			toggle.addEventListener( 'change', function () {
				Array.prototype.forEach.call( boxes, function ( box ) {
					box.checked = toggle.checked;
				} );
			} );

			Array.prototype.forEach.call( boxes, function ( box ) {
				box.addEventListener( 'change', function () {
					var all = true;
					Array.prototype.forEach.call( boxes, function ( other ) {
						if ( ! other.checked ) {
							all = false;
						}
					} );
					toggle.checked = all;
				} );
			} );
		} );
	}

	/**
	 * Fütterung: Umschaltung zwischen einzelnem Tag und Zeitraum.
	 */
	function initFeedModeSwitch() {
		var radios = document.querySelectorAll( 'input[name="rm_feed_mode"]' );
		if ( ! radios.length ) {
			return;
		}

		function apply() {
			var selected = document.querySelector( 'input[name="rm_feed_mode"]:checked' );
			var mode = selected ? selected.value : 'single';

			Array.prototype.forEach.call(
				document.querySelectorAll( '[data-feed-mode]' ),
				function ( block ) {
					block.hidden = block.getAttribute( 'data-feed-mode' ) !== mode;
				}
			);
		}

		Array.prototype.forEach.call( radios, function ( radio ) {
			radio.addEventListener( 'change', apply );
		} );

		apply();
	}

	ready( function () {
		initSpeciesSwitch();
		initCheckAll();
		initFeedModeSwitch();
	} );
}() );
