/**
 * Reptilien Manager PWA – QR-Code-Scanner über die Kamera (getUserMedia)
 * und jsQR (per CDN nachgeladen, ~10 KB, nur wenn der Scanner geöffnet wird).
 *
 * Klassisches Skript (kein ES-Modul), Namespace unter self.RMBarcodeScanner.
 */
( function ( global ) {
	'use strict';

	var DEFAULT_JSQR_SRC = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';

	var stream = null;
	var rafId = null;
	var canvas = null;
	var ctx = null;

	function loadJsQr() {
		if ( 'function' === typeof global.jsQR ) {
			return Promise.resolve();
		}
		return new Promise( function ( resolve, reject ) {
			var src = ( global.RM_PWA_CONFIG && global.RM_PWA_CONFIG.jsQrSrc ) || DEFAULT_JSQR_SRC;
			var script = document.createElement( 'script' );
			script.src = src;
			script.async = true;
			script.onload = function () { resolve(); };
			script.onerror = function () { reject( new Error( 'jsQR konnte nicht geladen werden.' ) ); };
			document.head.appendChild( script );
		} );
	}

	function isSupported() {
		return !! ( navigator.mediaDevices && navigator.mediaDevices.getUserMedia );
	}

	/**
	 * Startet den Kamera-Scan. Ruft onDetect(text) beim ersten Treffer auf
	 * (die Erkennungsschleife läuft danach weiter, bis stop() gerufen wird).
	 *
	 * @param {HTMLVideoElement} videoEl  Video-Element für den Kamera-Stream.
	 * @param {Function}         onDetect (text) => void.
	 * @param {Function}         [onError] (error) => void.
	 * @return {Promise<void>}
	 */
	function start( videoEl, onDetect, onError ) {
		if ( ! isSupported() ) {
			var err = new Error( 'Kamera-Zugriff wird von diesem Browser nicht unterstützt.' );
			if ( onError ) { onError( err ); }
			return Promise.reject( err );
		}

		return loadJsQr()
			.then( function () {
				return navigator.mediaDevices.getUserMedia( {
					video: { facingMode: 'environment' }
				} );
			} )
			.then( function ( mediaStream ) {
				stream = mediaStream;
				videoEl.srcObject = stream;
				videoEl.setAttribute( 'playsinline', 'true' ); // iOS: Inline statt Vollbild.
				return videoEl.play();
			} )
			.then( function () {
				canvas = document.createElement( 'canvas' );
				ctx = canvas.getContext( '2d', { willReadFrequently: true } );
				scanLoop( videoEl, onDetect );
			} )
			.catch( function ( error ) {
				if ( onError ) { onError( error ); }
				throw error;
			} );
	}

	function scanLoop( videoEl, onDetect ) {
		if ( ! stream ) {
			return;
		}

		if ( videoEl.readyState === videoEl.HAVE_ENOUGH_DATA ) {
			canvas.width = videoEl.videoWidth;
			canvas.height = videoEl.videoHeight;
			ctx.drawImage( videoEl, 0, 0, canvas.width, canvas.height );

			var imageData = ctx.getImageData( 0, 0, canvas.width, canvas.height );
			var code = global.jsQR( imageData.data, imageData.width, imageData.height, {
				inversionAttempts: 'dontInvert'
			} );

			if ( code && code.data ) {
				onDetect( code.data );
			}
		}

		rafId = global.requestAnimationFrame( function () { scanLoop( videoEl, onDetect ); } );
	}

	/**
	 * Stoppt Kamera-Stream und Erkennungsschleife.
	 */
	function stop() {
		if ( rafId ) {
			global.cancelAnimationFrame( rafId );
			rafId = null;
		}
		if ( stream ) {
			stream.getTracks().forEach( function ( track ) { track.stop(); } );
			stream = null;
		}
	}

	/**
	 * Extrahiert eine Tier-ID aus dem gescannten Text: sowohl eine reine
	 * Zahl ("123") als auch eine URL mit ID am Ende ("…/tier/123",
	 * "…/animals/123/") werden erkannt.
	 *
	 * @param {string} text Gescannter QR-Code-Inhalt.
	 * @return {number|null}
	 */
	function extractAnimalId( text ) {
		if ( ! text ) {
			return null;
		}
		var match = String( text ).match( /(\d+)\/?$/ );
		return match ? parseInt( match[ 1 ], 10 ) : null;
	}

	global.RMBarcodeScanner = {
		isSupported: isSupported,
		start: start,
		stop: stop,
		extractAnimalId: extractAnimalId
	};
}( typeof self !== 'undefined' ? self : this ) );
