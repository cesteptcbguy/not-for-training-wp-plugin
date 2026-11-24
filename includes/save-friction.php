<?php
/**
 * Save friction: on images that match the selectors, no dragging, no
 * right-click menu, and no long-press save sheet on iOS. Clicks are left
 * alone, so lightboxes, product zoom and links on images keep working.
 *
 * Front end only, for people who can't upload files: the site's own
 * authors and editors can still save their images. One inline style and
 * one inline script, no files, no jQuery.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'notfortraining_enqueue_save_friction', 20 );

/**
 * Selectors covered by default: content images, core blocks and their
 * lightbox, featured images, WooCommerce's product gallery and PhotoSwipe,
 * and Elementor's image widget, gallery and lightbox.
 *
 * @return string[]
 */
function notfortraining_save_friction_default_selectors() {
	return array(
		'.entry-content img',
		'.wp-block-image img',
		'.wp-block-gallery img',
		'.gallery img',
		'.wp-block-post-featured-image img',
		'.wp-post-image',
		'.wp-lightbox-overlay img',
		'.woocommerce-product-gallery img',
		'.pswp img',
		'.elementor-widget-image img',
		'.elementor-gallery-item img',
		'.elementor-lightbox img',
	);
}

/**
 * The selector lists to use: the defaults, and the site's custom ones
 * (already sanitized, possibly empty). Kept apart so an unusual custom
 * selector a browser doesn't support can never switch off the defaults.
 *
 * @return array{defaults: string, custom: string}
 */
function notfortraining_save_friction_selectors() {
	return array(
		'defaults' => implode( ',', notfortraining_save_friction_default_selectors() ),
		'custom'   => (string) notfortraining_get_option( 'save_friction_selectors' ),
	);
}

/**
 * Whether save friction applies to this request: on, on the front end, not
 * a feed, REST or embed request, and the visitor can't upload files.
 *
 * @return bool
 */
function notfortraining_save_friction_applies() {
	if ( ! notfortraining_get_option( 'save_friction' ) ) {
		return false;
	}
	if ( is_admin() || is_feed() || is_embed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}
	return ! current_user_can( 'upload_files' );
}

/**
 * The inline CSS. Defaults and custom selectors are separate rules, so a
 * custom selector a browser rejects only drops its own rule.
 *
 * @return string
 */
function notfortraining_save_friction_css() {
	$selectors    = notfortraining_save_friction_selectors();
	$declarations = '{-webkit-touch-callout:none;-webkit-user-select:none;user-select:none;-webkit-user-drag:none}';
	$css          = $selectors['defaults'] . $declarations;
	if ( '' !== $selectors['custom'] ) {
		$css .= $selectors['custom'] . $declarations;
	}
	return $css;
}

/**
 * The inline script: one listener each for contextmenu and dragstart on
 * the document, so images added later (lightboxes, infinite scroll) are
 * covered too. Custom selectors are only used if the browser accepts them.
 *
 * @return string
 */
function notfortraining_save_friction_js() {
	$selectors = notfortraining_save_friction_selectors();
	return '(function(d,s,c){if(c){try{d.querySelector(c);s+=","+c}catch(e){}}'
		. 'function f(e){var t=e.target;if(t&&t.matches&&t.matches(s))e.preventDefault()}'
		. 'd.addEventListener("contextmenu",f);d.addEventListener("dragstart",f)})(document,'
		. wp_json_encode( $selectors['defaults'] ) . ',' . wp_json_encode( $selectors['custom'] ) . ');';
}

/**
 * Add the style and script.
 */
function notfortraining_enqueue_save_friction() {
	if ( ! notfortraining_save_friction_applies() ) {
		return;
	}
	wp_register_style( 'notfortraining-save-friction', false, array(), NOTFORTRAINING_VERSION );
	wp_enqueue_style( 'notfortraining-save-friction' );
	wp_add_inline_style( 'notfortraining-save-friction', notfortraining_save_friction_css() );

	wp_register_script( 'notfortraining-save-friction', false, array(), NOTFORTRAINING_VERSION, array( 'in_footer' => true ) );
	wp_enqueue_script( 'notfortraining-save-friction' );
	wp_add_inline_script( 'notfortraining-save-friction', notfortraining_save_friction_js() );
}

