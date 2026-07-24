/**
 * Reptilien Manager – „Tierart“-Panel für den Block-Editor (Gutenberg).
 *
 * Klassische Meta-Boxen landen in Gutenberg ganz unten unter dem Inhalt und
 * werden dort leicht übersehen. Dieses Panel zeigt die Art-Auswahl zusätzlich
 * in der Dokument-Seitenleiste – dieselbe Taxonomie, dieselben Begriffe.
 *
 * Ohne Build-Schritt geschrieben (createElement statt JSX).
 */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data ) {
		return;
	}

	// PluginDocumentSettingPanel ist seit WP 6.6 in wp.editor, davor in wp.editPost.
	var panelSource = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) ? wp.editor : wp.editPost;

	if ( ! panelSource || ! panelSource.PluginDocumentSettingPanel ) {
		return;
	}

	var el = wp.element.createElement;
	var PluginDocumentSettingPanel = panelSource.PluginDocumentSettingPanel;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var SelectControl = wp.components.SelectControl;

	var terms = ( data && data.terms ) || [];

	/**
	 * Klassisches Auswahlfeld der Stammdaten-Box mitziehen.
	 *
	 * Gutenberg speichert die Meta-Boxen in einem zweiten Request nach dem
	 * REST-Save. Bliebe das Feld dort auf dem alten Wert stehen, würde es die
	 * im Panel gewählte Art wieder überschreiben. Das `change`-Event stößt
	 * zugleich das Nachladen der artspezifischen Genetik-Felder an.
	 *
	 * @param {string} termId Gewählte Begriffs-ID.
	 */
	function syncClassicField( termId ) {
		var select = document.getElementById( 'rm_species' );

		if ( ! select || select.value === termId ) {
			return;
		}

		select.value = termId;
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function SpeciesPanel() {
		var postType = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );

		var selected = useSelect( function ( select ) {
			var value = select( 'core/editor' ).getEditedPostAttribute( 'rm_species' );
			return ( value && value.length ) ? String( value[ 0 ] ) : '';
		}, [] );

		var editPost = useDispatch( 'core/editor' ).editPost;

		if ( 'rm_animal' !== postType || ! terms.length ) {
			return null;
		}

		var options = terms.map( function ( term ) {
			return { label: term.name, value: String( term.id ) };
		} );

		if ( ! selected ) {
			options.unshift( { label: data.chooseLabel, value: '' } );
		}

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'rm-species-panel',
				title: data.panelTitle,
				className: 'rm-species-panel'
			},
			el( SelectControl, {
				label: data.fieldLabel,
				value: selected,
				options: options,
				onChange: function ( value ) {
					editPost( { rm_species: value ? [ parseInt( value, 10 ) ] : [] } );
					syncClassicField( value );
				},
				help: data.help
			} )
		);
	}

	wp.plugins.registerPlugin( 'rm-species-panel', { render: SpeciesPanel } );

	// Gegenrichtung: Wahl im klassischen Feld ins Panel bzw. in den Editor-Store
	// übernehmen (aktiviert zugleich den „Aktualisieren“-Button).
	document.addEventListener( 'change', function ( event ) {
		// isTrusted === false ⇒ von syncClassicField() ausgelöst, keine Schleife.
		if ( ! event.target || 'rm_species' !== event.target.id || ! event.isTrusted ) {
			return;
		}

		var id = parseInt( event.target.value, 10 );
		wp.data.dispatch( 'core/editor' ).editPost( { rm_species: id ? [ id ] : [] } );
	} );
}( window.wp, window.rmEditorSpecies ) );
