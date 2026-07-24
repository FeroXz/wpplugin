/* global Chart */
/**
 * Reptilien Manager – Gewichtsverlauf-Graphik (Chart.js).
 * Liest die Daten aus dem JSON-Island #rm-weight-chart-data.
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

	function cssVar( name, fallback ) {
		var v = getComputedStyle( document.documentElement ).getPropertyValue( name );
		return ( v && v.trim() ) || fallback;
	}

	var STATUS_COLORS = {
		low: '#b45309',
		normal: '#15803d',
		high: '#b91c1c',
		unknown: '#64748b'
	};

	var STATUS_TEXT = {
		low: 'untergewichtig',
		normal: 'normal',
		high: 'übergewichtig',
		unknown: 'ohne Referenz'
	};

	ready( function () {
		var canvas = document.getElementById( 'rm-weight-chart' );
		var island = document.getElementById( 'rm-weight-chart-data' );

		if ( ! canvas || ! island || typeof Chart === 'undefined' ) {
			return;
		}

		var data;
		try {
			data = JSON.parse( island.textContent );
		} catch ( e ) {
			return;
		}

		var hasAge = data.has_age && data.points.some( function ( p ) {
			return p.x !== null;
		} );

		var primary = cssVar( '--color-primary', '#4f46e5' );
		var accent = cssVar( '--color-accent', '#06b6d4' );
		var gridColor = 'rgba(148,163,184,0.25)';

		var actualPoints = data.points.map( function ( p, i ) {
			return {
				x: hasAge ? p.x : i,
				y: p.y,
				date: p.date,
				status: p.status,
				expected: p.expected
			};
		} );

		var pointColors = data.points.map( function ( p ) {
			return STATUS_COLORS[ p.status ] || STATUS_COLORS.unknown;
		} );

		var datasets = [ {
			label: 'Gewicht (g)',
			data: actualPoints,
			borderColor: primary,
			backgroundColor: primary,
			pointBackgroundColor: pointColors,
			pointBorderColor: pointColors,
			pointRadius: 5,
			pointHoverRadius: 7,
			tension: 0.25,
			fill: false,
			order: 1
		} ];

		if ( hasAge && data.expected && data.expected.length ) {
			datasets.push( {
				label: 'Erwartet (Referenz)',
				data: data.expected.map( function ( e ) {
					return { x: e.x, y: e.y };
				} ),
				borderColor: accent,
				backgroundColor: accent,
				borderDash: [ 6, 4 ],
				pointRadius: 0,
				tension: 0.35,
				fill: false,
				order: 2
			} );
		}

		var xScale = hasAge
			? {
				type: 'linear',
				title: { display: true, text: 'Alter (Monate)' },
				grid: { color: gridColor },
				ticks: { precision: 0 }
			}
			: {
				type: 'category',
				labels: data.points.map( function ( p ) {
					return p.date;
				} ),
				title: { display: true, text: 'Messung' },
				grid: { color: gridColor }
			};

		// eslint-disable-next-line no-new
		new Chart( canvas.getContext( '2d' ), {
			type: 'line',
			data: { datasets: datasets },
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: { mode: 'nearest', intersect: false },
				scales: {
					x: xScale,
					y: {
						title: { display: true, text: 'Gewicht (g)' },
						grid: { color: gridColor },
						beginAtZero: true
					}
				},
				plugins: {
					legend: { display: true },
					tooltip: {
						callbacks: {
							title: function ( items ) {
								var raw = items[ 0 ].raw;
								return raw && raw.date ? raw.date : '';
							},
							label: function ( ctx ) {
								var raw = ctx.raw || {};
								if ( ctx.dataset.label.indexOf( 'Erwartet' ) === 0 ) {
									return 'Erwartet: ' + ctx.parsed.y + ' g';
								}
								var lines = [ 'Gewicht: ' + ctx.parsed.y + ' g' ];
								if ( raw.expected ) {
									lines.push( 'Erwartet: ' + raw.expected + ' g' );
								}
								if ( raw.status ) {
									lines.push( 'Status: ' + ( STATUS_TEXT[ raw.status ] || raw.status ) );
								}
								return lines;
							}
						}
					}
				}
			}
		} );
	} );
}() );
