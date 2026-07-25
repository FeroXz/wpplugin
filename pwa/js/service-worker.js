/**
 * Reptilien Manager PWA – Service Worker.
 *
 * Strategien:
 * - Statische Assets (CSS/JS/HTML-Templates/Icons/Manifest): Cache-First,
 *   im Hintergrund aktualisiert ("stale-while-revalidate light").
 * - REST-API (GET /wp-json/reptilien/v1/*): Network-First; schlägt die
 *   Netzwerkanfrage fehl, wird für Tier-Listen/-Details aus dem
 *   IndexedDB-Cache (siehe offline-queue.js) geantwortet.
 * - Navigation, die offline fehlschlägt: einfache Offline-Seite inline.
 *
 * __RM_PWA_PRECACHE__ und __RM_PWA_VERSION__ werden serverseitig beim
 * Ausliefern dieser Datei ersetzt (siehe RM_PWA_Assets::serve_service_worker()),
 * da die absoluten Asset-URLs vom jeweiligen WordPress-Installationspfad
 * abhängen.
 */

importScripts( '__RM_PWA_QUEUE_URL__' );

var RM_PWA_VERSION = '__RM_PWA_VERSION__';
var STATIC_CACHE = 'rm-pwa-static-' + RM_PWA_VERSION;
var PRECACHE_URLS = __RM_PWA_PRECACHE__;
var API_MARKER = '__RM_PWA_API_MARKER__'; // z. B. "/wp-json/reptilien/v1/"
var ASSET_PREFIX = '__RM_PWA_ASSET_PREFIX__'; // Pfad des pwa/-Verzeichnisses.

self.addEventListener( 'install', function ( event ) {
	event.waitUntil(
		caches.open( STATIC_CACHE )
			.then( function ( cache ) {
				return cache.addAll( PRECACHE_URLS.map( function ( url ) {
					return new Request( url, { cache: 'reload' } );
				} ) ).catch( function () {
					// Einzelne fehlende Assets sollen die Installation nicht
					// komplett scheitern lassen (z. B. bei Cache-Busting-Konflikten).
					return Promise.all(
						PRECACHE_URLS.map( function ( url ) {
							return cache.add( new Request( url, { cache: 'reload' } ) ).catch( function () {} );
						} )
					);
				} );
			} )
			.then( function () { return self.skipWaiting(); } )
	);
} );

self.addEventListener( 'activate', function ( event ) {
	event.waitUntil(
		caches.keys()
			.then( function ( keys ) {
				return Promise.all(
					keys
						.filter( function ( key ) { return key.indexOf( 'rm-pwa-' ) === 0 && key !== STATIC_CACHE; } )
						.map( function ( key ) { return caches.delete( key ); } )
				);
			} )
			.then( function () { return self.clients.claim(); } )
	);
} );

self.addEventListener( 'fetch', function ( event ) {
	var request = event.request;

	// Nur GET wird von diesem Service Worker behandelt; alles andere
	// (POST/PUT/DELETE) geht unverändert ans Netzwerk – Schreibaktionen
	// werden bereits app-seitig in die Offline-Queue gelegt, bevor sie
	// überhaupt versucht werden.
	if ( 'GET' !== request.method ) {
		return;
	}

	var url = new URL( request.url );
	var restRoute = url.searchParams.get( 'rest_route' ) || '';

	// Erkennt sowohl hübsche Permalinks (/wp-json/reptilien/v1/…) als auch
	// die Klartext-Variante (?rest_route=/reptilien/v1/…) ohne Rewrite-Regeln.
	if ( -1 !== url.pathname.indexOf( API_MARKER ) || 0 === restRoute.indexOf( '/reptilien/v1' ) ) {
		event.respondWith( networkFirstApi( request, url ) );
		return;
	}

	if ( isCacheableAsset( url ) ) {
		event.respondWith( cacheFirstAsset( request ) );
	}

	// Alles Übrige (normale Seiten, wp-admin, wp-login, fremde Hosts) bleibt
	// bewusst unangetastet und geht direkt ans Netzwerk – sonst würden
	// Cache-First-Antworten dauerhaft veraltete Seiten ausliefern.
} );

/**
 * Nur eigene Plugin-Assets mit statischer Dateiendung werden gecacht.
 *
 * @param {URL} url Angefragte URL.
 * @return {boolean}
 */
function isCacheableAsset( url ) {
	if ( url.origin !== self.location.origin ) {
		return false;
	}
	if ( 0 !== url.pathname.indexOf( ASSET_PREFIX ) ) {
		return false;
	}
	return /\.(css|js|html|png|jpg|jpeg|svg|webp|woff2?)$/i.test( url.pathname );
}

/**
 * Cache-First für statische Assets: liefert den Cache-Treffer sofort und
 * aktualisiert den Cache im Hintergrund (stale-while-revalidate).
 *
 * @param {Request} request Anfrage.
 * @return {Promise<Response>}
 */
function cacheFirstAsset( request ) {
	return caches.match( request ).then( function ( cached ) {
		var network = fetch( request )
			.then( function ( response ) {
				if ( response && response.ok ) {
					var clone = response.clone();
					caches.open( STATIC_CACHE ).then( function ( cache ) { cache.put( request, clone ); } );
				}
				return response;
			} )
			.catch( function () { return cached; } );

		return cached || network;
	} );
}

