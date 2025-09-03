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
		$had = parent::next_token();
		if ( ! $had ) {
			return false;
		}

		// Only respond to tag tokens; ignore text/comments/etc.
		if ( '#tag' !== $this->get_token_type() ) {
			return true;
		}

		// Approximate stack transitions from public signals.
		if ( $this->is_tag_closer() ) {
			// Pop one frame if present.
			if ( ! empty( $this->frames ) ) {
				array_pop( $this->frames );
			}
			return true;
		}

		// Ensure our frames are aligned with current breadcrumbs before pushing.
		$crumbs = $this->get_breadcrumbs(); // includes HTML/BODY and the current element at the end
		$need   = max( 0, count( $crumbs ) - 1 /* minus current */ - count( $this->frames ) );
		if ( $need > 0 ) {
			$ns = $this->get_namespace();
			for ( $i = $need; $i > 0; $i-- ) {
				// Push placeholders for any missing ancestors (no index, no bookmark)
				$ancestor_tag   = $crumbs[ count( $this->frames ) ];
				$this->frames[] = array(
					'tag'       => $ancestor_tag,
					'namespace' => $ns,
					'index'     => null,
					'bookmark'  => null,
				);
			}
		}

		// Push current opener frame.
		$tag = $this->get_tag();
		$ns  = $this->get_namespace();

		$index = null;
		if ( $this->track_indices && 'HTML' !== $tag && ! ( 'BODY' === $tag && 'html' === $ns ) ) {
			$parent_depth                                 = max( 0, count( $this->frames ) - 1 );
			$this->child_counts_by_depth[ $parent_depth ] = ( $this->child_counts_by_depth[ $parent_depth ] ?? 0 ) + 1;
			$index                                        = $this->child_counts_by_depth[ $parent_depth ];
		}

		$bookmark_name = null;
		// Attempt to bookmark real tokens to enable lazy attribute reads.
		if ( $this->track_attributes ) {
			$candidate = 'wphtmlbp-' . ( ++$this->bookmark_counter );
			if ( $this->set_bookmark( $candidate ) ) {
				$bookmark_name = $candidate;
			}
		}

		$this->frames[] = array(
			'tag'       => $tag,
			'namespace' => $ns,
			'index'     => $index,
			'bookmark'  => $bookmark_name,
		);

		// Initialize child count slot for new depth to zero when we push.
		$this->child_counts_by_depth[ count( $this->frames ) - 1 ] = ( $this->child_counts_by_depth[ count( $this->frames ) - 1 ] ?? 0 );
		return true;
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
		$result = array();

		// Save our place if we plan to seek for attributes / index computations.
		$here = null;
		if ( $this->track_attributes || $this->track_indices ) {
			$here = 'wphtmlbp-return';
			$this->set_bookmark( $here );
		}

		$parent_token = null;
		foreach ( $this->state->stack_of_open_elements->walk_down() as $token ) {
			$node_name = $token->node_name;
			$ns        = $token->namespace;

			$index = null;
			if ( $this->track_indices && isset( $parent_token ) && 'HTML' !== $node_name && ! ( 'BODY' === $node_name && 'html' === $ns ) ) {
				$index = $this->compute_direct_child_index( $parent_token, $token, $here );
			}

			$attributes = array();
			if ( $this->track_attributes && ! empty( $token->bookmark_name ) && $this->seek( $token->bookmark_name ) ) {
				foreach ( $this->attribute_allowlist as $attr ) {
					$v = parent::get_attribute( $attr );
					if ( null !== $v ) {
						$attributes[ $attr ] = $v;
					}
				}
			}

			$result[]    = array(
				'tag'        => $node_name,
				'namespace'  => $ns,
				'index'      => $index,
				'attributes' => $attributes,
			);
			$parent_token = $token;
		}

		if ( $here && $this->has_bookmark( $here ) ) {
			$this->seek( $here );
			$this->release_bookmark( $here );
		}

		return $result;
	}

	/**
	 * Optional helper to compose an XPath-like expression for the current node.
	 */
	public function get_xpath_for_current(): ?string {
		$crumbs = $this->get_element_breadcrumbs();
		if ( empty( $crumbs ) ) {
			return null;
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
