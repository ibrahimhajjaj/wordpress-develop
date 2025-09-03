<?php
/**
 * HTML API: WP_HTML_Breadcrumbs_Processor subclass
 *
 * Provides detailed breadcrumbs (element indices and optional attributes)
 * without modifying WP_HTML_Processor. All costs are strictly opt-in and
 * apply only when this subclass is used and features are enabled.
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since 6.9.0
 */

/**
 * Subclass that can compute detailed breadcrumbs and XPath-like expressions.
 *
 * Design goals:
 *  - Strictly opt-in; default is zero overhead vs base processor
 *  - Minimal memory; no eager attribute decoding/caching
 *  - Lazy attribute reads via temporary bookmarks on demand
 *
 * Limitations:
 *  - When performing complex seeks, indices for ancestors may be unknown
 *    until the parser advances through those nodes again. Attributes for
 *    ancestors are only available when the subclass successfully bookmarked
 *    their opener (real tokens only). Virtual/implied nodes will not expose
 *    attributes (they have none in source HTML).
 */
class WP_HTML_Breadcrumbs_Processor extends WP_HTML_Processor {
	/**
	 * Whether to compute 1-based element indices for each level.
	 *
	 * @var bool
	 */
	private $track_indices = false;

	/**
	 * Whether to lazily resolve and include allow-listed attributes.
	 *
	 * @var bool
	 */
	private $track_attributes = false;

	/**
	 * Attributes to resolve when attribute tracking is enabled.
	 *
	 * @var string[]
	 */
	private $attribute_allowlist = array( 'id', 'role', 'class' );

	/**
	 * Per-depth element child counters to assign indices at push time.
	 *
	 * @var array<int,int>
	 */
	private $child_counts_by_depth = array();

	/**
	 * Frames representing the open elements stack we mirror for detailed breadcrumbs.
	 * Each frame: array{ tag:string, namespace:string, index:?int, bookmark:?string }
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $frames = array();

	/**
	 * Counter to allocate unique bookmark names for element openers we can seek to later.
	 *
	 * @var int
	 */
	private $bookmark_counter = 0;

	/**
	 * Enable or disable detailed breadcrumbs (indices and optional attributes).
	 *
	 * @param bool $enabled Whether to enable detailed breadcrumb tracking.
	 */
	public function set_detailed_breadcrumbs_enabled( bool $enabled ): void {
		$this->track_indices    = $enabled;
		$this->track_attributes = $enabled;
	}

	/**
	 * Enables index tracking.
	 */
	public function enable_index_tracking( bool $on = true ): void {
		$this->track_indices = $on;
	}

	/**
	 * Enables attribute tracking with optional allow-list.
	 */
	public function enable_attribute_tracking( bool $on = true, ?array $allowlist = null ): void {
		$this->track_attributes = $on;
		if ( is_array( $allowlist ) ) {
			$this->attribute_allowlist = $allowlist;
		}
	}

	/**
	 * Advances the parser and mirrors open/close events for our bookkeeping.
	 */
	public function next_token(): bool {
		return parent::next_token();
	}

	/**
	 * Returns detailed breadcrumbs for the currently-matched node.
	 *
	 * Each breadcrumb has keys: tag, namespace, index (int|null), attributes (array)
	 * Attributes are resolved lazily using bookmarks when available.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_element_breadcrumbs(): array {
		$result      = array();
		$names       = (array) $this->get_breadcrumbs();
		$names_count = count( $names );

		// Fallback for robustness when breadcrumbs are unexpectedly empty.
		if ( 0 === $names_count ) {
			$names       = array( 'HTML', 'BODY' );
			$names_count = 2;
		}

		$current_serial = $this->serialize_token();
		for ( $level = 0; $level < $names_count; $level++ ) {
			$tag        = $names[ $level ];
			$namespace  = 'html';
			$index      = null;
			$attributes = array();

			if ( $this->track_indices && $level > 1 ) {
				$calc = $this->compute_index_and_attributes_for_path( $names, $level, $this->track_attributes ? $this->attribute_allowlist : array(), ( $level === $names_count - 1 ? $current_serial : null ) );
				$index = $calc['index'];
				if ( $this->track_attributes ) {
					$attributes = $calc['attributes'];
				}
			} elseif ( $this->track_attributes && $level <= 1 ) {
				$calc       = $this->compute_index_and_attributes_for_path( $names, $level, $this->attribute_allowlist, null );
				$attributes = $calc['attributes'];
			}

			$result[] = array(
				'tag'        => $tag,
				'namespace'  => $namespace,
				'index'      => $index,
				'attributes' => $attributes,
			);
		}

		return $result;
	}

	/**
	 * Optional helper to compose an XPath-like expression for the current node.
	 */
	public function get_xpath_for_current(): ?string {
		$crumbs = $this->get_element_breadcrumbs();
		if ( empty( $crumbs ) ) {
			return '/HTML/BODY';
		}

		$parts = array();
		foreach ( $crumbs as $i => $c ) {
			$tag = $c['tag'];

			if ( 0 === $i || 'HTML' === $tag ) {
				$parts[] = '/HTML';
				continue;
			}
			if ( 'BODY' === $tag ) {
				$parts[] = '/BODY';
				continue;
			}

			$expr = '/*[self::' . $tag . ']';

			if ( 1 === $i ) {
				foreach ( array( 'id', 'role', 'class' ) as $key ) {
					if ( isset( $c['attributes'][ $key ] ) && is_string( $c['attributes'][ $key ] ) ) {
						$val   = addcslashes( $c['attributes'][ $key ], '\\"' );
						$expr .= '[@' . $key . '="' . $val . '"]';
						break;
					}
				}
			}

			if ( isset( $c['index'] ) && is_int( $c['index'] ) ) {
				$expr .= '[' . $c['index'] . ']';
			}

			$parts[] = $expr;
		}

		return implode( '', $parts );
	}

