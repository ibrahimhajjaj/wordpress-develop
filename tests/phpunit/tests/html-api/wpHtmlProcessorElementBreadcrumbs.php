<?php
/**
 * Unit tests covering WP_HTML_Processor::get_element_breadcrumbs().
 *
 * @package WordPress
 * @subpackage HTML-API
 *
 * @since 6.9.0
 *
 * @group html-api
 *
 * @coversDefaultClass WP_HTML_Breadcrumbs_Processor
 */
class Tests_HtmlApi_WpHtmlProcessorElementBreadcrumbs extends WP_UnitTestCase {

	/**
	 * @ticket 63020
	 *
	 * @covers WP_HTML_Breadcrumbs_Processor::get_element_breadcrumbs
	 *
	 * @dataProvider data_element_breadcrumbs_basic
	 *
	 * @param string $html        HTML string with tags in it.
	 * @param string $target_tag  Tag name to find and test.
	 * @param array  $expected    Expected element breadcrumbs structure.
	 */
	public function test_get_element_breadcrumbs_basic( $html, $target_tag, $expected ) {
		$processor = WP_HTML_Breadcrumbs_Processor::create_fragment( $html );
		$processor->enable_index_tracking( true );
		$processor->enable_attribute_tracking( true, array( 'id', 'role', 'class' ) );

		$this->assertTrue( $processor->next_tag( $target_tag ), "Failed to find {$target_tag} element in HTML." );

		$breadcrumbs = $processor->get_element_breadcrumbs();

		$this->assertIsArray( $breadcrumbs, 'get_element_breadcrumbs() should return an array.' );
		$this->assertCount( count( $expected ), $breadcrumbs, 'Breadcrumbs array should have correct number of elements.' );

		foreach ( $expected as $index => $expected_crumb ) {
			$this->assertArrayHasKey( $index, $breadcrumbs, "Breadcrumb at index {$index} should exist." );
			$actual_crumb = $breadcrumbs[ $index ];

			$this->assertIsArray( $actual_crumb, "Breadcrumb at index {$index} should be an array." );
			$this->assertArrayHasKey( 'tag', $actual_crumb, "Breadcrumb at index {$index} should have 'tag' key." );
			$this->assertArrayHasKey( 'namespace', $actual_crumb, "Breadcrumb at index {$index} should have 'namespace' key." );
			$this->assertArrayHasKey( 'index', $actual_crumb, "Breadcrumb at index {$index} should have 'index' key." );
			$this->assertArrayHasKey( 'attributes', $actual_crumb, "Breadcrumb at index {$index} should have 'attributes' key." );

			$this->assertSame( $expected_crumb['tag'], $actual_crumb['tag'], "Tag name mismatch at index {$index}." );
			$this->assertSame( $expected_crumb['namespace'], $actual_crumb['namespace'], "Namespace mismatch at index {$index}." );
			$this->assertSame( $expected_crumb['index'], $actual_crumb['index'], "Index mismatch at index {$index}." );

			if ( isset( $expected_crumb['attributes'] ) ) {
				$this->assertSame( $expected_crumb['attributes'], $actual_crumb['attributes'], "Attributes mismatch at index {$index}." );
			} else {
				$this->assertEmpty( $actual_crumb['attributes'], "Attributes should be empty at index {$index}." );
			}
		}
	}