/**
 * Sanitize the custom selector list.
 *
 * 1. Strip what can never be selector syntax: HTML tags with their
 *    contents, CSS comments (to nothing, as CSS reads them), declaration
 *    blocks with their contents (each becomes a comma, so the selectors of
 *    pasted rules stay apart), url(...), semicolons, angle brackets and
 *    backslashes, then any other character outside the selector set. A
 *    ">" child combinator therefore becomes a descendant combinator, which
 *    covers the same images and possibly more.
 * 2. Split on top-level commas (not inside parentheses, brackets or quotes).
 * 3. Parse each selector with notfortraining_is_valid_selector().
 *
 * If any selector doesn't parse, or the cleaned list is over 500
 * characters, the whole value is dropped: a half-applied list would be
 * harder to notice than an empty one.
 *
 * @param mixed $value Submitted value.
 * @return string Comma-and-space separated selectors, or "".
 */
function notfortraining_sanitize_selectors( $value ) {
	if ( ! is_string( $value ) || strlen( $value ) > 5000 ) {
		return '';
	}
	$value = wp_strip_all_tags( $value );
	$value = preg_replace( '#/\*.*?(?:\*/|$)#s', '', $value );
	$value = preg_replace( '/\{[^{}]*\}?/', ',', (string) $value ); // Pasted rules: keep their selectors apart.
	$value = preg_replace( '/url\s*\([^)]*\)?/i', ' ', (string) $value );
	$value = str_replace( array( '{', '}', ';', '<', '>', '\\' ), ' ', (string) $value );
	$value = preg_replace( '/[^A-Za-z0-9\s\-_.#,:\[\]=\'"~|^$*()+]/', ' ', (string) $value );
	$value = trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
	if ( '' === $value ) {
		return '';
	}

	$parts = notfortraining_split_selector_list( $value );
	if ( null === $parts ) {
		return '';
	}

	$clean = array();
	foreach ( $parts as $part ) {
		$part = trim( $part );
		if ( '' === $part ) {
			continue; // A stray or trailing comma.
		}
		if ( ! notfortraining_is_valid_selector( $part ) ) {
			return '';
		}
		$clean[] = $part;
	}

	$list = implode( ', ', $clean );
	return strlen( $list ) > 500 ? '' : $list;
}

/**
 * Split a selector list on top-level commas.
 *
 * @param string $selectors Selector list.
 * @return string[]|null Parts, or null if brackets or quotes don't balance.
 */
function notfortraining_split_selector_list( $selectors ) {
	$parts = array();
	$depth = 0;
	$quote = '';
	$part  = '';
	$len   = strlen( $selectors );
	for ( $i = 0; $i < $len; $i++ ) {
		$char = $selectors[ $i ];
		if ( '' !== $quote ) {
			if ( $char === $quote ) {
				$quote = '';
			}
		} elseif ( '"' === $char || "'" === $char ) {
			$quote = $char;
		} elseif ( '(' === $char || '[' === $char ) {
			++$depth;
		} elseif ( ')' === $char || ']' === $char ) {
			if ( --$depth < 0 ) {
				return null;
			}
		} elseif ( ',' === $char && 0 === $depth ) {
			$parts[] = $part;
			$part    = '';
			continue;
		}
		$part .= $char;
	}
	if ( 0 !== $depth || '' !== $quote ) {
		return null;
	}
	$parts[] = $part;
	return $parts;
}

/**
 * Whether one selector (no top-level commas) is valid: compound selectors
 * (type or *, .class, #id, [attribute], :pseudo-class) joined by
 * descendant, + or ~ combinators. Pseudo-elements are rejected: they never
 * match an image.
 *
 * @param string $selector Selector.
 * @param int    $depth    Nesting depth inside :not() and friends.
 * @param bool   $relative Allow a leading + or ~ (inside :has()).
 * @return bool
 */
