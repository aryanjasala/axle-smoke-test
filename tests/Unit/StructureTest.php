<?php
/**
 * Tests for the structure fingerprint, the rules and the findings built on them.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Rules;
use AryanX\Axle\SmokeTest\Structure;
use AryanX\Axle\SmokeTest\Verdict;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

final class StructureTest extends SmokeTestCase {

	private const T = array(
		'text'   => 0.6,
		'layout' => 40,
		'blank'  => 40,
	);

	private function page( array $parts = array() ): string {
		$parts = $parts + array(
			'header' => '<header><a href="/">Home</a></header>',
			'nav'    => '<nav><ul><li><a href="/a">A</a></li><li><a href="/b">B</a></li><li><a href="/c">C</a></li></ul></nav>',
			'main'   => '<main><h1>Our coffee</h1><p>We roast coffee in small batches in Pune and ship it fresh to your door every week.</p><form action="https://example.com/contact/" method="post"><input name="email"><button>Send</button></form></main>',
			'footer' => '<footer><a href="/privacy">Privacy</a></footer>',
		);

		return '<!doctype html><html><head><title>Shop</title><script>var x = "<form>";</script></head><body>' . implode( '', $parts ) . '</body></html>';
	}

	private function codes( array $findings ): array {
		return array_column( $findings, 'code' );
	}

	public function test_the_fingerprint_names_landmarks_forms_and_buckets_counts(): void {
		$fingerprint = Structure::analyse( $this->page() );

		$this->assertSame(
			array(
				'header' => 1,
				'nav'    => 1,
				'main'   => 1,
				'footer' => 1,
			),
			$fingerprint['landmarks']
		);
		$this->assertSame( 'Our coffee', $fingerprint['h1'] );
		$this->assertSame( 'Shop', $fingerprint['title'] );
		$this->assertSame( array( 'form:/contact/' => 1 ), $fingerprint['forms'] );
		$this->assertSame( 0, $fingerprint['add_to_cart'] );
		$this->assertSame( 3, $fingerprint['counts']['menu_items'] );
		// A form inside a script is not a form.
		$this->assertCount( 1, $fingerprint['forms'] );
	}

	public function test_it_tells_search_and_login_forms_apart_and_finds_add_to_cart(): void {
		$html        = $this->page(
			array(
				'main' => '<main><form role="search" action="/"><input type="search" name="s"></form><form action="/wp-login.php"><input type="password" name="pwd"></form><button class="single_add_to_cart_button">Add</button></main>',
			)
		);
		$fingerprint = Structure::analyse( $html );

		$this->assertSame(
			array(
				'login:/wp-login.php' => 1,
				'search:/'            => 1,
			),
			$fingerprint['forms']
		);
		$this->assertSame( 1, $fingerprint['search'] );
		$this->assertSame( 1, $fingerprint['login'] );
		$this->assertSame( 1, $fingerprint['add_to_cart'] );
	}

	public function test_a_notice_form_that_posts_to_admin_post_is_not_a_part_of_the_page(): void {
		$fingerprint = Structure::analyse( $this->page( array( 'main' => '<main><form action="https://example.com/wp-admin/admin-post.php"><button>Dismiss</button></form></main>' ) ) );

		$this->assertSame( array(), $fingerprint['forms'] );
	}

	public function test_small_changes_in_content_do_not_change_the_buckets(): void {
		$a = Structure::analyse( $this->page( array( 'footer' => '<footer>' . str_repeat( '<a href="/x">x</a>', 40 ) . '</footer>' ) ) );
		$b = Structure::analyse( $this->page( array( 'footer' => '<footer>' . str_repeat( '<a href="/x">x</a>', 42 ) . '</footer>' ) ) );

		$this->assertSame( $a['counts'], $b['counts'] );
		$this->assertSame( array(), Structure::compare( $b, $a ) );
	}

	public function test_a_missing_form_header_and_add_to_cart_are_red_and_say_what_is_gone(): void {
		$before = Structure::analyse( $this->page( array( 'main' => '<main><h1>Mug</h1><form action="/cart/"><button class="single_add_to_cart_button">Add</button></form><p>' . str_repeat( 'words ', 40 ) . '</p></main>' ) ) );
		$after  = Structure::analyse( $this->page( array( 'header' => '', 'main' => '<main><h1>Mug</h1><p>' . str_repeat( 'words ', 40 ) . '</p></main>' ) ) );

		$findings = Structure::compare( $after, $before );

		$this->assertSame( array( 'structure_landmark', 'structure_form', 'structure_add_to_cart' ), $this->codes( $findings ) );
		$this->assertSame( array( 'red', 'red', 'red' ), array_column( $findings, 'level' ) );
		$this->assertSame( 'The page no longer has a header.', $findings[0]['message'] );
		$this->assertSame( 'The page no longer has a form that sends to /cart/. It had 1.', $findings[1]['message'] );
		$this->assertSame( 'form:form:/cart/', $findings[1]['sig'] );
	}

	public function test_a_big_drop_in_counts_or_text_is_amber(): void {
		$before = Structure::analyse( $this->page( array( 'main' => '<main><h1>Blog</h1>' . str_repeat( '<p><a href="/p">Post</a> <img src="a.jpg"></p>', 60 ) . '</main>' ) ) );
		$after  = Structure::analyse( $this->page( array( 'main' => '<main><h1>Blog</h1><p><a href="/p">Post</a></p></main>' ) ) );

		$findings = Structure::compare( $after, $before );

		$this->assertSame( array( 'amber', 'amber', 'amber' ), array_column( $findings, 'level' ) );
		$this->assertContains( 'structure_text', $this->codes( $findings ) );
		$this->assertContains( 'structure_count', $this->codes( $findings ) );
	}

	public function test_thresholds_and_findings_have_filters(): void {
		$before = Structure::analyse( $this->page( array( 'main' => '<main><h1>Blog</h1>' . str_repeat( '<p><a href="/p">Post</a></p>', 40 ) . '</main>' ) ) );
		$after  = Structure::analyse( $this->page( array( 'main' => '<main><h1>Blog</h1>' . str_repeat( '<p><a href="/p">Post</a></p>', 20 ) . '</main>' ) ) );

		$this->assertSame( array(), Structure::compare( $after, $before ) );

		Filters\expectApplied( 'axle_smoke_test_structure_drop_percent' )->once()->andReturn( 30 );
		Filters\expectApplied( 'axle_smoke_test_structure_findings' )->once()->andReturnUsing(
			static function ( $findings ) {
				$findings[] = array(
					'level'   => 'amber',
					'code'    => 'extra',
					'message' => 'Extra',
					'sig'     => 'extra',
				);
				return $findings;
			}
		);
		$findings = Structure::compare( $after, $before );
		$this->assertContains( 'extra', $this->codes( $findings ) );
		$this->assertContains( 'structure_count', $this->codes( $findings ) );
	}

	public function test_bucket_edges_come_from_a_filter(): void {
		$this->assertSame( 5, Structure::bucket( 5, Structure::buckets() ) );
		$this->assertSame( 8, Structure::bucket( 6, Structure::buckets() ) );
		$this->assertSame( 0, Structure::bucket( 0, Structure::buckets() ) );

		Filters\expectApplied( 'axle_smoke_test_structure_buckets' )->once()->andReturn( array( 10, 'x', -4, 100 ) );
		$this->assertSame( array( 10, 100 ), Structure::buckets() );
	}

	public function test_rules_match_text_and_simple_selectors(): void {
		$html = $this->page( array( 'main' => '<main><h1>Contact us</h1><form id="contact" class="wpcf7-form big" action="/c/"><button class="buy now" data-sku="abc-1">Buy now</button></form></main>' ) );

		$this->assertSame( array(), Structure::check_rules( $html, array( 'must' => array( 'Buy NOW', 'form#contact', '.wpcf7-form > button.buy', 'button[data-sku^="abc"]', '.missing, .buy', 'header' ), 'must_not' => array( 'Sorry, no products', '.cart-empty' ) ) ) );

		$findings = Structure::check_rules( $html, array( 'must' => array( 'Free shipping', '#checkout' ), 'must_not' => array( 'Contact us', 'form.wpcf7-form' ) ) );
		$this->assertSame( array( 'rule_must', 'rule_must', 'rule_must_not', 'rule_must_not' ), $this->codes( $findings ) );
		$this->assertSame( 'The page must contain "Free shipping", but it does not.', $findings[0]['message'] );
		$this->assertSame( 'The page must not contain "Contact us", but it does.', $findings[2]['message'] );
	}

	public function test_a_rule_is_text_unless_it_looks_like_a_selector(): void {
		$this->assertFalse( Structure::is_selector( 'Add to cart' ) );
		$this->assertFalse( Structure::is_selector( 'contact' ) );
		$this->assertFalse( Structure::is_selector( 'text:.hero' ) );
		$this->assertFalse( Structure::is_selector( 'text:[woocommerce_cart]' ) );
		$this->assertTrue( Structure::is_selector( '.hero' ) );
		$this->assertTrue( Structure::is_selector( '#main' ) );
		$this->assertTrue( Structure::is_selector( 'form.cart' ) );
		$this->assertTrue( Structure::is_selector( 'footer' ) );
		$this->assertTrue( Structure::is_selector( 'css:article' ) );
		$this->assertSame( '[woocommerce_cart]', Structure::plain( 'text:[woocommerce_cart]' ) );
	}

	public function test_a_rule_that_cannot_be_read_is_reported_not_guessed(): void {
		$findings = Structure::check_rules( $this->page(), array( 'must' => array( '.a:not(.b)' ) ) );
		$this->assertSame( array( 'rule_invalid' ), $this->codes( $findings ) );
		$this->assertSame( 'amber', $findings[0]['level'] );
	}

	public function test_to_xpath_builds_the_query_for_each_part(): void {
		$this->assertSame( '//form[@id="contact" and contains(concat(" ",normalize-space(@class)," ")," a ")]', Structure::to_xpath( 'form#contact.a' ) );
		$this->assertSame( '//main//a[@href]|//footer/p', Structure::to_xpath( 'main a[href], footer > p' ) );
		$this->assertNull( Structure::to_xpath( 'a:hover' ) );
		$this->assertNull( Structure::to_xpath( '' ) );
		$this->assertSame( array( '.buy', 'form#c' ), Structure::selectors( array( 'Buy now', '.buy', 'text:.nope', 'form#c', '.a:not(b)' ) ) );
	}

	public function test_verdict_uses_the_structure_and_keeps_old_baselines_working(): void {
		$base = array(
			'url_key'          => 'home',
			'tier'             => 'server',
			'http_status'      => 200,
			'landmarks'        => array( 'footer' => 1 ),
			'text_fingerprint' => array(),
			'meta'             => array(
				'expected'   => array( 200 ),
				'kind'       => 'html',
				'text_bytes' => 500,
				'structure'  => Structure::analyse( $this->page() ),
			),
		);
		$now  = $base;
		$now['landmarks']         = array( 'footer' => 0 );
		$now['meta']['structure'] = Structure::analyse( $this->page( array( 'footer' => '' ) ) );

		$verdict = Verdict::evaluate( $now, $base, self::T );
		$this->assertSame( 'red', $verdict['verdict'] );
		$this->assertSame( array( 'structure_landmark' ), array_column( $verdict['reasons'], 'code' ) );
		$this->assertTrue( $verdict['reasons'][0]['acceptable'] );
		$this->assertSame( 'landmark:footer', $verdict['reasons'][0]['sig'] );

		// A baseline from before the structure check: the tag counts still catch the footer.
		unset( $base['meta']['structure'] );
		$old = Verdict::evaluate( $now, $base, self::T );
		$this->assertSame( array( 'landmark' ), array_column( $old['reasons'], 'code' ) );
		$this->assertSame( 'amber', $old['verdict'] );
	}

	public function test_rules_turn_a_first_run_red_and_are_never_acceptable(): void {
		$result = array(
			'url_key'     => 'home',
			'tier'        => 'server',
			'http_status' => 200,
			'meta'        => array(
				'expected'      => array( 200 ),
				'kind'          => 'html',
				'text_bytes'    => 500,
				'rule_findings' => Structure::check_rules( $this->page(), array( 'must' => array( '.buy' ) ) ),
			),
		);

		$verdict = Verdict::evaluate( $result, null, self::T );

		$this->assertSame( 'red', $verdict['verdict'] );
		$this->assertFalse( $verdict['reasons'][0]['acceptable'] );
	}

	public function test_accepted_findings_are_left_out_for_that_url_only(): void {
		$base = array(
			'url_key'     => 'home',
			'tier'        => 'server',
			'http_status' => 200,
			'meta'        => array(
				'expected'   => array( 200 ),
				'kind'       => 'html',
				'text_bytes' => 500,
				'structure'  => Structure::analyse( $this->page() ),
			),
		);
		$now  = $base;
		$now['meta']['structure'] = Structure::analyse( $this->page( array( 'footer' => '', 'nav' => '' ) ) );

		$landmarks = static function ( array $verdict ): array {
			return array_values( preg_grep( '/^landmark:/', array_column( $verdict['reasons'], 'sig' ) ) );
		};
		$this->assertSame( array( 'landmark:nav', 'landmark:footer' ), $landmarks( Verdict::evaluate( $now, $base, self::T ) ) );

		$now['meta']['accepted'] = array( 'landmark:footer' );
		$this->assertSame( array( 'landmark:nav' ), $landmarks( Verdict::evaluate( $now, $base, self::T ) ) );
	}

	public function test_browser_tier_says_when_a_required_element_is_hidden_or_not_rendered(): void {
		$result = array(
			'url_key' => 'home',
			'tier'    => 'browser',
			'layout'  => array(
				'.buy' => array( 'x' => 0, 'y' => 0, 'w' => 0, 'h' => 0, 'visible' => false ),
			),
			'meta'    => array( 'must_selectors' => array( '.buy', '.cta', '.ok' ) ),
		);
		$result['layout']['.ok'] = array( 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'visible' => true );

		$verdict = Verdict::evaluate( $result, null, self::T );

		$this->assertSame( array( 'rule_hidden', 'rule_not_rendered' ), array_column( $verdict['reasons'], 'code' ) );
		$this->assertSame( 'amber', $verdict['verdict'] );
	}

	public function test_rule_sets_are_cleaned_capped_and_saved_per_url(): void {
		Actions\expectDone( 'axle_smoke_test_rules_saved' )->twice();

		$saved = Rules::save( 'Shop', array( 'must' => "  .buy \n\n.buy\nAdd to cart", 'must_not' => array( 'Fatal', '' ) ) );

		$this->assertSame( array( '.buy', 'Add to cart' ), $saved['must'] );
		$this->assertSame( array( 'Fatal' ), $saved['must_not'] );
		$this->assertSame( $saved, Rules::saved( 'shop' ) );
		$this->assertSame( array( 'must' => array( 'a', '.buy', 'Add to cart' ), 'must_not' => array( 'Fatal' ) ), Rules::merge( array( 'must' => array( 'a', '.buy' ) ), $saved ) );

		Filters\expectApplied( 'axle_smoke_test_max_rules' )->atLeast()->once()->andReturn( 1 );
		$this->assertSame( array( '.buy' ), Rules::clean( array( 'must' => array( '.buy', 'x' ) ) )['must'] );

		Rules::save( 'shop', array() );
		$this->assertArrayNotHasKey( 'shop', Rules::all() );
	}

	public function test_accepted_findings_are_remembered_taken_back_and_cleared(): void {
		Rules::accept( 'home', 'form:a' );
		Rules::accept( 'home', 'form:a' );
		Rules::accept( 'home', 'text' );
		$this->assertSame( array( 'form:a', 'text' ), Rules::accepted( 'home' ) );

		Rules::unaccept( 'home', 'form:a' );
		$this->assertSame( array( 'text' ), Rules::accepted( 'home' ) );

		Rules::forget( 'home' );
		$this->assertSame( array(), Rules::accepted( 'home' ) );
	}
}