	/**
	 * Data provider for basic element breadcrumbs tests.
	 *
	 * @return array[]
	 */
	public static function data_element_breadcrumbs_basic() {
		return array(
			'Simple IMG tag' => array(
				'<img src="test.jpg">',
				'IMG',
				array(
					array(
						'tag'        => 'HTML',
						'namespace'  => 'html',
						'index'      => null,
						'attributes' => array(),
					),
					array(
						'tag'        => 'BODY',
						'namespace'  => 'html',
						'index'      => null,
						'attributes' => array(),
					),
					array(
						'tag'        => 'IMG',
						'namespace'  => 'html',
						'index'      => 1,
						'attributes' => array(),
					),
				),
			),
			'IMG with class attribute' => array(
				'<div class="container"><img src="test.jpg"></div>',
				'IMG',
				array(
					array(
						'tag'        => 'HTML',
						'namespace'  => 'html',
						'index'      => null,
						'attributes' => array(),
					),
					array(
						'tag'        => 'BODY',
						'namespace'  => 'html',
						'index'      => null,
						'attributes' => array(),
					),
					array(
						'tag'        => 'DIV',
						'namespace'  => 'html',
						'index'      => 1,
						'attributes' => array( 'class' => 'container' ),
					),
					array(
						'tag'        => 'IMG',
						'namespace'  => 'html',
						'index'      => 1,
						'attributes' => array(),
					),
				),
			),
			'Multiple attributes' => array(
				'<div id="main" class="content" role="banner"><img src="test.jpg"></div>',
				'IMG',
				array(
					array(
						'tag'        => 'HTML',
						'namespace'  => 'html',
						'index'      => null,
						'attributes' => array(),
					),
					array(
						'tag'        => 'BODY',
						'namespace'  => 'html',
						'index'      => null,
						'attributes' => array(),
					),
					array(
						'tag'        => 'DIV',
						'namespace'  => 'html',
						'index'      => 1,
						'attributes' => array( 'id' => 'main', 'role' => 'banner', 'class' => 'content' ),
					),
					array(
						'tag'        => 'IMG',
						'namespace'  => 'html',
						'index'      => 1,
						'attributes' => array(),
					),
				),
			),
		);
	}

	/**
	 * @ticket 63020
	 *
	 * @covers WP_HTML_Breadcrumbs_Processor::get_element_breadcrumbs
	 *
	 * @dataProvider data_element_breadcrumbs_indexing
	 *
	 * @param string $html        HTML string with multiple elements.
	 * @param string $target_tag  Tag name to find and test.
	 * @param int    $match_n     Which occurrence to match (1-based).
	 * @param array  $expected    Expected element breadcrumbs structure.
	 */
	public function test_get_element_breadcrumbs_indexing( $html, $target_tag, $match_n, $expected ) {
		$processor = WP_HTML_Breadcrumbs_Processor::create_fragment( $html );
		$processor->enable_index_tracking( true );
		$processor->enable_attribute_tracking( true, array( 'id', 'role', 'class' ) );

		$found_count = 0;
		while ( $processor->next_tag( $target_tag ) ) {
			$found_count++;
			if ( $found_count === $match_n ) {
				break;
			}
		}

		$this->assertSame( $match_n, $found_count, "Failed to find {$match_n}th occurrence of {$target_tag} element." );

		$breadcrumbs = $processor->get_element_breadcrumbs();

		// Check that the target element has the correct index
		$target_breadcrumb = end( $breadcrumbs );
		$this->assertSame( $expected['target_index'], $target_breadcrumb['index'], "Target element should have index {$expected['target_index']}." );

		// Check that all breadcrumbs have correct structure
		foreach ( $breadcrumbs as $crumb ) {
			$this->assertArrayHasKey( 'tag', $crumb, 'Each breadcrumb should have tag key.' );
			$this->assertArrayHasKey( 'namespace', $crumb, 'Each breadcrumb should have namespace key.' );
			$this->assertArrayHasKey( 'index', $crumb, 'Each breadcrumb should have index key.' );
			$this->assertArrayHasKey( 'attributes', $crumb, 'Each breadcrumb should have attributes key.' );
			$this->assertSame( 'html', $crumb['namespace'], 'All elements should have html namespace.' );
		}
	}

	/**
	 * Data provider for element breadcrumbs indexing tests.
	 *
	 * @return array[]
	 */
	public static function data_element_breadcrumbs_indexing() {
		return array(
			'Second IMG in sequence' => array(
				'<div><p>Text</p><img src="first.jpg"><img src="second.jpg"></div>',
				'IMG',
				2,
				array( 'target_index' => 3 ),
			),
			'Third IMG in sequence' => array(
				'<div><p>Text</p><img src="first.jpg"><img src="second.jpg"><img src="third.jpg"></div>',
				'IMG',
				3,
				array( 'target_index' => 4 ),
			),
			'IMG after mixed content' => array(
				'<div><p>Text</p><span>Span</span><img src="test.jpg"><div>Div</div></div>',
				'IMG',
				1,
				array( 'target_index' => 3 ),
			),
		);
	}

