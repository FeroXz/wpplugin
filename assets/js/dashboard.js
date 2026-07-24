/* global Chart */
/**
 * Reptilien Manager – Frontend-Dashboard-Diagramme (Chart.js).
 * Liest die Daten aus dem JSON-Island #rm-dashboard-data.
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	// Zugängliche, brand-neutrale Kategorie-Palette.
	var PALETTE = [
		'#4f46e5', '#06b6d4', '#f59e0b', '#10b981',
		'#ef4444', '#8b5cf6', '#ec4899', '#64748b'
	];

	function values( rows ) {
		return rows.map( function ( r ) { return r.value; } );
	}
	function labels( rows ) {
		return rows.map( function ( r ) { return r.label; } );
	}
	function colors( rows ) {
		return rows.map( function ( r, i ) { return PALETTE[ i % PALETTE.length ]; } );
	}

	function gridColor() {
		var root = document.documentElement;
		var dark = root.getAttribute( 'data-theme' ) === 'dark'
			|| ( window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches
				&& root.getAttribute( 'data-theme' ) !== 'light' );
		return dark ? 'rgba(148,163,184,0.25)' : 'rgba(15,23,42,0.1)';
	}

	ready( function () {
		var island = document.getElementById( 'rm-dashboard-data' );
		if ( ! island || typeof Chart === 'undefined' ) {
			return;
		}

		var data;
		try {
			data = JSON.parse( island.textContent );
		} catch ( e ) {
			return;
		}

		var grid = gridColor();

		function doughnut( id, rows ) {
			var el = document.getElementById( id );
			if ( ! el || ! rows.length ) {
				return;
			}
			// eslint-disable-next-line no-new
			new Chart( el.getContext( '2d' ), {
				type: 'doughnut',
				data: {
					labels: labels( rows ),
					datasets: [ {
						data: values( rows ),
						backgroundColor: colors( rows ),
						borderWidth: 0
					} ]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { position: 'bottom' } }
				}
			} );
		}

		function bar( id, rows ) {
			var el = document.getElementById( id );
			if ( ! el || ! rows.length ) {
				return;
			}
			// eslint-disable-next-line no-new
			new Chart( el.getContext( '2d' ), {
				type: 'bar',
				data: {
					labels: labels( rows ),
					datasets: [ {
						data: values( rows ),
						backgroundColor: colors( rows ),
						borderRadius: 6
					} ]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false } },
					scales: {
						x: { grid: { display: false } },
						y: { beginAtZero: true, grid: { color: grid }, ticks: { precision: 0 } }
					}
				}
			} );
		}

		doughnut( 'rm-chart-sex', data.sex || [] );
		bar( 'rm-chart-age', data.age || [] );
		bar( 'rm-chart-morph', data.morph || [] );
	} );
}() );