function notfortraining_is_valid_selector( $selector, $depth = 0, $relative = false ) {
	$s     = trim( (string) $selector );
	$len   = strlen( $s );
	$ident = '-?[_a-zA-Z][_a-zA-Z0-9-]*';
	$value = '(?:"[^"]*"|\'[^\']*\'|' . $ident . ')';
	if ( '' === $s || $depth > 3 ) {
		return false;
	}

	$i = 0;
	if ( $relative && preg_match( '/\G[+~]\s*/', $s, $m, 0, $i ) ) {
		$i += strlen( $m[0] );
	}

	while ( $i < $len ) {
		$start = $i;
		if ( preg_match( '/\G(?:\*|' . $ident . ')/', $s, $m, 0, $i ) ) {
			$i += strlen( $m[0] );
		}
		while ( $i < $len ) {
			if ( preg_match( '/\G[.#]' . $ident . '/', $s, $m, 0, $i )
				|| preg_match( '/\G\[\s*' . $ident . '\s*(?:[~|^$*]?=\s*' . $value . '\s*(?:[iIsS]\s*)?)?\]/', $s, $m, 0, $i ) ) {
				$i += strlen( $m[0] );
				continue;
			}
			if ( ':' === $s[ $i ] ) {
				$used = notfortraining_selector_pseudo_length( $s, $i, $depth );
				if ( ! $used ) {
					return false;
				}
				$i += $used;
				continue;
			}
			break;
		}
		if ( $i === $start ) {
			return false; // Nothing a compound selector could start with.
		}
		if ( $i >= $len ) {
			return true;
		}
		if ( ! preg_match( '/\G(?:\s*[+~]\s*|\s+)/', $s, $m, 0, $i ) ) {
			return false;
		}
		$i += strlen( $m[0] );
		if ( $i >= $len ) {
			return false; // Ends in a combinator.
		}
	}
	return false;
}

/**
 * Length of the pseudo-class starting at $i, or 0 if it isn't valid.
 *
 * @param string $s     Selector.
 * @param int    $i     Offset of the ":".
 * @param int    $depth Nesting depth.
 * @return int
 */
function notfortraining_selector_pseudo_length( $s, $i, $depth ) {
	if ( ! preg_match( '/\G:(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', $s, $m, 0, $i ) ) {
		return 0; // Includes "::" pseudo-elements.
	}
	$used = strlen( $m[0] );
	$name = strtolower( $m[1] );
	if ( $i + $used >= strlen( $s ) || '(' !== $s[ $i + $used ] ) {
		return $used;
	}

	// Find the matching ")".
	$open  = $i + $used;
	$level = 0;
	$close = -1;
	$len   = strlen( $s );
	for ( $j = $open; $j < $len; $j++ ) {
		if ( '(' === $s[ $j ] ) {
			++$level;
		} elseif ( ')' === $s[ $j ] && 0 === --$level ) {
			$close = $j;
			break;
		}
	}
	if ( $close < 0 ) {
		return 0;
	}
	$inner = trim( substr( $s, $open + 1, $close - $open - 1 ) );

	if ( in_array( $name, array( 'not', 'is', 'where', 'has' ), true ) ) {
		$parts = notfortraining_split_selector_list( $inner );
		if ( null === $parts ) {
			return 0;
		}
		foreach ( $parts as $part ) {
			if ( ! notfortraining_is_valid_selector( $part, $depth + 1, 'has' === $name ) ) {
				return 0;
			}
		}
	} elseif ( in_array( $name, array( 'nth-child', 'nth-last-child', 'nth-of-type', 'nth-last-of-type' ), true ) ) {
		if ( ! preg_match( '/^(?:odd|even|[+-]?\d*n(?:\s*[+-]\s*\d+)?|[+-]?\d+)$/i', $inner ) ) {
			return 0;
		}
	} elseif ( in_array( $name, array( 'lang', 'dir' ), true ) ) {
		if ( ! preg_match( '/^-?[_a-zA-Z][_a-zA-Z0-9-]*$/', $inner ) ) {
			return 0;
		}
	} else {
		return 0; // No other pseudo-class takes arguments.
	}
	return $close - $i + 1;
}