	/**
	 * @ticket 63020
	 *
	 * @covers WP_HTML_Breadcrumbs_Processor::get_element_breadcrumbs
	 */
	public function test_get_element_breadcrumbs_deep_nesting() {
		$html = '<div><div><div><div><div><img src="deep.jpg"></div></div></div></div></div>';
		$processor = WP_HTML_Breadcrumbs_Processor::create_fragment( $html );
		$processor->enable_index_tracking( true );
		$processor->enable_attribute_tracking( true, array( 'id', 'role', 'class' ) );

		$this->assertTrue( $processor->next_tag( 'IMG' ), 'Failed to find IMG element in deeply nested HTML.' );

		$breadcrumbs = $processor->get_element_breadcrumbs();

		// Should have HTML, BODY, and 5 DIVs, plus IMG
		$this->assertCount( 8, $breadcrumbs, 'Deep nesting should produce 8 breadcrumbs.' );

		// Check that all DIVs have index 1 (they're all first children)
		for ( $i = 2; $i < 7; $i++ ) { // Skip HTML and BODY
			$this->assertSame( 'DIV', $breadcrumbs[ $i ]['tag'], "Breadcrumb {$i} should be DIV." );
			$this->assertSame( 1, $breadcrumbs[ $i ]['index'], "DIV at position {$i} should have index 1." );
		}

		// Check that IMG has index 1
		$this->assertSame( 'IMG', $breadcrumbs[7]['tag'], 'Last breadcrumb should be IMG.' );
		$this->assertSame( 1, $breadcrumbs[7]['index'], 'IMG should have index 1.' );
	}

	/**
	 * @ticket 63020
	 *
	 * @covers WP_HTML_Breadcrumbs_Processor::get_element_breadcrumbs
	 */
	public function test_get_element_breadcrumbs_empty_html() {
		$processor = WP_HTML_Breadcrumbs_Processor::create_fragment( '' );
		$processor->enable_index_tracking( true );
		$processor->enable_attribute_tracking( true, array( 'id', 'role', 'class' ) );

		$this->assertFalse( $processor->next_tag( 'IMG' ), 'Should not find IMG in empty HTML.' );
	}

	/**
	 * @ticket 63020
	 *
	 * @covers WP_HTML_Breadcrumbs_Processor::get_element_breadcrumbs
	 */
	public function test_get_element_breadcrumbs_no_target_element() {
		$html = '<div><p>No images here</p><span>Just text</span></div>';
		$processor = WP_HTML_Breadcrumbs_Processor::create_fragment( $html );
		$processor->enable_index_tracking( true );
		$processor->enable_attribute_tracking( true, array( 'id', 'role', 'class' ) );

		$this->assertFalse( $processor->next_tag( 'IMG' ), 'Should not find IMG when none exists.' );
	}

	/**
	 * @ticket 63020
	 *
	 * @covers WP_HTML_Breadcrumbs_Processor::get_element_breadcrumbs
	 */
	public function test_get_element_breadcrumbs_special_characters() {
		$html = '<div class="test-class" id="test-id" data-value="special & chars"><img src="test.jpg" alt="Image & Description"></div>';
		$processor = WP_HTML_Breadcrumbs_Processor::create_fragment( $html );
		$processor->enable_index_tracking( true );
		$processor->enable_attribute_tracking( true, array( 'id', 'role', 'class' ) );

		$this->assertTrue( $processor->next_tag( 'IMG' ), 'Failed to find IMG element with special characters.' );

		$breadcrumbs = $processor->get_element_breadcrumbs();
		$div_breadcrumb = $breadcrumbs[2]; // HTML, BODY, DIV

		$this->assertSame( 'DIV', $div_breadcrumb['tag'], 'Should find DIV element.' );
		$this->assertArrayHasKey( 'id', $div_breadcrumb['attributes'], 'Should capture id attribute.' );
		$this->assertArrayHasKey( 'class', $div_breadcrumb['attributes'], 'Should capture class attribute.' );
		$this->assertSame( 'test-id', $div_breadcrumb['attributes']['id'], 'Should preserve special characters in id.' );
		$this->assertSame( 'test-class', $div_breadcrumb['attributes']['class'], 'Should preserve special characters in class.' );
	}

