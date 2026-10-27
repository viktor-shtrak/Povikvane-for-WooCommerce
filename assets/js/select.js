/**
 * Branded drop-downs for the Povikvane screens.
 *
 * Every plain <select> inside .pvk is replaced by a button and a list that match the
 * design. The original <select> stays in the page (hidden) and is kept in step, so
 * forms submit exactly as before, and without JavaScript the native control is used.
 * Opt out with data-pvk-native on a <select>.
 *
 * Also shows or hides blocks marked data-pvk-visible-if="selectId=value".
 */
( function () {
	'use strict';

	var uid = 0;
	var open = null;

	/**
	 * Build the enhanced control for one <select>.
	 *
	 * @param {HTMLSelectElement} select Native select.
	 */
	function enhance( select ) {
		var id = ++uid;
		var wrapper = document.createElement( 'div' );
		var button = document.createElement( 'button' );
		var label = document.createElement( 'span' );
		var list = document.createElement( 'div' );
		var options = [];
		var active = -1;
		var typed = '';
		var typedTimer = null;

		wrapper.className = 'pvk-select';
		button.type = 'button';
		button.className = 'pvk-select__button';
		button.id = 'pvk-select-btn-' + id;
		button.setAttribute( 'role', 'combobox' );
		button.setAttribute( 'aria-haspopup', 'listbox' );
		button.setAttribute( 'aria-expanded', 'false' );
		button.setAttribute( 'aria-controls', 'pvk-select-list-' + id );
		label.className = 'pvk-select__label';
		button.appendChild( label );

		list.className = 'pvk-select__list';
		list.id = 'pvk-select-list-' + id;
		list.setAttribute( 'role', 'listbox' );
		list.hidden = true;

		select.parentNode.insertBefore( wrapper, select );
		wrapper.appendChild( select );
		wrapper.appendChild( button );
		wrapper.appendChild( list );
		select.classList.add( 'pvk-select__native' );
		select.tabIndex = -1;
		select.setAttribute( 'aria-hidden', 'true' );

		// The visible label of the field names the button, too.
		if ( select.id ) {
			var field = document.querySelector( 'label[for="' + select.id + '"]' );

			if ( field ) {
				field.id = field.id || 'pvk-select-label-' + id;
				button.setAttribute( 'aria-labelledby', field.id + ' ' + button.id );
				field.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					button.focus();
				} );
			}
		}

		Array.prototype.forEach.call( select.options, function ( option, index ) {
			var item = document.createElement( 'div' );
			var text = option.textContent;
			var match = option.disabled ? /^(.*) \((.*)\)$/.exec( text ) : null;

			item.className = 'pvk-select__option';
			item.id = 'pvk-select-opt-' + id + '-' + index;
			item.setAttribute( 'role', 'option' );
			item.setAttribute( 'data-index', String( index ) );

			if ( match ) {
				item.appendChild( document.createTextNode( match[ 1 ] ) );
				var sub = document.createElement( 'span' );
				sub.className = 'pvk-select__sub';
				sub.textContent = match[ 2 ];
				item.appendChild( sub );
			} else {
				item.textContent = text;
			}

			if ( option.disabled ) {
				item.setAttribute( 'aria-disabled', 'true' );
			}

			list.appendChild( item );
			options.push( item );
		} );

		/**
		 * Show the chosen option on the button and in the list.
		 */
		function sync() {
			var chosen = select.selectedIndex;

			label.textContent = chosen >= 0 ? select.options[ chosen ].textContent : '';
			label.classList.toggle( 'is-placeholder', chosen >= 0 && '' === select.options[ chosen ].value );
			button.disabled = select.disabled;

			options.forEach( function ( item, index ) {
				item.setAttribute( 'aria-selected', index === chosen ? 'true' : 'false' );
			} );
		}

		function highlight( index, scroll ) {
			options.forEach( function ( item, position ) {
				item.classList.toggle( 'is-active', position === index );
			} );

			active = index;

			if ( index >= 0 ) {
				button.setAttribute( 'aria-activedescendant', options[ index ].id );

				if ( scroll ) {
					var item = options[ index ];

					if ( item.offsetTop < list.scrollTop ) {
						list.scrollTop = item.offsetTop;
					} else if ( item.offsetTop + item.offsetHeight > list.scrollTop + list.clientHeight ) {
						list.scrollTop = item.offsetTop + item.offsetHeight - list.clientHeight;
					}
				}
			} else {
				button.removeAttribute( 'aria-activedescendant' );
			}
		}

		function step( from, direction ) {
			var index = from;

			do {
				index += direction;
			} while ( index >= 0 && index < options.length && select.options[ index ].disabled );

			return index >= 0 && index < options.length ? index : from;
		}

		function show() {
			if ( select.disabled || ! list.hidden ) {
				return;
			}

			if ( open && open !== api ) {
				open.hide();
			}

			list.hidden = false;
			wrapper.classList.add( 'is-open' );
			button.setAttribute( 'aria-expanded', 'true' );

			// Open upwards when there is no room below.
			var rect = button.getBoundingClientRect();
			var room = window.innerHeight - rect.bottom;

			wrapper.classList.toggle( 'is-up', room < Math.min( list.scrollHeight, 280 ) + 16 && rect.top > room );

			highlight( select.selectedIndex, false );

			if ( select.selectedIndex >= 0 ) {
				list.scrollTop = Math.max( 0, options[ select.selectedIndex ].offsetTop - list.clientHeight / 2 );
			}

			open = api;
		}

		function hide() {
			if ( list.hidden ) {
				return;
			}

			list.hidden = true;
			wrapper.classList.remove( 'is-open', 'is-up' );
			button.setAttribute( 'aria-expanded', 'false' );
			button.removeAttribute( 'aria-activedescendant' );

			if ( open === api ) {
				open = null;
			}
		}

		function choose( index ) {
			if ( index < 0 || select.options[ index ].disabled ) {
				return;
			}

			var changed = select.selectedIndex !== index;

			select.selectedIndex = index;
			sync();
			hide();
			button.focus();

			if ( changed ) {
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		}

		var api = { hide: hide, sync: sync };

		button.addEventListener( 'click', function () {
			if ( list.hidden ) {
				show();
			} else {
				hide();
			}
		} );

		button.addEventListener( 'keydown', function ( event ) {
			var key = event.key;

			if ( 'ArrowDown' === key || 'ArrowUp' === key ) {
				event.preventDefault();

				if ( list.hidden ) {
					show();
				} else {
					highlight( step( active, 'ArrowDown' === key ? 1 : -1 ), true );
				}
			} else if ( 'Home' === key || 'End' === key ) {
				if ( ! list.hidden ) {
					event.preventDefault();
					highlight( 'Home' === key ? step( -1, 1 ) : step( options.length, -1 ), true );
				}
			} else if ( 'Enter' === key || ' ' === key ) {
				event.preventDefault();

				if ( list.hidden ) {
					show();
				} else {
					choose( active );
				}
			} else if ( 'Escape' === key ) {
				if ( ! list.hidden ) {
					event.preventDefault();
					event.stopPropagation();
					hide();
				}
			} else if ( 'Tab' === key ) {
				hide();
			} else if ( 1 === key.length && ! event.ctrlKey && ! event.metaKey && ! event.altKey ) {
				// Type-ahead: jump to the next option that starts with what was typed.
				typed += key.toLowerCase();
				window.clearTimeout( typedTimer );
				typedTimer = window.setTimeout( function () {
					typed = '';
				}, 700 );

				for ( var i = 0; i < options.length; i++ ) {
					var index = ( Math.max( active, 0 ) + ( 1 === typed.length ? 1 : 0 ) + i ) % options.length;

					if ( ! select.options[ index ].disabled && select.options[ index ].textContent.toLowerCase().indexOf( typed ) === 0 ) {
						if ( list.hidden ) {
							select.selectedIndex = index;
							sync();
							select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
						} else {
							highlight( index, true );
						}

						break;
					}
				}
			}
		} );

		list.addEventListener( 'mousedown', function ( event ) {
			// Keep focus on the button while clicking an option.
			event.preventDefault();
		} );

		list.addEventListener( 'click', function ( event ) {
			var item = event.target.closest ? event.target.closest( '.pvk-select__option' ) : null;

			if ( item ) {
				choose( parseInt( item.getAttribute( 'data-index' ), 10 ) );
			}
		} );

		list.addEventListener( 'mousemove', function ( event ) {
			var item = event.target.closest ? event.target.closest( '.pvk-select__option' ) : null;

			if ( item && ! item.hasAttribute( 'aria-disabled' ) ) {
				highlight( parseInt( item.getAttribute( 'data-index' ), 10 ), false );
			}
		} );

		button.addEventListener( 'blur', function () {
			hide();
		} );

		// A change made elsewhere (a script, a form reset) shows up on the button.
		select.addEventListener( 'change', sync );

		if ( select.form ) {
			select.form.addEventListener( 'reset', function () {
				window.setTimeout( sync, 0 );
			} );
		}

		sync();
	}

	/**
	 * Show blocks only while a select has a given value.
	 */
	function conditionals() {
		document.querySelectorAll( '[data-pvk-visible-if]' ).forEach( function ( block ) {
			var parts = block.getAttribute( 'data-pvk-visible-if' ).split( '=' );
			var source = document.getElementById( parts[ 0 ] );

			if ( ! source ) {
				return;
			}

			function update() {
				block.hidden = source.value !== parts[ 1 ];
			}

			source.addEventListener( 'change', update );
			update();
		} );
	}

	document.querySelectorAll( '.pvk select:not([multiple]):not([data-pvk-native])' ).forEach( enhance );
	conditionals();

	document.addEventListener( 'click', function ( event ) {
		if ( open && ! event.target.closest( '.pvk-select' ) ) {
			open.hide();
		}
	} );
}() );

( function () {
	'use strict';

	// A choice that saves as soon as it is made, such as the language in the header.
	document.addEventListener( 'change', function ( event ) {
		var target = event.target;

		if ( target instanceof Element && target.hasAttribute( 'data-povikvane-autosubmit' ) && target.form ) {
			target.form.submit();
		}
	} );
}() );

( function () {
	'use strict';

	// A button that starts something slow shows what it is doing and cannot be pressed twice.
	document.addEventListener( 'submit', function ( event ) {
		var button = event.submitter;

		if ( ! ( button instanceof Element ) || ! button.hasAttribute( 'data-pvk-busy' ) ) {
			return;
		}

		window.setTimeout( function () {
			button.textContent = button.getAttribute( 'data-pvk-busy' );
			button.classList.add( 'is-busy' );
			button.setAttribute( 'aria-busy', 'true' );
		}, 0 );
	} );
}() );
