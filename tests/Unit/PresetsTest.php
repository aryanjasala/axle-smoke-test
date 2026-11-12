<?php
/**
 * Tests for the shop and language pages the smoke test suggests.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Presets;
use AryanX\Axle\SmokeTest\Rules;
use AryanX\Axle\SmokeTest\Settings;
use AryanX\Axle\SmokeTest\Urls;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class PresetsTest extends SmokeTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->options['page_on_front']            = 0;
		$this->options['axle_smoke_test_settings'] = array( 'auto_pick' => array_fill_keys( Urls::AUTO_TYPES, false ) );
		Functions\when( 'get_permalink' )->alias(
			static function ( $id ) {
				return 'https://example.com/?p=' . $id;
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array() );
	}

	private function urls(): Urls {
		return new Urls( new Settings() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['axle_smoke_test_shop'] );
		parent::tearDown();
	}

	private function shop(): void {
		$GLOBALS['axle_smoke_test_shop'] = true;
		\WP_Query::$queue                = array( array( 'posts' => array( 77 ) ) );
	}

	public function test_nothing_is_suggested_without_a_shop_or_a_language_plugin(): void {
		$this->assertSame( array(), Presets::suggestions( $this->urls() ) );
	}

	public function test_a_shop_gets_its_five_pages_each_with_rules_that_only_render(): void {
		$this->shop();

		$suggestions = Presets::suggestions( $this->urls() );

		$this->assertSame( array( 'preset-shop', 'preset-latest-product', 'preset-cart', 'preset-checkout', 'preset-my-account' ), array_column( $suggestions, 'key' ) );
		$this->assertSame( 'https://example.com/?p=77', $suggestions[1]['url'] );
		$this->assertStringContainsString( 'single_add_to_cart_button', $suggestions[1]['rules']['must'][0] );
		$this->assertSame( array( 'text:[woocommerce_cart]' ), $suggestions[2]['rules']['must_not'] );
		$this->assertSame( array( 200, 302 ), $suggestions[3]['expected'] );
		$this->assertSame( array( 'shop' ), array_unique( array_column( $suggestions, 'group' ) ) );
	}

	public function test_pages_already_on_the_list_are_not_suggested_again(): void {
		$this->shop();
		$this->options[ Urls::OPTION ] = array(
			array( 'key' => 'mine', 'label' => 'Cart', 'url' => 'https://example.com/cart', 'source' => 'custom' ),
		);

		$this->assertNotContains( 'preset-cart', array_column( Presets::suggestions( $this->urls() ), 'key' ) );
	}

	public function test_adding_suggestions_saves_the_pages_and_their_rules(): void {
		$this->shop();
		Actions\expectDone( 'axle_smoke_test_suggestions_added' )->once();
		Actions\expectDone( 'axle_smoke_test_url_added' )->twice();
		$urls = $this->urls();

		$added = Presets::add( $urls, array( 'preset-cart', 'preset-checkout' ) );

		$this->assertCount( 2, $added );
		$cart = $urls->find( $added[0]['key'] );
		$this->assertSame( 'Cart', $cart['label'] );
		$this->assertSame( array( 'text:[woocommerce_cart]' ), Rules::saved( $added[0]['key'] )['must_not'] );
		$this->assertSame( array( 200, 302 ), $urls->find( $added[1]['key'] )['expected'] );
		$this->assertSame( 'custom', $cart['source'] );
		$this->assertNotContains( 'preset-cart', array_column( Presets::suggestions( $urls ), 'key' ), 'Added pages stop being suggested.' );
	}

	public function test_each_language_gets_its_home_page_checked_for_a_header_and_a_footer(): void {
		Filters\expectApplied( 'axle_smoke_test_languages' )->atLeast()->once()->andReturn(
			array(
				array( 'code' => 'en', 'label' => 'English', 'home' => 'https://example.com/', 'default' => true ),
				array( 'code' => 'de', 'label' => 'Deutsch', 'home' => 'https://example.com/de/', 'default' => false ),
				array( 'code' => 'fr', 'label' => 'Francais', 'home' => 'https://example.com/fr/', 'default' => false ),
			)
		);

		$suggestions = Presets::suggestions( $this->urls() );

		$this->assertSame( array( 'preset-home-de', 'preset-home-fr' ), array_column( $suggestions, 'key' ) );
		$this->assertSame( 'Home page (Deutsch)', $suggestions[0]['label'] );
		$this->assertSame( array( 'header', 'footer' ), $suggestions[0]['rules']['must'] );
		$this->assertSame( 'language', $suggestions[0]['group'] );
	}

	public function test_a_site_with_one_language_is_not_multilingual(): void {
		Filters\expectApplied( 'axle_smoke_test_languages' )->atLeast()->once()->andReturn( array( array( 'code' => 'en', 'home' => 'https://example.com/', 'default' => true ) ) );

		$this->assertSame( array(), Presets::languages() );
	}

	public function test_translated_shop_pages_are_suggested_per_language(): void {
		$this->shop();
		Functions\when( 'wc_get_page_id' )->alias(
			static function ( $page ) {
				return array( 'shop' => 10, 'cart' => 11, 'checkout' => 12 )[ $page ] ?? 0;
			}
		);
		Functions\when( 'pll_get_post' )->alias(
			static function ( $id, $lang ) {
				return 'de' === $lang ? $id + 100 : 0;
			}
		);
		Filters\expectApplied( 'axle_smoke_test_languages' )->atLeast()->once()->andReturn(
			array(
				array( 'code' => 'en', 'label' => 'English', 'home' => 'https://example.com/', 'default' => true, 'plugin' => 'polylang' ),
				array( 'code' => 'de', 'label' => 'Deutsch', 'home' => 'https://example.com/de/', 'default' => false, 'plugin' => 'polylang' ),
			)
		);

		$keys = array_column( Presets::suggestions( $this->urls() ), 'key' );

		$this->assertContains( 'preset-checkout-de', $keys );
		$this->assertContains( 'preset-cart-de', $keys );
		$this->assertContains( 'preset-home-de', $keys );
	}

	public function test_a_filter_adds_more_presets(): void {
		Filters\expectApplied( 'axle_smoke_test_suggestions' )->once()->andReturn(
			array(
				array( 'key' => 'mine', 'url' => 'https://example.com/pricing/' ),
				array( 'nokey' => true ),
			)
		);

		$found = Presets::suggestions( $this->urls() );

		$this->assertSame( array( 'mine' ), array_column( $found, 'key' ) );
		$this->assertSame( array(), $found[0]['rules'] );
	}

	public function test_the_shop_pages_have_a_filter(): void {
		$this->shop();
		Filters\expectApplied( 'axle_smoke_test_shop_pages' )->once()->andReturn( array( array( 'key' => 'only', 'label' => 'Only', 'url' => 'https://example.com/only/' ), array( 'label' => 'No key' ) ) );

		$this->assertSame( array( 'only' ), array_column( Presets::shop(), 'key' ) );
	}
}