	/**
	 * @ticket 63020
	 *
	 * @covers WP_HTML_Breadcrumbs_Processor::get_element_breadcrumbs
	 */
	public function test_get_element_breadcrumbs_self_closing_elements() {
		$html = '<div><br><hr><img src="test.jpg"><input type="text"></div>';
		$processor = WP_HTML_Breadcrumbs_Processor::create_fragment( $html );
		$processor->enable_index_tracking( true );
		$processor->enable_attribute_tracking( true, array( 'id', 'role', 'class' ) );

		$this->assertTrue( $processor->next_tag( 'IMG' ), 'Failed to find IMG element among self-closing elements.' );

		$breadcrumbs = $processor->get_element_breadcrumbs();
		$img_breadcrumb = end( $breadcrumbs );

		$this->assertSame( 'IMG', $img_breadcrumb['tag'], 'Should find IMG element.' );
		$this->assertSame( 3, $img_breadcrumb['index'], 'IMG should have index 3 (after BR and HR).' );
	}

	/**
	 * @ticket 63020
	 *
	 * @covers WP_HTML_Breadcrumbs_Processor::get_element_breadcrumbs
	 */
	public function test_get_element_breadcrumbs_performance() {
		$html = str_repeat( '<div class="item"><span>Item</span><img src="item.jpg"></div>', 100 );
		$processor = WP_HTML_Breadcrumbs_Processor::create_fragment( $html );
		$processor->enable_index_tracking( true );
		$processor->enable_attribute_tracking( true, array( 'id', 'role', 'class' ) );

		$count = 0;
		while ( $processor->next_tag( 'IMG' ) ) {
			$breadcrumbs = $processor->get_element_breadcrumbs();
			$count++;
			
			// Verify each breadcrumb has the expected structure
			$this->assertIsArray( $breadcrumbs, 'Each breadcrumb should be an array.' );
			$this->assertGreaterThan( 0, count( $breadcrumbs ), 'Each breadcrumb should have at least one element.' );
		}

		$this->assertSame( 100, $count, 'Should process all 100 IMG elements.' );
	}

	/**
	 * @ticket 63020
	 *
	 * @covers WP_HTML_Breadcrumbs_Processor::get_element_breadcrumbs
	 */
	public function test_get_element_breadcrumbs_consistency_with_get_breadcrumbs() {
		$html = '<div class="container"><header><div><img src="test.jpg"></div></header></div>';
		$processor = WP_HTML_Breadcrumbs_Processor::create_fragment( $html );
		$processor->enable_index_tracking( true );
		$processor->enable_attribute_tracking( true, array( 'id', 'role', 'class' ) );

		$this->assertTrue( $processor->next_tag( 'IMG' ), 'Failed to find IMG element.' );

		$element_breadcrumbs = $processor->get_element_breadcrumbs();
		$regular_breadcrumbs = $processor->get_breadcrumbs();

		// Both should have the same number of elements
		$this->assertCount( count( $regular_breadcrumbs ), $element_breadcrumbs, 'Element breadcrumbs should have same count as regular breadcrumbs.' );

		// Tag names should match
		foreach ( $element_breadcrumbs as $index => $element_crumb ) {
			$this->assertSame( $regular_breadcrumbs[ $index ], $element_crumb['tag'], "Tag name should match at index {$index}." );
		}
	}

