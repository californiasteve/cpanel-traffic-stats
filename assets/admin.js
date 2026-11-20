/* cPanel Traffic Stats admin behaviour (no dependencies). */
( function () {
	'use strict';

	// Month picker submits on change.
	document.querySelectorAll( '.cts-autosubmit' ).forEach( function ( el ) {
		el.addEventListener( 'change', function () {
			el.form.submit();
		} );
	} );

	// Chart tabs.
	document.querySelectorAll( '[data-cts-tabs]' ).forEach( function ( tabs ) {
		var buttons = tabs.querySelectorAll( '[data-cts-tab]' );
		buttons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				buttons.forEach( function ( b ) {
					var panel = document.getElementById( b.getAttribute( 'data-cts-tab' ) );
					var active = b === btn;
					b.classList.toggle( 'is-active', active );
					b.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
					if ( panel ) {
						panel.hidden = ! active;
					}
				} );
			} );
		} );
	} );

	// "Show more" rows in tables.
	document.querySelectorAll( '.cts-show-more' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var panel = btn.closest( '.cts-table-panel' );
			panel.querySelectorAll( '.cts-more' ).forEach( function ( row ) {
				row.hidden = false;
			} );
			btn.parentNode.remove();
		} );
	} );

	// Settings: show only the fields for the chosen source.
	var sourceRadios = document.querySelectorAll( 'input[name="cts_settings[source]"]' );
	if ( sourceRadios.length ) {
		var toggle = function () {
			var checked = document.querySelector( 'input[name="cts_settings[source]"]:checked' );
			var source = checked ? checked.value : 'local';
			document.querySelectorAll( '.cts-local' ).forEach( function ( row ) {
				row.hidden = source !== 'local';
			} );
			document.querySelectorAll( '.cts-cpanel' ).forEach( function ( row ) {
				row.hidden = source !== 'cpanel';
			} );
		};
		sourceRadios.forEach( function ( r ) {
			r.addEventListener( 'change', toggle );
		} );
		toggle();
	}

	// Export: show date fields for a custom range.
	document.querySelectorAll( '[data-cts-export-period]' ).forEach( function ( sel ) {
		var dates = sel.form.querySelector( '.cts-custom-dates' );
		var sync = function () {
			dates.hidden = sel.value !== 'custom';
		};
		sel.addEventListener( 'change', sync );
		sync();
	} );

	// Email report form: show the options for the chosen frequency, and
	// suggest a matching period until the person picks one themselves.
	document.querySelectorAll( '[data-cts-frequency]' ).forEach( function ( freq ) {
		var form = freq.form;
		var period = form.querySelector( '[data-cts-period]' );
		var touched = false;
		var suggest = { daily: 'yesterday', weekly: 'last_7_days', monthly_day: 'last_month', monthly_nth: 'last_month' };
		if ( period ) {
			period.addEventListener( 'change', function () {
				touched = true;
			} );
		}
		var sync = function ( fromChange ) {
			form.querySelectorAll( '[data-cts-for]' ).forEach( function ( el ) {
				el.hidden = el.getAttribute( 'data-cts-for' ) !== freq.value;
			} );
			if ( fromChange && period && ! touched && suggest[ freq.value ] ) {
				period.value = suggest[ freq.value ];
			}
		};
		freq.addEventListener( 'change', function () {
			sync( true );
		} );
		sync( false );
	} );

	// Confirm before deleting.
	document.querySelectorAll( 'form.cts-confirm' ).forEach( function ( f ) {
		f.addEventListener( 'submit', function ( e ) {
			if ( ! window.confirm( f.getAttribute( 'data-confirm' ) ) ) {
				e.preventDefault();
			}
		} );
	} );
} )();
