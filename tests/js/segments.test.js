'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { analyse } = require( '../../assets/js/segments.js' );

const cases = JSON.parse( fs.readFileSync( path.join( __dirname, '../fixtures/segment-cases.json' ), 'utf8' ) );

for ( const c of cases ) {
	test( 'segment counter: ' + c.name, () => {
		const text = undefined !== c.repeat ? c.repeat.repeat( c.times ) : c.text;
		const result = analyse( text );

		assert.equal( result.encoding, c.encoding );
		assert.equal( result.units, c.units );
		assert.equal( result.segments, c.segments );
	} );
}
