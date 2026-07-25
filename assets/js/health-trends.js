/* global Chart */
/**
 * Reptilien Manager – Gesundheits-Trends (Chart.js).
 * Liest die Daten aus dem JSON-Island #rm-health-trends-data.
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

	ready( function () {
		var island = document.getElementById( 'rm-health-trends-data' );
		if ( ! island || typeof Chart === 'undefined' ) {
			return;
		}

		var data;
		try {
			data = JSON.parse( island.textContent );
		} catch ( e ) {
			return;
		}

		var symptoms = data.symptoms || [];
		var symptomsEl = document.getElementById( 'rm-health-chart-symptoms' );
		if ( symptomsEl && symptoms.length ) {
			// eslint-disable-next-line no-new
			new Chart( symptomsEl.getContext( '2d' ), {
				type: 'bar',
				data: {
					labels: labels( symptoms ),
					datasets: [ {
						data: values( symptoms ),
						backgroundColor: colors( symptoms ),
						borderRadius: 6
					} ]
				},
				options: {
					indexAxis: 'y',
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false } },
					scales: {
						x: { beginAtZero: true, ticks: { precision: 0 } }
					}
				}
			} );
		}

		var timeline = data.timeline || [];
		var timelineEl = document.getElementById( 'rm-health-chart-timeline' );
		if ( timelineEl && timeline.length ) {
			// eslint-disable-next-line no-new
			new Chart( timelineEl.getContext( '2d' ), {
				type: 'line',
				data: {
					labels: labels( timeline ),
					datasets: [ {
						label: '',
						data: values( timeline ),
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
					scales: {
						y: { beginAtZero: true, ticks: { precision: 0 } }
					}
				}
			} );
		}
	} );
}() );
