<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Parser;

/**
 * Standardised Eightshift block markup fixtures for parser tests.
 * Each constant is valid Gutenberg block comment markup.
 */
final class BlockFixtures
{
	// ---------------------------------------------------------------------------
	// Single self-closing blocks
	// ---------------------------------------------------------------------------

	public const SIMPLE_HEADING = '<!-- wp:eightshift-boilerplate/heading {"headingHeadingContent":"About the Teacher","headingHeadingSize":"h2"} /-->';

	public const SIMPLE_PARAGRAPH = '<!-- wp:eightshift-boilerplate/paragraph {"paragraphParagraphContent":"Something is going on here."} /-->';

	/** Button: URL and boolean should NOT be extracted, only content. */
	public const BUTTON_WITH_URL = '<!-- wp:eightshift-boilerplate/button {"buttonButtonContent":"Learn More","buttonButtonUrl":"https://example.com","buttonButtonIsNewTab":true} /-->';

	/** Block whose only attributes are structural — nothing to translate. */
	public const NO_TRANSLATABLE_CONTENT = '<!-- wp:eightshift-boilerplate/spacer {"spacerHeight":"100","spacerMobile":"50"} /-->';

	/** Malformed JSON — parser must skip and log, not crash. */
	public const MALFORMED_JSON = '<!-- wp:eightshift-boilerplate/heading {"broken json here} /-->';

	// ---------------------------------------------------------------------------
	// Wrapper blocks
	// ---------------------------------------------------------------------------

	public const WRAPPER_WITH_INNER = <<<'MARKUP'
<!-- wp:eightshift-boilerplate/card {"cardCardTitleContent":"Our Story","cardCardDescriptionContent":"Founded in 2020."} -->
    <!-- wp:eightshift-boilerplate/button {"buttonButtonContent":"Read More"} /-->
<!-- /wp:eightshift-boilerplate/card -->
MARKUP;

	/** Nested wrapper: layout → card → button. Tests depth tracking. */
	public const NESTED_WRAPPERS = <<<'MARKUP'
<!-- wp:eightshift-boilerplate/layout {"layoutLayoutTitleContent":"Team"} -->
    <!-- wp:eightshift-boilerplate/card {"cardCardTitleContent":"Alice","cardCardDescriptionContent":"Developer"} -->
        <!-- wp:eightshift-boilerplate/button {"buttonButtonContent":"Contact Alice"} /-->
    <!-- /wp:eightshift-boilerplate/card -->
<!-- /wp:eightshift-boilerplate/layout -->
MARKUP;

	/** Same block type nested — closing tag matching must be depth-aware. */
	public const SAME_TYPE_NESTED = <<<'MARKUP'
<!-- wp:eightshift-boilerplate/group {"groupGroupTitleContent":"Outer"} -->
    <!-- wp:eightshift-boilerplate/group {"groupGroupTitleContent":"Inner"} -->
    <!-- /wp:eightshift-boilerplate/group -->
<!-- /wp:eightshift-boilerplate/group -->
MARKUP;

	// ---------------------------------------------------------------------------
	// Mixed content
	// ---------------------------------------------------------------------------

	/** Standard Gutenberg core blocks interspersed with Eightshift blocks. */
	public const MIXED_CORE_AND_EIGHTSHIFT = <<<'MARKUP'
<!-- wp:eightshift-boilerplate/heading {"headingHeadingContent":"Title Here"} /-->
<!-- wp:paragraph -->
<p>This is a core paragraph block.</p>
<!-- /wp:paragraph -->
<!-- wp:eightshift-boilerplate/paragraph {"paragraphParagraphContent":"Eightshift paragraph."} /-->
MARKUP;

	// ---------------------------------------------------------------------------
	// Full page
	// ---------------------------------------------------------------------------

	public const FULL_PAGE = <<<'MARKUP'
<!-- wp:eightshift-boilerplate/heading {"headingHeadingContent":"Welcome to Our School","headingHeadingSize":"h1","headingPaddingTop":"full"} /-->
<!-- wp:eightshift-boilerplate/paragraph {"paragraphParagraphContent":"We have been educating students since 1990.","paragraphParagraphSize":"large"} /-->
<!-- wp:eightshift-boilerplate/card {"cardCardTitleContent":"Programs","cardCardDescriptionContent":"Explore our wide range of academic programs."} -->
    <!-- wp:eightshift-boilerplate/button {"buttonButtonContent":"View All Programs","buttonButtonUrl":"/programs"} /-->
<!-- /wp:eightshift-boilerplate/card -->
<!-- wp:eightshift-boilerplate/heading {"headingHeadingContent":"Contact Us","headingHeadingSize":"h2"} /-->
MARKUP;

	// ---------------------------------------------------------------------------
	// Edge cases
	// ---------------------------------------------------------------------------

	/** Empty post content. */
	public const EMPTY_CONTENT = '';

	/** Only whitespace. */
	public const WHITESPACE_CONTENT = "\n\n   \n";

	/** Content with HTML entities in attribute values. */
	public const HTML_ENTITIES = '<!-- wp:eightshift-boilerplate/heading {"headingHeadingContent":"AT&T \u0026 Friends"} /-->';

	/** Attribute value that is a URL — must NOT be extracted even if key ends with Content. */
	public const URL_IN_CONTENT_KEY = '<!-- wp:eightshift-boilerplate/link {"linkLinkContent":"https://example.com/page"} /-->';

	/** Attribute value that is numeric — must NOT be extracted. */
	public const NUMERIC_IN_CONTENT_KEY = '<!-- wp:eightshift-boilerplate/counter {"counterCounterContent":"42"} /-->';

	/** Multiple translatable attributes in one block. */
	public const MULTIPLE_CONTENT_ATTRS = '<!-- wp:eightshift-boilerplate/hero {"heroHeroTitleContent":"Hero Title","heroHeroSubtitleContent":"Hero Subtitle","heroHeroSize":"large"} /-->';
}
