/**
 * Adds an "SMS promotions" column to the WooCommerce > Customers report: whether the
 * customer agreed to promotional SMS, and when.
 */
( function () {
	'use strict';

	if ( ! window.wp || ! window.wp.hooks || ! window.povikvaneCustomers ) {
		return;
	}

	var data = window.povikvaneCustomers;

	window.wp.hooks.addFilter( 'woocommerce_admin_report_table', 'povikvane/customers', function ( table ) {
		if ( ! table || 'customers' !== table.endpoint || ! table.items || ! Array.isArray( table.items.data ) ) {
			return table;
		}

		var headers = table.headers.concat( [ { label: data.i18n.label, key: 'povikvane_consent', isSortable: false, required: false } ] );
		var rows = table.rows.map( function ( row, index ) {
			var customer = table.items.data[ index ] || {};
			var when = ( customer.user_id && data.users[ customer.user_id ] ) || ( customer.email && data.emails[ String( customer.email ).toLowerCase() ] ) || '';
			var text = when ? data.i18n.yes + ' ' + when : '—';

			return row.concat( [ { display: text, value: text } ] );
		} );

		return Object.assign( {}, table, { headers: headers, rows: rows } );
	} );
}() );
