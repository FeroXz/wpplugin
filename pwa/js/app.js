/**
 * Reptilien Manager PWA – Haupt-App: State, Routing, Event-Handler.
 *
 * Klassisches Skript (kein ES-Modul). Erwartet, dass offline-queue.js und
 * barcode-scanner.js vorher geladen wurden (RMOfflineQueue / RMBarcodeScanner
 * im globalen Scope) sowie window.RM_PWA_CONFIG (siehe RM_PWA_Shortcode).
 */
( function () {
	'use strict';

	var config = window.RM_PWA_CONFIG || {};
	var pageCache = {}; // fetch()-Cache der HTML-Templates (animals.html usw.).
	var weightChart = null;

	var state = {
		animals: [],
		currentAnimal: null,
		route: { name: 'animals', param: null }
	};

	/* ---------------------------------------------------------------------
	 * Kleine Helfer
	 * ------------------------------------------------------------------ */

	function $( selector, root ) { return ( root || document ).querySelector( selector ); }
	function $$( selector, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( selector ) ); }

	function fetchPage( name ) {
		if ( pageCache[ name ] ) {
			return Promise.resolve( pageCache[ name ] );
		}
		return fetch( config.pagesUrl + name )
			.then( function ( res ) { return res.text(); } )
			.then( function ( html ) {
				pageCache[ name ] = html;
				return html;
			} );
	}

	/**
	 * Ruft die Reptilien-Manager-REST-API auf (X-Reptilien-API-Key-Header).
	 *
	 * @param {string} path   Pfad relativ zu config.apiBase, z. B. "/animals".
	 * @param {Object} [opts] fetch()-Optionen.
	 * @return {Promise<Object>}
	 */
	function api( path, opts ) {
		opts = opts || {};
		var headers = Object.assign(
			{ 'X-Reptilien-API-Key': config.apiKey, 'Content-Type': 'application/json' },
			opts.headers || {}
		);

		return fetch( config.apiBase + path, Object.assign( {}, opts, { headers: headers } ) )
			.then( function ( res ) {
				return res.json().catch( function () { return {}; } ).then( function ( data ) {
					if ( ! res.ok ) {
						var err = new Error( data.message || ( 'HTTP ' + res.status ) );
						err.code = data.code;
						err.status = res.status;
						throw err;
					}
					return data;
				} );
			} );
	}

	function toast( message ) {
		var el = $( '#rm-pwa-toast' );
		if ( ! el ) { return; }
		el.textContent = message;
		el.hidden = false;
		window.clearTimeout( el._rmTimer );
		el._rmTimer = window.setTimeout( function () { el.hidden = true; }, 3200 );
	}

	function showLocalNotification( title, body ) {
		if ( ! ( 'Notification' in window ) || 'granted' !== Notification.permission ) {
			return;
		}
		if ( ! navigator.serviceWorker ) {
			return;
		}
		navigator.serviceWorker.ready.then( function ( reg ) {
			reg.showNotification( title, { body: body, icon: config.icon192, badge: config.icon192 } );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Offline-/Sync-Anzeige
	 * ------------------------------------------------------------------ */

	function updateOnlineBanner() {
		var banner = $( '#rm-pwa-offline-banner' );
		if ( ! banner ) { return; }
		banner.hidden = navigator.onLine;
		banner.textContent = navigator.onLine ? '' : 'Du bist offline – Änderungen werden gespeichert und später synchronisiert.';
	}

	function refreshSyncPill() {
		var countEl = $( '#rm-pwa-sync-count' );
		if ( ! countEl || ! window.RMOfflineQueue ) { return; }
		window.RMOfflineQueue.countPending().then( function ( n ) {
			countEl.textContent = n > 0 ? n + ' ausstehend' : '';
		} );
	}

	var syncing = false;
	var syncBackoff = 5000;
	var droppedItems = [];

	/**
	 * Synchronisiert die Offline-Queue FIFO mit der REST-API. Dauerhaft
	 * ungültige Einträge (4xx) werden verworfen, vorübergehende Fehler
	 * (Netzwerk, 5xx) brechen den Lauf ab und werden mit steigendem Backoff
	 * erneut versucht.
	 */
	function trySync() {
		if ( syncing || ! navigator.onLine || ! window.RMOfflineQueue ) {
			return;
		}
		syncing = true;
		droppedItems = [];
		var btn = $( '#rm-pwa-sync-btn' );
		if ( btn ) { btn.classList.add( 'is-syncing' ); }

		window.RMOfflineQueue.getPendingQueue()
			.then( function ( items ) { return syncNext( items, 0, 0 ); } )
			.then( function ( synced ) {
				syncing = false;
				if ( btn ) { btn.classList.remove( 'is-syncing' ); }
				refreshSyncPill();

				if ( synced > 0 ) {
					toast( synced + ' Eintrag(e) synchronisiert.' );
					showLocalNotification( 'Reptilien Manager', synced + ' Eintrag(e) synchronisiert.' );
					syncBackoff = 5000;
					refreshAnimalsFromNetwork();
				}
				if ( droppedItems.length ) {
					toast( droppedItems.length + ' Eintrag(e) konnten nicht übernommen werden und wurden verworfen.' );
				}
			} )
			.catch( function () {
				syncing = false;
				if ( btn ) { btn.classList.remove( 'is-syncing' ); }
				refreshSyncPill();
				syncBackoff = Math.min( syncBackoff * 2, 5 * 60 * 1000 );
				window.setTimeout( trySync, syncBackoff );
			} );
	}

	function syncNext( items, index, syncedCount ) {
		if ( index >= items.length ) {
			return Promise.resolve( syncedCount );
		}
		var item = items[ index ];

		return dispatchQueueItem( item )
			.then( function () { return window.RMOfflineQueue.markSynced( item.id ); } )
			.then( function () { return syncNext( items, index + 1, syncedCount + 1 ); } )
			.catch( function ( err ) {
				// 4xx heißt: der Eintrag wird auch beim nächsten Versuch
				// scheitern (Tier gelöscht, ungültige Daten). Solche Einträge
				// werden verworfen, damit ein einzelner „vergifteter“ Eintrag
				// nicht dauerhaft die gesamte Warteschlange blockiert.
				// Netzwerk-/Serverfehler (kein Status, 5xx) werden erneut
				// versucht, indem der Fehler nach oben durchgereicht wird.
				if ( err && err.status >= 400 && err.status < 500 ) {
					droppedItems.push( item );
					return window.RMOfflineQueue.markSynced( item.id ).then( function () {
						return syncNext( items, index + 1, syncedCount );
					} );
				}
				throw err;
			} );
	}

	function dispatchQueueItem( item ) {
		switch ( item.action ) {
			case 'create_weight':
				// weight_date erhält das Erfassungsdatum, auch wenn erst
				// Tage später synchronisiert wird.
				return api( '/animals/' + item.animal_id, {
					method: 'PUT',
					body: JSON.stringify( {
						weight: item.payload.weight,
						weight_date: item.payload.date
					} )
				} ).then( function () {
					return item.payload.photo ? uploadPhoto( item.animal_id, item.payload.photo ) : null;
				} );
			case 'update_animal':
				return api( '/animals/' + item.animal_id, {
					method: 'PUT',
					body: JSON.stringify( item.payload )
				} );
			case 'create_feeding':
				return api( '/feedings', {
					method: 'POST',
					body: JSON.stringify( item.payload )
				} );
			default:
				return Promise.resolve();
		}
	}

	/* ---------------------------------------------------------------------
	 * Tiere: Laden, Cache, Rendern
	 * ------------------------------------------------------------------ */

	function loadAnimals() {
		return window.RMOfflineQueue.getAnimalsFromCache()
			.then( function ( cached ) {
				if ( cached && cached.length ) {
					state.animals = cached;
					renderAnimalList();
				}
				return refreshAnimalsFromNetwork();
			} );
	}

	function refreshAnimalsFromNetwork() {
		if ( ! navigator.onLine ) {
			return Promise.resolve();
		}
		return api( '/animals?per_page=100' )
			.then( function ( data ) {
				state.animals = data.animals || [];
				return window.RMOfflineQueue.saveAnimals( state.animals );
			} )
			.then( function () {
				if ( 'animals' === state.route.name ) {
					renderAnimalList();
				}
			} )
			.catch( function () { /* offline: bereits gecachte Liste bleibt sichtbar. */ } );
	}

	function filteredAnimals() {
		var term = ( $( '#rm-pwa-search' ) && $( '#rm-pwa-search' ).value || '' ).toLowerCase().trim();
		if ( ! term ) { return state.animals; }
		return state.animals.filter( function ( a ) {
			return [ a.name, a.species, a.morph ].filter( Boolean ).join( ' ' ).toLowerCase().indexOf( term ) !== -1;
		} );
	}

	function renderAnimalList() {
		var list = $( '#rm-pwa-animal-list' );
		var template = $( '#rm-pwa-animal-card-template' );
		var emptyEl = $( '#rm-pwa-list-empty' );
		if ( ! list || ! template ) { return; }

		var items = filteredAnimals();
		list.innerHTML = '';
		if ( emptyEl ) { emptyEl.hidden = items.length > 0; }

		items.forEach( function ( animal ) {
			var node = template.content.cloneNode( true );
			var card = node.querySelector( '.rm-pwa-card' );
			card.dataset.id = animal.id;
			node.querySelector( '.rm-pwa-card__name' ).textContent = animal.name || '';
			node.querySelector( '.rm-pwa-card__meta' ).textContent = [ animal.species, animal.morph ].filter( Boolean ).join( ' · ' );
			node.querySelector( '.rm-pwa-card__weight' ).textContent = animal.weight ? animal.weight + ' g' : '';
			var status = node.querySelector( '.rm-pwa-card__status' );
			status.textContent = animal.status || '';
			if ( animal.photo ) {
				var img = node.querySelector( '.rm-pwa-card__img' );
				img.src = animal.photo;
				img.hidden = false;
				node.querySelector( '.rm-pwa-card__placeholder' ).hidden = true;
			}
			list.appendChild( node );
		} );
	}

	function findAnimal( id ) {
		id = Number( id );
		var found = state.animals.filter( function ( a ) { return Number( a.id ) === id; } )[ 0 ];
		if ( found ) { return Promise.resolve( found ); }
		return window.RMOfflineQueue.getAnimalFromCache( id );
	}

	/* ---------------------------------------------------------------------
	 * Routing
	 * ------------------------------------------------------------------ */

	function parseHash() {
		var hash = ( window.location.hash || '#/animals' ).replace( /^#\/?/, '' );
		var parts = hash.split( '/' ).filter( Boolean );
		return { name: parts[ 0 ] || 'animals', param: parts[ 1 ] || null };
	}

	function navigate( path ) {
		window.location.hash = '/' + path;
	}

	function route() {
		var r = parseHash();
		state.route = r;
		setActiveNav( 'animals' === r.name ? 'animals' : r.name );

		if ( 'scan' === r.name ) {
			renderScanView();
		} else if ( r.param ) {
			renderDetailView( r.param );
		} else {
			renderAnimalsView();
		}
	}

	function setActiveNav( name ) {
		$$( '.rm-pwa-nav [data-route]' ).forEach( function ( btn ) {
			btn.classList.toggle( 'is-active', btn.dataset.route === name );
		} );
	}

	function renderAnimalsView() {
		fetchPage( 'animals.html' ).then( function ( html ) {
			$( '#rm-pwa-view' ).innerHTML = html;
			renderAnimalList();
			var search = $( '#rm-pwa-search' );
			if ( search ) {
				search.addEventListener( 'input', renderAnimalList );
			}
			bindCardActions();
			refreshAnimalsFromNetwork();
		} );
	}

	function bindCardActions() {
		$( '#rm-pwa-animal-list' ).addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '[data-action]' );
			var card = event.target.closest( '.rm-pwa-card' );
			if ( ! card ) { return; }
			var id = card.dataset.id;

			if ( ! btn ) {
				navigate( 'animals/' + id );
				return;
			}

			event.stopPropagation();
			if ( 'weight' === btn.dataset.action ) { openQuickEntry( 'weight', id ); }
			else if ( 'feed' === btn.dataset.action ) { openQuickEntry( 'feeding', id ); }
			else if ( 'edit' === btn.dataset.action ) { navigate( 'animals/' + id ); }
		} );
	}

	function renderDetailView( id ) {
		fetchPage( 'animal-detail.html' ).then( function ( html ) {
			$( '#rm-pwa-view' ).innerHTML = html;
			return findAnimal( id );
		} ).then( function ( animal ) {
			if ( ! animal ) {
				$( '#rm-pwa-view' ).innerHTML = '<p class="rm-pwa-empty">Tier nicht gefunden (offline evtl. noch nicht geladen).</p>';
				return;
			}
			state.currentAnimal = animal;
			fillDetail( animal );
			bindDetailActions( animal );
			return api( '/animals/' + id ).then( function ( fresh ) {
				state.currentAnimal = fresh;
				fillDetail( fresh );
				return window.RMOfflineQueue.saveAnimal( fresh );
			} ).catch( function () { /* offline: bereits angezeigte Cache-Daten bleiben gültig. */ } );
		} );
	}

	function fillDetail( animal ) {
		var root = $( '.rm-pwa-detail-view' );
		if ( ! root ) { return; }

		root.querySelector( '.rm-pwa-detail__name' ).textContent = animal.name || '';
		root.querySelector( '.rm-pwa-detail__morph' ).textContent = animal.morph || '';
		if ( animal.photo ) {
			var img = root.querySelector( '.rm-pwa-detail__img' );
			img.src = animal.photo;
			img.hidden = false;
			root.querySelector( '.rm-pwa-card__placeholder' ).hidden = true;
		}

		var facts = root.querySelector( '.rm-pwa-facts' );
		facts.innerHTML = '';
		var rows = [
			[ 'Art', animal.species ],
			[ 'Geschlecht', animal.sex ],
			[ 'Schlupf', animal.hatch_date ],
			[ 'Gewicht', animal.weight ? animal.weight + ' g' : '' ],
			[ 'Herkunft', animal.origin ],
			[ 'Status', animal.status ]
		];
		rows.forEach( function ( row ) {
			if ( ! row[ 1 ] ) { return; }
			var dt = document.createElement( 'dt' );
			dt.textContent = row[ 0 ];
			var dd = document.createElement( 'dd' );
			dd.textContent = row[ 1 ];
			facts.appendChild( dt );
			facts.appendChild( dd );
		} );

		renderWeightChart( animal );
	}

	function renderWeightChart( animal ) {
		var canvas = $( '#rm-pwa-weight-chart' );
		var emptyEl = $( '[data-empty-weight]' );
		var history = Array.isArray( animal.weight_history ) ? animal.weight_history : [];

		if ( ! canvas || 'undefined' === typeof Chart || ! history.length ) {
			if ( emptyEl ) { emptyEl.hidden = !! history.length; }
			return;
		}
		if ( emptyEl ) { emptyEl.hidden = true; }

		if ( weightChart ) { weightChart.destroy(); }
		weightChart = new Chart( canvas.getContext( '2d' ), {
			type: 'line',
			data: {
				labels: history.map( function ( row ) { return row.date; } ),
				datasets: [ {
					label: 'Gewicht (g)',
					data: history.map( function ( row ) { return row.grams; } ),
					borderColor: '#4f46e5',
					backgroundColor: 'rgba(79,70,229,0.12)',
					fill: true,
					tension: 0.3
				} ]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: { legend: { display: false } },
				scales: { y: { beginAtZero: true } }
			}
		} );
	}

	/**
	 * Bindet die Ereignisse der Detailansicht. Wird bewusst genau einmal je
	 * gerendertem Template aufgerufen – fillDetail() läuft zweimal (Cache,
	 * dann Server) und würde sonst doppelte Listener anhängen.
	 *
	 * @param {Object} animal Tier-Datensatz.
	 */
	function bindDetailActions( animal ) {
		var root = $( '.rm-pwa-detail-view' );

		$$( '.rm-pwa-tab', root ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				$$( '.rm-pwa-tab', root ).forEach( function ( t ) { t.classList.remove( 'is-active' ); } );
				$$( '.rm-pwa-tab-panel', root ).forEach( function ( p ) { p.classList.remove( 'is-active' ); } );
				tab.classList.add( 'is-active' );
				root.querySelector( '[data-panel="' + tab.dataset.tab + '"]' ).classList.add( 'is-active' );
			} );
		} );

		$$( '[data-action="open-full-site"]', root ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				window.open( config.fullSiteUrl, '_blank', 'noopener' );
			} );
		} );

		$( '[data-action="back"]', root ).addEventListener( 'click', function () { navigate( 'animals' ); } );
		$$( '[data-action="weight"]', root ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { openQuickEntry( 'weight', animal.id ); } );
		} );
		$$( '[data-action="feed"]', root ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { openQuickEntry( 'feeding', animal.id ); } );
		} );
		var editBtn = $( '[data-action="edit"]', root );
		if ( editBtn ) {
			editBtn.addEventListener( 'click', function () { window.open( config.fullSiteUrl, '_blank', 'noopener' ); } );
		}
	}

	/* ---------------------------------------------------------------------
	 * QR-Scan
	 * ------------------------------------------------------------------ */

	function renderScanView() {
		$( '#rm-pwa-view' ).innerHTML =
			'<div class="rm-pwa-scan-view">' +
			'<p class="rm-pwa-muted">Richte die Kamera auf den QR-Code am Terrarium.</p>' +
			'<video class="rm-pwa-scan-video" autoplay playsinline muted></video>' +
			'<p class="rm-pwa-empty" id="rm-pwa-scan-error" hidden></p>' +
			'</div>';

		var video = $( '.rm-pwa-scan-video' );
		if ( ! window.RMBarcodeScanner || ! window.RMBarcodeScanner.isSupported() ) {
			$( '#rm-pwa-scan-error' ).hidden = false;
			$( '#rm-pwa-scan-error' ).textContent = 'Kamera-Zugriff wird von diesem Browser nicht unterstützt.';
			return;
		}

		var handled = false;
		window.RMBarcodeScanner.start(
			video,
			function ( text ) {
				if ( handled ) { return; }
				var id = window.RMBarcodeScanner.extractAnimalId( text );
				if ( id ) {
					handled = true;
					window.RMBarcodeScanner.stop();
					navigate( 'animals/' + id );
				}
			},
			function ( error ) {
				var el = $( '#rm-pwa-scan-error' );
				if ( el ) {
					el.hidden = false;
					el.textContent = error && error.message ? error.message : 'Kamera-Zugriff nicht möglich.';
				}
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Quick-Entry-Modal (Gewicht / Fütterung)
	 * ------------------------------------------------------------------ */

	var capturedPhotoBlob = null;
	var cameraStream = null;

	function openQuickEntry( mode, animalId ) {
		fetchPage( 'quick-entry.html' ).then( function ( html ) {
			var root = $( '#rm-pwa-modal-root' );
			root.innerHTML = html;
			var modal = $( '.rm-pwa-modal', root );
			modal.dataset.mode = mode;

			$( '[data-form="weight"]', root ).hidden = 'weight' !== mode;
			$( '[data-form="feeding"]', root ).hidden = 'feeding' !== mode;

			populateAnimalSelect( root, animalId );
			$$( 'input[name="date"]', root ).forEach( function ( input ) {
				input.value = new Date().toISOString().slice( 0, 10 );
			} );

			if ( 'feeding' === mode ) {
				renderChoiceGroup( $( '[data-choices="foods"]', root ), config.foodTypes || {}, 'foods' );
				renderChoiceGroup( $( '[data-choices="supplements"]', root ), config.supplements || {}, 'supplements' );
			}

			bindModalEvents( root, mode );
		} );
	}

	function renderChoiceGroup( container, options, name ) {
		if ( ! container ) { return; }
		container.innerHTML = '';
		Object.keys( options ).forEach( function ( key ) {
			var label = document.createElement( 'label' );
			var input = document.createElement( 'input' );
			input.type = 'checkbox';
			input.name = name;
			input.value = key;
			label.appendChild( input );
			label.appendChild( document.createTextNode( options[ key ] ) );
			container.appendChild( label );
		} );
	}

	function populateAnimalSelect( root, selectedId ) {
		$$( 'select[name="animal_id"]', root ).forEach( function ( select ) {
			select.innerHTML = '';
			state.animals.forEach( function ( animal ) {
				var option = document.createElement( 'option' );
				option.value = animal.id;
				option.textContent = animal.name;
				if ( String( animal.id ) === String( selectedId ) ) { option.selected = true; }
				select.appendChild( option );
			} );
		} );
	}

	function closeModal() {
		stopCamera();
		$( '#rm-pwa-modal-root' ).innerHTML = '';
	}

	function stopCamera() {
		if ( cameraStream ) {
			cameraStream.getTracks().forEach( function ( t ) { t.stop(); } );
			cameraStream = null;
		}
	}

	function bindModalEvents( root, mode ) {
		$$( '[data-action="close"]', root ).forEach( function ( el ) {
			el.addEventListener( 'click', closeModal );
		} );

		var captureBtn = $( '[data-action="capture-photo"]', root );
		var snapBtn = $( '[data-action="snap-photo"]', root );
		var video = $( '.rm-pwa-camera-preview', root );
		var canvas = $( '.rm-pwa-photo-preview', root );

		if ( captureBtn ) {
			captureBtn.addEventListener( 'click', function () {
				if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
					toast( 'Kamera nicht verfügbar.' );
					return;
				}
				navigator.mediaDevices.getUserMedia( { video: { facingMode: 'environment' } } )
					.then( function ( stream ) {
						cameraStream = stream;
						video.srcObject = stream;
						video.hidden = false;
						snapBtn.hidden = false;
						captureBtn.hidden = true;
					} )
					.catch( function () { toast( 'Kamera-Zugriff verweigert.' ); } );
			} );
		}

		if ( snapBtn ) {
			snapBtn.addEventListener( 'click', function () {
				canvas.width = video.videoWidth;
				canvas.height = video.videoHeight;
				canvas.getContext( '2d' ).drawImage( video, 0, 0 );
				canvas.hidden = false;
				video.hidden = true;
				stopCamera();
				canvas.toBlob( function ( blob ) { capturedPhotoBlob = blob; }, 'image/jpeg', 0.85 );
			} );
		}

		var weightForm = $( 'form[data-form="weight"]', root );
		if ( weightForm ) {
			weightForm.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				handleWeightSubmit( weightForm );
			} );
		}

		var feedingForm = $( 'form[data-form="feeding"]', root );
		if ( feedingForm ) {
			feedingForm.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				handleFeedingSubmit( feedingForm );
			} );
		}
	}

	function handleWeightSubmit( form ) {
		var animalId = Number( $( 'select[name="animal_id"]', form ).value );
		var weight = Number( $( 'input[name="weight"]', form ).value );
		var date = $( 'input[name="date"]', form ).value;
		var photo = capturedPhotoBlob;
		capturedPhotoBlob = null;

		// IndexedDB speichert Blobs mit, sodass ein offline aufgenommenes Foto
		// beim späteren Sync unverändert mitgeschickt wird.
		var proceed = navigator.onLine
			? api( '/animals/' + animalId, {
				method: 'PUT',
				body: JSON.stringify( { weight: weight, weight_date: date } )
			} ).then( function () {
				return photo ? uploadPhoto( animalId, photo ) : null;
			} )
			: window.RMOfflineQueue.enqueue( 'create_weight', animalId, {
				weight: weight,
				date: date,
				photo: photo || null
			} );

		proceed
			.then( function () {
				closeModal();
				toast( navigator.onLine ? 'Gewicht gespeichert!' : 'Gewicht offline gespeichert – wird synchronisiert.' );
				refreshSyncPill();
				if ( navigator.onLine ) {
					refreshAnimalsFromNetwork();
					if ( state.currentAnimal && Number( state.currentAnimal.id ) === animalId ) {
						renderDetailView( animalId );
					}
				} else {
					requestBackgroundSync();
				}
			} )
			.catch( function ( err ) {
				toast( 'Fehler: ' + ( err && err.message ? err.message : 'Speichern fehlgeschlagen.' ) );
			} );
	}

	/**
	 * Lädt ein Foto als Profilbild hoch.
	 *
	 * Nutzt den schmalen REST-Endpunkt /animals/{id}/photo statt des
	 * vollständigen Speicherformulars – letzteres würde alle nicht
	 * mitgesendeten Felder (Genetik, Sichtbarkeit …) zurücksetzen. Zudem
	 * hängt die API-Key-Authentifizierung nicht an einem Nonce, der in einer
	 * lang geöffneten App längst abgelaufen sein kann.
	 *
	 * @param {number} animalId Tier-ID.
	 * @param {Blob}   blob     Bilddaten.
	 * @return {Promise<Response>}
	 */
	function uploadPhoto( animalId, blob ) {
		var data = new FormData();
		data.append( 'photo', blob, 'quick-weight.jpg' );

		return fetch( config.apiBase + '/animals/' + animalId + '/photo', {
			method: 'POST',
			headers: { 'X-Reptilien-API-Key': config.apiKey },
			body: data
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				var err = new Error( 'Foto-Upload fehlgeschlagen (HTTP ' + res.status + ').' );
				// status mitgeben, damit die Sync-Logik dauerhafte (4xx) von
				// vorübergehenden Fehlern unterscheiden kann.
				err.status = res.status;
				throw err;
			}
			return res;
		} );
	}

	function handleFeedingSubmit( form ) {
		var animalId = Number( $( 'select[name="animal_id"]', form ).value );
		var foods = $$( 'input[name="foods"]:checked', form ).map( function ( el ) { return el.value; } );
		var supplements = $$( 'input[name="supplements"]:checked', form ).map( function ( el ) { return el.value; } );
		var payload = {
			animal_ids: [ animalId ],
			foods: foods,
			supplements: supplements,
			amount: $( 'input[name="amount"]', form ).value,
			date: $( 'input[name="date"]', form ).value
		};

		if ( ! foods.length ) {
			toast( 'Bitte mindestens eine Futterart wählen.' );
			return;
		}

		var proceed = navigator.onLine
			? api( '/feedings', { method: 'POST', body: JSON.stringify( payload ) } )
			: window.RMOfflineQueue.enqueue( 'create_feeding', animalId, payload );

		proceed
			.then( function () {
				closeModal();
				toast( navigator.onLine ? 'Fütterung gespeichert!' : 'Fütterung offline gespeichert – wird synchronisiert.' );
				refreshSyncPill();
				if ( ! navigator.onLine ) { requestBackgroundSync(); }
			} )
			.catch( function ( err ) {
				toast( 'Fehler: ' + ( err && err.message ? err.message : 'Speichern fehlgeschlagen.' ) );
			} );
	}

	function requestBackgroundSync() {
		if ( ! navigator.serviceWorker ) { return; }
		navigator.serviceWorker.ready.then( function ( reg ) {
			if ( reg.sync ) {
				reg.sync.register( 'rm-pwa-sync' ).catch( function () {} );
			}
		} );
	}

	/* ---------------------------------------------------------------------
	 * Service Worker + Benachrichtigungen
	 * ------------------------------------------------------------------ */

	function registerServiceWorker() {
		if ( ! ( 'serviceWorker' in navigator ) || ! config.swUrl ) {
			return;
		}
		navigator.serviceWorker.register( config.swUrl, { scope: '/' } ).catch( function () {
			// z. B. iOS Safari in älteren Versionen oder eingeschränkte Umgebungen.
		} );

		navigator.serviceWorker.addEventListener( 'message', function ( event ) {
			if ( event.data && 'sync-requested' === event.data.type ) {
				trySync();
			}
		} );
	}

	function bindNotificationButton() {
		var btn = $( '#rm-pwa-notify-btn' );
		if ( ! btn ) { return; }
		if ( ! ( 'Notification' in window ) ) {
			btn.hidden = true;
			return;
		}
		btn.hidden = 'granted' === Notification.permission;
		btn.addEventListener( 'click', function () {
			Notification.requestPermission().then( function ( permission ) {
				btn.hidden = 'granted' === permission;
				if ( 'granted' === permission ) {
					toast( 'Benachrichtigungen aktiviert.' );
				}
			} );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Start
	 * ------------------------------------------------------------------ */

	function applyShortcutAction() {
		var params = new URLSearchParams( window.location.search );
		var action = params.get( 'rm_pwa_action' );
		if ( ! action ) { return; }

		params.delete( 'rm_pwa_action' );
		var cleanUrl = window.location.pathname + ( params.toString() ? '?' + params.toString() : '' ) + window.location.hash;
		window.history.replaceState( {}, '', cleanUrl );

		if ( 'scan' === action ) {
			navigate( 'scan' );
		} else if ( 'quick-weight' === action ) {
			window.setTimeout( function () { openQuickEntry( 'weight', null ); }, 300 );
		} else if ( 'quick-feeding' === action ) {
			window.setTimeout( function () { openQuickEntry( 'feeding', null ); }, 300 );
		}
	}

	function init() {
		window.addEventListener( 'online', function () { updateOnlineBanner(); trySync(); } );
		window.addEventListener( 'offline', updateOnlineBanner );
		document.addEventListener( 'visibilitychange', function () {
			if ( 'visible' === document.visibilityState ) { trySync(); }
		} );

		$$( '.rm-pwa-nav [data-route]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { navigate( btn.dataset.route ); } );
		} );
		$$( '.rm-pwa-nav [data-modal]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				openQuickEntry( btn.dataset.modal, state.currentAnimal ? state.currentAnimal.id : null );
			} );
		} );

		var syncBtn = $( '#rm-pwa-sync-btn' );
		if ( syncBtn ) { syncBtn.addEventListener( 'click', trySync ); }

		bindNotificationButton();
		updateOnlineBanner();
		registerServiceWorker();

		window.addEventListener( 'hashchange', route );

		if ( window.RMOfflineQueue ) {
			loadAnimals().then( function () {
				route();
				applyShortcutAction();
				refreshSyncPill();
				if ( navigator.onLine ) { trySync(); }
			} );
		} else {
			route();
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