/**
 * Network-First für die REST-API: schlägt das Netzwerk fehl, wird bei
 * Tier-Listen/-Details aus dem IndexedDB-Cache geantwortet.
 *
 * @param {Request} request Anfrage.
 * @param {URL}     url     Geparste URL.
 * @return {Promise<Response>}
 */
function networkFirstApi( request, url ) {
	return fetch( request ).catch( function () {
		return offlineApiFallback( url );
	} );
}

/**
 * Erzeugt aus dem IndexedDB-Tier-Cache eine Antwort, die dieselbe Form wie
 * die echten Endpunkte hat, sofern möglich.
 *
 * @param {URL} url Angefragte URL.
 * @return {Promise<Response>}
 */
function offlineApiFallback( url ) {
	var jsonHeaders = {
		'Content-Type': 'application/json',
		'X-RM-PWA-Source': 'offline-cache'
	};

	// Effektiver Pfad: entweder das hübsche Permalink selbst oder der Wert
	// des ?rest_route=-Parameters bei deaktivierten Permalinks.
	var effectivePath = url.searchParams.get( 'rest_route' ) || url.pathname;

	var match = effectivePath.match( /\/animals\/(\d+)\/?$/ );
	if ( match ) {
		return self.RMOfflineQueue.getAnimalFromCache( parseInt( match[ 1 ], 10 ) ).then( function ( animal ) {
			if ( ! animal ) {
				return jsonResponse( { code: 'rm_offline', message: 'Offline: Tier nicht im lokalen Cache.', data: { status: 404 } }, 404, jsonHeaders );
			}
			return jsonResponse( animal, 200, jsonHeaders );
		} );
	}

	if ( -1 !== effectivePath.indexOf( '/animals' ) ) {
		return self.RMOfflineQueue.getAnimalsFromCache().then( function ( animals ) {
			return jsonResponse(
				{ animals: animals, total: animals.length, pages: 1 },
				200,
				jsonHeaders
			);
		} );
	}

	return Promise.resolve(
		jsonResponse( { code: 'rm_offline', message: 'Offline: Diese Daten sind ohne Verbindung nicht verfügbar.', data: { status: 503 } }, 503, jsonHeaders )
	);
}

function jsonResponse( data, status, headers ) {
	return new Response( JSON.stringify( data ), { status: status, headers: headers } );
}

/* ---------------------------------------------------------------------
 * Nachrichten von der App (z. B. "clear-cache", "sync-now")
 * ------------------------------------------------------------------ */
self.addEventListener( 'message', function ( event ) {
	var data = event.data || {};

	if ( 'clear-cache' === data.type ) {
		event.waitUntil(
			caches.delete( STATIC_CACHE ).then( function () {
				return self.clients.matchAll();
			} ).then( function ( clients ) {
				clients.forEach( function ( client ) { client.postMessage( { type: 'cache-cleared' } ); } );
			} )
		);
	}

	if ( 'sync-now' === data.type ) {
		// Die eigentliche Synchronisierung läuft app-seitig (offline-queue.js
		// liest/schreibt IndexedDB im Seiten-Kontext); der Service Worker
		// bestätigt hier nur den Empfang, damit die Seite bei Bedarf reagiert.
		self.clients.matchAll().then( function ( clients ) {
			clients.forEach( function ( client ) { client.postMessage( { type: 'sync-requested' } ); } );
		} );
	}
} );

/* ---------------------------------------------------------------------
 * Background Sync (Chrome/Android; iOS Safari unterstützt dies nicht –
 * dort synchronisiert app.js manuell bei 'online'/Sichtbarkeitswechsel).
 * ------------------------------------------------------------------ */
self.addEventListener( 'sync', function ( event ) {
	if ( 'rm-pwa-sync' === event.tag ) {
		event.waitUntil(
			self.clients.matchAll().then( function ( clients ) {
				clients.forEach( function ( client ) { client.postMessage( { type: 'sync-requested' } ); } );
			} )
		);
	}
} );

/* ---------------------------------------------------------------------
 * Push (Grundgerüst für spätere echte Push-Zustellung; ohne eigenen
 * Push-Server zeigt dies aktuell nur lokal ausgelöste Benachrichtigungen –
 * siehe app.js showLocalNotification()).
 * ------------------------------------------------------------------ */
self.addEventListener( 'push', function ( event ) {
	var payload = { title: 'Reptilien Manager', body: 'Neue Aktivität.' };
	if ( event.data ) {
		try {
			payload = event.data.json();
		} catch ( e ) {
			payload.body = event.data.text();
		}
	}

	event.waitUntil(
		self.registration.showNotification( payload.title || 'Reptilien Manager', {
			body: payload.body || '',
			icon: '__RM_PWA_ICON_192__',
			badge: '__RM_PWA_ICON_192__'
		} )
	);
} );

self.addEventListener( 'notificationclick', function ( event ) {
	event.notification.close();
	event.waitUntil(
		self.clients.matchAll( { type: 'window' } ).then( function ( clients ) {
			for ( var i = 0; i < clients.length; i++ ) {
				if ( 'focus' in clients[ i ] ) {
					return clients[ i ].focus();
				}
			}
			if ( self.clients.openWindow ) {
				return self.clients.openWindow( '__RM_PWA_START_URL__' );
			}
		} )
	);
} );