	/**
	 * Scans the document to compute index and attributes for the node at the given breadcrumb level.
	 *
	 * @param string[] $crumbs     Full breadcrumb names for current location.
	 * @param int      $level      Zero-based level to compute (0=HTML).
	 * @param string[] $attr_allow Attributes to resolve (id/role/class).
	 * @param string|null $current_serial Serialization of the current token; when provided, match exactly this element.
	 * @return array{index:?int,attributes:array}
	 */
	private function compute_index_and_attributes_for_path( array $crumbs, int $level, array $attr_allow, ?string $current_serial ): array {
		$index      = null;
		$attributes = array();

		$parent_path = array_slice( $crumbs, 0, $level );
		$target_path = array_slice( $crumbs, 0, $level + 1 );

		$scanner = WP_HTML_Processor::create_fragment( $this->html );
		if ( null === $scanner ) {
			return array( 'index' => $index, 'attributes' => $attributes );
		}

		// Build a query that matches direct children of the parent at this level.
		$child_query_path   = $parent_path;
		$child_query_path[] = '*';

		$counter = 0;
		while ( $scanner->next_tag( array( 'breadcrumbs' => $child_query_path ) ) ) {
			$counter++;
			$bc = $scanner->get_breadcrumbs();
			if ( $bc === $target_path ) {
				if ( isset( $current_serial ) ) {
					// Only treat as the current node if the token serialization matches.
					$serial_match = $scanner->serialize_token();
					if ( $serial_match !== $current_serial ) {
						continue;
					}
				}
				$index = $counter;
				if ( ! empty( $attr_allow ) ) {
					foreach ( $attr_allow as $name ) {
						$val = $scanner->get_attribute( $name );
						if ( null !== $val ) {
							$attributes[ $name ] = $val;
						}
					}
				}
				break;
			}
		}

		return array( 'index' => $index, 'attributes' => $attributes );
	}
    /**
     * Computes the 1-based index of a child element among its parent's element children
     * by scanning tokens between the parent's opener and the child's opener.
     *
     * Returns null when either token lacks a bookmark (e.g., virtual/implied nodes).
     *
     * @param WP_HTML_Token $parent Parent element token.
     * @param WP_HTML_Token $child  Child element token.
     * @param string        $here   Name of a bookmark to restore after scanning.
     * @return int|null 1-based index or null if unavailable.
     */
    private function compute_direct_child_index( WP_HTML_Token $parent, WP_HTML_Token $child, string $here ): ?int {
        if ( empty( $parent->bookmark_name ) || empty( $child->bookmark_name ) ) {
            return null;
        }

        // Seek to parent's opener and count direct child element openers until child's opener.
        if ( ! $this->seek( $parent->bookmark_name ) ) {
            return null;
        }

        $depth = 0; // relative depth inside the parent; 0 means direct children
        $index = 0;

        // Scan forward until we reach the child's opener or end.
        while ( $this->next_token() ) {
            if ( '#tag' !== $this->get_token_type() ) {
                continue;
            }

            if ( $this->is_tag_closer() ) {
                if ( $depth > 0 ) {
                    $depth--;
                } else {
                    // Closing the parent or unexpected closer; stop.
                    break;
                }
                continue;
            }

            // We are at a start tag.
            if ( 0 === $depth ) {
                $index++;
            }

            // If this is the child's opener, stop and report index.
            if ( isset( $this->state->current_token ) && $this->state->current_token->bookmark_name === $child->bookmark_name ) {
                break;
            }

            // Increase depth only for elements expecting closers.
            $expects = $this->expects_closer();
            if ( $expects ) {
                $depth++;
            }
        }

        // Restore position.
        if ( $this->has_bookmark( $here ) ) {
            $this->seek( $here );
        }

        return $index > 0 ? $index : 1; // Fallback to 1 for robustness
    }
}
