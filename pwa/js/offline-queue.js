/**
 * Reptilien Manager PWA – IndexedDB-Schicht: Tier-Cache (offline lesbar)
 * und FIFO-Sync-Queue (offline schreibbar).
 *
 * Bewusst als klassisches Skript ohne import/export geschrieben (kein
 * ES-Modul), damit dieselbe Datei sowohl von der Seite (<script src>) als
 * auch vom Service Worker (importScripts()) verwendet werden kann – `self`
 * bezeichnet in beiden Kontexten den globalen Scope.
 */
( function ( global ) {
	'use strict';

	var DB_NAME = 'rm-pwa';
	var DB_VERSION = 1;
	var STORE_ANIMALS = 'animals';
	var STORE_QUEUE = 'queue';

	/** @type {Promise<IDBDatabase>|null} */
	var dbPromise = null;

	function openDB() {
		if ( dbPromise ) {
			return dbPromise;
		}
		if ( ! global.indexedDB ) {
			return Promise.reject( new Error( 'IndexedDB nicht verfügbar' ) );
		}

		dbPromise = new Promise( function ( resolve, reject ) {
			var request = global.indexedDB.open( DB_NAME, DB_VERSION );

			request.onupgradeneeded = function ( event ) {
				var db = event.target.result;

				if ( ! db.objectStoreNames.contains( STORE_ANIMALS ) ) {
					db.createObjectStore( STORE_ANIMALS, { keyPath: 'id' } );
				}
				if ( ! db.objectStoreNames.contains( STORE_QUEUE ) ) {
					var queueStore = db.createObjectStore( STORE_QUEUE, {
						keyPath: 'id',
						autoIncrement: true
					} );
					queueStore.createIndex( 'synced', 'synced', { unique: false } );
					queueStore.createIndex( 'timestamp', 'timestamp', { unique: false } );
				}
			};

			request.onsuccess = function ( event ) { resolve( event.target.result ); };
			request.onerror = function () { reject( request.error ); };
		} );

		return dbPromise;
	}

	/**
	 * Führt eine Transaktion aus und liefert ein Promise, das mit dem
	 * Ergebnis der übergebenen Funktion aufgelöst wird.
	 *
	 * @param {string}   storeName Name des Object Stores.
	 * @param {string}   mode      'readonly' oder 'readwrite'.
	 * @param {Function} fn        (store) => IDBRequest|void.
	 * @return {Promise<*>}
	 */
	function withStore( storeName, mode, fn ) {
		return openDB().then( function ( db ) {
			return new Promise( function ( resolve, reject ) {
				var tx = db.transaction( storeName, mode );
				var store = tx.objectStore( storeName );
				var result;

				try {
					result = fn( store );
				} catch ( err ) {
					reject( err );
					return;
				}

				tx.oncomplete = function () { resolve( result ); };
				tx.onerror = function () { reject( tx.error ); };
				tx.onabort = function () { reject( tx.error || new Error( 'Transaktion abgebrochen' ) ); };
			} );
		} );
	}

	function requestToPromise( request ) {
		return new Promise( function ( resolve, reject ) {
			request.onsuccess = function () { resolve( request.result ); };
			request.onerror = function () { reject( request.error ); };
		} );
	}

	/* ---------------------------------------------------------------------
	 * Tier-Cache (Store "animals": id, name, data)
	 * ------------------------------------------------------------------ */

	/**
	 * Ersetzt den kompletten Tier-Cache durch eine frische Serverliste.
	 *
	 * @param {Array} animals Liste von Tier-Objekten (mind. { id, name }).
	 * @return {Promise<void>}
	 */
	function saveAnimals( animals ) {
		return openDB().then( function ( db ) {
			return new Promise( function ( resolve, reject ) {
				var tx = db.transaction( STORE_ANIMALS, 'readwrite' );
				var store = tx.objectStore( STORE_ANIMALS );

				store.clear();
				( animals || [] ).forEach( function ( animal ) {
					store.put( { id: animal.id, name: animal.name, data: animal } );
				} );

				tx.oncomplete = function () { resolve(); };
				tx.onerror = function () { reject( tx.error ); };
			} );
		} );
	}

	/**
	 * Aktualisiert/ergänzt ein einzelnes Tier im Cache (z. B. nach einem
	 * Detail-Abruf oder einer erfolgreichen Synchronisierung).
	 *
	 * @param {Object} animal Tier-Objekt (mind. { id, name }).
	 * @return {Promise<void>}
	 */
	function saveAnimal( animal ) {
		return withStore( STORE_ANIMALS, 'readwrite', function ( store ) {
			store.put( { id: animal.id, name: animal.name, data: animal } );
		} );
	}

	/**
	 * Alle gecachten Tiere.
	 *
	 * @return {Promise<Array>}
	 */
	function getAnimalsFromCache() {
		return withStore( STORE_ANIMALS, 'readonly', function ( store ) {
			return requestToPromise( store.getAll() );
		} ).then( function ( rows ) {
			return ( rows || [] ).map( function ( row ) { return row.data; } );
		} );
	}

	/**
	 * Ein einzelnes gecachtes Tier.
	 *
	 * @param {number} id Tier-ID.
	 * @return {Promise<Object|null>}
	 */
	function getAnimalFromCache( id ) {
		return withStore( STORE_ANIMALS, 'readonly', function ( store ) {
			return requestToPromise( store.get( Number( id ) ) );
		} ).then( function ( row ) { return row ? row.data : null; } );
	}

	/* ---------------------------------------------------------------------
	 * Sync-Queue (Store "queue": id, action, animal_id, payload, timestamp, synced)
	 * ------------------------------------------------------------------ */

	/**
	 * Reiht eine Aktion zur späteren Synchronisierung ein (FIFO über die
	 * autoinkrementierte ID / den Zeitstempel).
	 *
	 * @param {string} action   z. B. 'create_weight', 'create_feeding', 'update_animal'.
	 * @param {number} animalId Betroffenes Tier (0, falls nicht zutreffend).
	 * @param {Object} payload  Nutzdaten der Aktion.
	 * @return {Promise<number>} ID des Warteschlangen-Eintrags.
	 */
	function enqueue( action, animalId, payload ) {
		return withStore( STORE_QUEUE, 'readwrite', function ( store ) {
			return requestToPromise(
				store.add( {
					action: action,
					animal_id: animalId || 0,
					payload: payload || {},
					timestamp: Date.now(),
					synced: 0
				} )
			);
		} );
	}

	/**
	 * Alle noch nicht synchronisierten Einträge, älteste zuerst.
	 *
	 * @return {Promise<Array>}
	 */
	function getPendingQueue() {
		return withStore( STORE_QUEUE, 'readonly', function ( store ) {
			return requestToPromise( store.getAll() );
		} ).then( function ( rows ) {
			return ( rows || [] )
				.filter( function ( row ) { return ! row.synced; } )
				.sort( function ( a, b ) { return a.timestamp - b.timestamp; } );
		} );
	}

	/**
	 * Anzahl noch ausstehender Einträge (für die Sync-Status-Anzeige).
	 *
	 * @return {Promise<number>}
	 */
	function countPending() {
		return getPendingQueue().then( function ( rows ) { return rows.length; } );
	}

	/**
	 * Markiert einen Warteschlangen-Eintrag als synchronisiert.
	 *
	 * @param {number} id Eintrags-ID.
	 * @return {Promise<void>}
	 */
	function markSynced( id ) {
		return withStore( STORE_QUEUE, 'readwrite', function ( store ) {
			var getRequest = store.get( id );
			getRequest.onsuccess = function () {
				var row = getRequest.result;
				if ( row ) {
					row.synced = 1;
					store.put( row );
				}
			};
		} );
	}

	/**
	 * Entfernt bereits synchronisierte Einträge, die älter als die
	 * angegebene Anzahl Tage sind (einfache Aufräumfunktion).
	 *
	 * @param {number} days Aufbewahrungsdauer in Tagen (Standard 7).
	 * @return {Promise<void>}
	 */
	function pruneSynced( days ) {
		var cutoff = Date.now() - ( days || 7 ) * 24 * 60 * 60 * 1000;
		return withStore( STORE_QUEUE, 'readwrite', function ( store ) {
			var request = store.getAll();
			request.onsuccess = function () {
				( request.result || [] ).forEach( function ( row ) {
					if ( row.synced && row.timestamp < cutoff ) {
						store.delete( row.id );
					}
				} );
			};
		} );
	}

	global.RMOfflineQueue = {
		openDB: openDB,
		saveAnimals: saveAnimals,
		saveAnimal: saveAnimal,
		getAnimalsFromCache: getAnimalsFromCache,
		getAnimalFromCache: getAnimalFromCache,
		enqueue: enqueue,
		getPendingQueue: getPendingQueue,
		countPending: countPending,
		markSynced: markSynced,
		pruneSynced: pruneSynced
	};
}( typeof self !== 'undefined' ? self : this ) );