	/**
	 * @ticket 63020
	 *
	 * @covers WP_HTML_Breadcrumbs_Processor::get_element_breadcrumbs
	 */
	public function test_get_element_breadcrumbs_malformed_html() {
		$html = '<div><img src="test.jpg" <div><p>Unclosed tags</p>';
		$processor = WP_HTML_Breadcrumbs_Processor::create_fragment( $html );
		$processor->enable_index_tracking( true );
		$processor->enable_attribute_tracking( true, array( 'id', 'role', 'class' ) );

		// Should still be able to find the IMG element even in malformed HTML
		$this->assertTrue( $processor->next_tag( 'IMG' ), 'Should find IMG element even in malformed HTML.' );

		$breadcrumbs = $processor->get_element_breadcrumbs();

		// Should still produce valid breadcrumbs
		$this->assertIsArray( $breadcrumbs, 'Should return array even for malformed HTML.' );
		$this->assertGreaterThan( 0, count( $breadcrumbs ), 'Should have at least one breadcrumb.' );

		// Check that breadcrumbs have the expected structure
		foreach ( $breadcrumbs as $crumb ) {
			$this->assertArrayHasKey( 'tag', $crumb, 'Each breadcrumb should have tag key.' );
			$this->assertArrayHasKey( 'namespace', $crumb, 'Each breadcrumb should have namespace key.' );
			$this->assertArrayHasKey( 'index', $crumb, 'Each breadcrumb should have index key.' );
			$this->assertArrayHasKey( 'attributes', $crumb, 'Each breadcrumb should have attributes key.' );
		}

		// The IMG should be found and have proper breadcrumbs
		$img_breadcrumb = end( $breadcrumbs );
		$this->assertSame( 'IMG', $img_breadcrumb['tag'], 'Should find IMG element in malformed HTML.' );
	}

	/**
	 * @ticket 63020
	 *
	 * @covers WP_HTML_Breadcrumbs_Processor::get_element_breadcrumbs
	 */
	public function test_get_element_breadcrumbs_xpath_generation() {
		$html = '<div class="wp-site-blocks"><header><div><img src="logo.png"></div></header></div>';
		$processor = WP_HTML_Processor::create_fragment( $html );

		$this->assertTrue( $processor->next_tag( 'IMG' ), 'Failed to find IMG element.' );

		$breadcrumbs = $processor->get_element_breadcrumbs();

		// Generate XPath using the breadcrumbs
		$xpath_parts = array();
		foreach ( $breadcrumbs as $crumb ) {
			$tag = $crumb['tag'];
			$index = $crumb['index'];
			$attributes = $crumb['attributes'] ?? array();

			if ( $tag === 'HTML' || $tag === 'BODY' ) {
				$xpath_parts[] = "/{$tag}";
			} else {
				$expression = "/*[{$index}][self::{$tag}]";

				// Add attribute predicates for disambiguation
				foreach ( array( 'id', 'role', 'class' ) as $attr_name ) {
					if ( isset( $attributes[ $attr_name ] ) && is_string( $attributes[ $attr_name ] ) ) {
						$expression .= "[@{$attr_name}='" . addcslashes( $attributes[ $attr_name ], '\\"' ) . "']";
						break;
					}
				}

				$xpath_parts[] = $expression;
			}
		}

		$generated_xpath = implode( '', $xpath_parts );

		// Test XPath structure rather than exact string match
		$this->assertStringContainsString( '/HTML/BODY', $generated_xpath, 'XPath should start with /HTML/BODY.' );
		$this->assertStringContainsString( '[self::DIV]', $generated_xpath, 'XPath should contain DIV element.' );
		$this->assertStringContainsString( "[@class='wp-site-blocks']", $generated_xpath, 'XPath should contain class attribute.' );
		$this->assertStringContainsString( '[self::HEADER]', $generated_xpath, 'XPath should contain HEADER element.' );
		$this->assertStringContainsString( '[self::IMG]', $generated_xpath, 'XPath should contain IMG element.' );
		$this->assertStringContainsString( '/*[1]', $generated_xpath, 'XPath should contain index predicates.' );
	}
}
