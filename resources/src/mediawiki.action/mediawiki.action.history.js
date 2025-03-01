/*!
 * JavaScript for History action
 */
$( function () {
	var $historyCompareForm = $( '#mw-history-compare' ),
		$historySubmitter,
		$pagehistory = $( '#pagehistory' ),
		$lis = $pagehistory.find( '.mw-contributions-list > li' );

	/**
	 * @ignore
	 * @this {Element} input
	 * @param {jQuery.Event} e
	 * @return {boolean} False to cancel the default event
	 */
	function updateDiffRadios() {
		var nextState = 'before',
			$li,
			$inputs,
			$oldidRadio,
			$diffRadio;

		if ( !$lis.length ) {
			return true;
		}

		$lis.each( function () {
			$li = $( this );
			$inputs = $li.find( 'input[type="radio"]' );
			$oldidRadio = $inputs.filter( '[name="oldid"]' ).eq( 0 );
			$diffRadio = $inputs.filter( '[name="diff"]' ).eq( 0 );

			$li.removeClass( 'selected between before after' );

			if ( !$oldidRadio.length || !$diffRadio.length ) {
				return true;
			}

			if ( $oldidRadio.prop( 'checked' ) ) {
				$li.addClass( 'selected after' );
				nextState = 'after';
				// Disable the hidden radio because it can still be selected with
				// arrow keys on Firefox
				$diffRadio.prop( 'disabled', true );
			} else if ( $diffRadio.prop( 'checked' ) ) {
				// The following classes are used here:
				// * before
				// * after
				$li.addClass( 'selected ' + nextState );
				nextState = 'between';
				// Disable the hidden radio because it can still be selected with
				// arrow keys on Firefox
				$oldidRadio.prop( 'disabled', true );
			} else {
				// This list item has neither checked
				// apply the appropriate class following the previous item.
				// The following classes are used here:
				// * before
				// * after
				$li.addClass( nextState );
				// Disable or re-enable for Firefox, provided the revision is accessible
				if ( $li.find( 'a.mw-changeslist-date' ).length ) {
					$oldidRadio.prop( 'disabled', nextState === 'before' );
					$diffRadio.prop( 'disabled', nextState === 'after' );
				}
			}
		} );

		return true;
	}

	$pagehistory.on( 'change', 'input[name="diff"], input[name="oldid"]', updateDiffRadios );

	// Set initial state
	updateDiffRadios();

	// Prettify url output for HistoryAction submissions,
	// to cover up action=historysubmit construction.

	// Ideally we'd use e.target instead of $historySubmitter, but e.target points
	// to the form element for submit actions, so.
	$historyCompareForm.find( '.historysubmit' ).on( 'click', function () {
		$historySubmitter = $( this );
	} );

	// On submit we clone the form element, remove unneeded fields in the clone
	// that pollute the query parameter with stuff from the other "use case",
	// and then submit the clone.
	// Without the cloning we'd be changing the real form, which is slower, could make
	// the page look broken for a second in slow browsers and might show the form broken
	// again when coming back from a "next" page.
	$historyCompareForm.on( 'submit', function ( e ) {
		var $copyForm, $copyRadios;

		if ( $historySubmitter ) {
			$copyForm = $historyCompareForm.clone();
			$copyRadios = $copyForm.find( '#pagehistory .mw-contributions-list > li' ).find( 'input[name="diff"], input[name="oldid"]' );

			// Emulate what native submit does by preserving the clicked button as hidden field
			if ( $historySubmitter.attr( 'name' ) ) {
				$( '<input>' ).prop( {
					type: 'hidden',
					name: $historySubmitter.attr( 'name' ),
					value: $historySubmitter.attr( 'value' )
				} ).appendTo( $copyForm );
			}

			// When comparing revisions, disable any checked revisiondelete ids[..]=.. checkboxes
			// eslint-disable-next-line no-jquery/no-class-state
			if ( $historySubmitter.hasClass( 'mw-history-compareselectedversions-button' ) ) {
				$copyForm.find( 'input[name^="ids["]:checked' ).prop( 'checked', false );

			// When using revisiondelete or editchangetags, strip diff=/oldid= radios
			} else if (
				// eslint-disable-next-line no-jquery/no-class-state
				$historySubmitter.hasClass( 'mw-history-revisiondelete-button' ) ||
				// eslint-disable-next-line no-jquery/no-class-state
				$historySubmitter.hasClass( 'mw-history-editchangetags-button' )
			) {
				$copyRadios.remove();
				// eslint-disable-next-line no-jquery/no-sizzle
				$copyForm.find( ':submit' ).remove();
			}

			// Firefox requires the form to be attached, so insert hidden into document first
			// Also remove potentially conflicting id attributes that we don't need anyway
			$copyForm
				.css( 'display', 'none' )
				.find( '[id]' ).removeAttr( 'id' )
				.end()
				.insertAfter( $historyCompareForm )
				.trigger( 'submit' );

			e.preventDefault();
			return false; // Because the submit is special, return false as well.
		}

		// Continue natural browser handling other wise
		return true;
	} );
} );
