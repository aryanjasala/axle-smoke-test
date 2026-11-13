<?php
/**
 * The Settings tab.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Admin;

use AryanX\Axle\SmokeTest\Crypto;
use AryanX\Axle\SmokeTest\MuPlugin;
use AryanX\Axle\SmokeTest\Presets;
use AryanX\Axle\SmokeTest\RoleUser;
use AryanX\Axle\SmokeTest\Structure;
use AryanX\Axle\SmokeTest\Settings;
use AryanX\Axle\SmokeTest\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * Key URLs, what gets picked from the site, thresholds, alerts, rollback,
 * the cron user, basic auth, the early collector and retention.
 */
final class SettingsView {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Key URLs.
	 *
	 * @var Urls
	 */
	private Urls $urls;

	/**
	 * Sets up the view.
	 *
	 * @param Settings $settings Settings.
	 * @param Urls     $urls     Key URLs.
	 */
	public function __construct( Settings $settings, Urls $urls ) {
		$this->settings = $settings;
		$this->urls     = $urls;
	}

	/**
	 * Checkbox and list fields as Settings::update() expects them. Unticked
	 * boxes are missing from a form, so they are set to false here.
	 *
	 * @param array $posted Posted settings.
	 * @return array
	 */
	public static function from_form( array $posted ): array {
		foreach ( array( 'browser_tier', 'email', 'notice', 'auto_rollback', 'cron_admin_checks', 'daily', 'role_checks' ) as $box ) {
			$posted[ $box ] = ! empty( $posted[ $box ] );
		}
		$auto_pick = array();
		foreach ( Urls::AUTO_TYPES as $type ) {
			$auto_pick[ $type ] = ! empty( $posted['auto_pick'][ $type ] );
		}
		$posted['auto_pick']        = $auto_pick;
		$posted['role_check_pages'] = array_keys( array_filter( (array) ( $posted['role_check_pages'] ?? array() ) ) );
		$posted['triggers']         = array_keys( array_filter( (array) ( $posted['triggers'] ?? array() ) ) );
		foreach ( array( 'webhook_url', 'auth_pass' ) as $secret ) {
			$posted[ $secret ] = isset( $posted[ $secret ] ) ? trim( (string) $posted[ $secret ] ) : '';
		}

		return $posted;
	}

	/**
	 * Draws the tab.
	 */
	public function render(): void {
		$this->suggestions_section();
		$this->urls_section();

		$s = $this->settings->all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ast-settings">';
		wp_nonce_field( 'axle_smoke_test_save_settings' );
		echo '<input type="hidden" name="action" value="axle_smoke_test_save_settings">';

		echo '<section class="axle-card">';
		echo '<h2 class="axle-card__title">' . esc_html__( 'Pages picked from the site', 'axle-smoke-test' ) . '</h2>';
		echo '<fieldset class="ast-checks"><legend class="screen-reader-text">' . esc_html__( 'Pages picked from the site', 'axle-smoke-test' ) . '</legend>';
		$types = array(
			'home'        => __( 'Home page', 'axle-smoke-test' ),
			'post'        => __( 'Latest post', 'axle-smoke-test' ),
			'page'        => __( 'Latest page that is not the front page', 'axle-smoke-test' ),
			'category'    => __( 'A category archive', 'axle-smoke-test' ),
			'search'      => __( 'Search results', 'axle-smoke-test' ),
			'not_found'   => __( 'A page that does not exist (expects 404)', 'axle-smoke-test' ),
			'feed'        => __( 'RSS feed', 'axle-smoke-test' ),
			'rest'        => __( 'REST API index', 'axle-smoke-test' ),
			'login'       => __( 'Login page', 'axle-smoke-test' ),
			'dashboard'   => __( 'Dashboard', 'axle-smoke-test' ),
			'plugins'     => __( 'Plugins screen', 'axle-smoke-test' ),
			'woocommerce' => __( 'WooCommerce: shop, latest product, cart, checkout and my account', 'axle-smoke-test' ),
		);
		foreach ( $types as $type => $label ) {
			printf(
				'<label><input type="checkbox" name="settings[auto_pick][%1$s]" value="1"%2$s> %3$s</label><br>',
				esc_attr( $type ),
				checked( ! empty( $s['auto_pick'][ $type ] ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '</section>';

		echo '<section class="axle-card">';
		echo '<h2 class="axle-card__title">' . esc_html__( 'Checks', 'axle-smoke-test' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		$this->checkbox_row( 'browser_tier', __( 'Browser check', 'axle-smoke-test' ), __( 'Also load the pages in a hidden frame of an admin\'s browser, for JavaScript errors and layout changes.', 'axle-smoke-test' ), $s );
		$this->number_row( 'text_threshold', __( 'Text change threshold', 'axle-smoke-test' ), __( 'Percent of the visible text that may change before a page turns amber.', 'axle-smoke-test' ), $s, 1, 100 );
		$this->number_row( 'layout_threshold', __( 'Layout threshold', 'axle-smoke-test' ), __( 'Pixels a landmark may move or resize before a page turns amber.', 'axle-smoke-test' ), $s, 1, 2000 );
		$this->number_row( 'blank_bytes', __( 'Blank page under', 'axle-smoke-test' ), __( 'Bytes of visible text under which a page counts as blank (red).', 'axle-smoke-test' ), $s, 0, 100000 );
		echo '<tr><th scope="row">' . esc_html__( 'Start a run after', 'axle-smoke-test' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Start a run after', 'axle-smoke-test' ) . '</legend>';
		$triggers = array(
			'update'  => __( 'Plugin, theme, core and translation updates', 'axle-smoke-test' ),
			'install' => __( 'Plugin installs and activations', 'axle-smoke-test' ),
			'theme'   => __( 'Theme switches', 'axle-smoke-test' ),
			'deploy'  => __( 'Deploys (code changed outside WordPress, checked every 5 minutes)', 'axle-smoke-test' ),
		);
		foreach ( $triggers as $trigger => $label ) {
			printf( '<label><input type="checkbox" name="settings[triggers][%1$s]" value="1"%2$s> %3$s</label><br>', esc_attr( $trigger ), checked( in_array( $trigger, (array) $s['triggers'], true ), true, false ), esc_html( $label ) );
		}
		echo '</fieldset></td></tr>';
		$this->checkbox_row( 'daily', __( 'Daily check', 'axle-smoke-test' ), __( 'Also run once a day, even when nothing changed.', 'axle-smoke-test' ), $s );
		echo '</table>';
		echo '</section>';

		echo '<section class="axle-card" id="ast-alerts">';
		echo '<h2 class="axle-card__title">' . esc_html__( 'Alerts', 'axle-smoke-test' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="ast-alert-on">' . esc_html__( 'Alert on', 'axle-smoke-test' ) . '</label></th><td><select id="ast-alert-on" name="settings[alert_on]">';
		$levels = array(
			'red'   => __( 'Red runs', 'axle-smoke-test' ),
			'amber' => __( 'Red and amber runs', 'axle-smoke-test' ),
			'never' => __( 'Never', 'axle-smoke-test' ),
		);
		foreach ( $levels as $level => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $level ), selected( $s['alert_on'], $level, false ), esc_html( $label ) );
		}
		echo '</select><p class="description">' . esc_html__( 'The same page and verdict alert at most once an hour.', 'axle-smoke-test' ) . '</p></td></tr>';
		$this->checkbox_row( 'email', __( 'Email', 'axle-smoke-test' ), __( 'Send an email.', 'axle-smoke-test' ), $s );
		echo '<tr><th scope="row"><label for="ast-emails">' . esc_html__( 'Email addresses', 'axle-smoke-test' ) . '</label></th><td><input type="text" class="regular-text" id="ast-emails" name="settings[emails]" value="' . esc_attr( (string) $s['emails'] ) . '" aria-describedby="ast-emails-help"><p class="description" id="ast-emails-help">' . esc_html__( 'Comma separated. Empty: the site admin email.', 'axle-smoke-test' ) . '</p></td></tr>';

		$webhook = $this->settings->secret( 'webhook_url' );
		echo '<tr><th scope="row"><label for="ast-webhook">' . esc_html__( 'Webhook URL', 'axle-smoke-test' ) . '</label></th><td><input type="url" class="regular-text" id="ast-webhook" name="settings[webhook_url]" value="" autocomplete="off" aria-describedby="ast-webhook-help">';
		echo '<p class="description" id="ast-webhook-help">' . ( '' !== $webhook
			/* translators: %s: masked webhook URL. */
			? esc_html( sprintf( __( 'Saved, encrypted: %s. Leave empty to keep it.', 'axle-smoke-test' ), Crypto::mask( $webhook ) ) )
			: esc_html__( 'Optional. Stored encrypted.', 'axle-smoke-test' ) ) . '</p>';
		if ( '' !== $webhook ) {
			echo '<label><input type="checkbox" name="settings[clear_webhook_url]" value="1"> ' . esc_html__( 'Remove the saved webhook', 'axle-smoke-test' ) . '</label>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="ast-webhook-format">' . esc_html__( 'Webhook format', 'axle-smoke-test' ) . '</label></th><td><select id="ast-webhook-format" name="settings[webhook_format]">';
		printf( '<option value="json"%1$s>%2$s</option>', selected( $s['webhook_format'], 'json', false ), esc_html__( 'Generic JSON', 'axle-smoke-test' ) );
		printf( '<option value="slack"%1$s>%2$s</option>', selected( $s['webhook_format'], 'slack', false ), esc_html__( 'Slack compatible', 'axle-smoke-test' ) );
		echo '</select></td></tr>';
		$this->checkbox_row( 'notice', __( 'Admin notice', 'axle-smoke-test' ), __( 'Show a notice in wp-admin until it is dismissed or a green run follows.', 'axle-smoke-test' ), $s );
		echo '</table>';
		echo '</section>';

		$this->role_card( $s );

		echo '<section class="axle-card">';
		echo '<h2 class="axle-card__title">' . esc_html__( 'Rollback', 'axle-smoke-test' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		$this->checkbox_row( 'auto_rollback', __( 'Auto rollback', 'axle-smoke-test' ), __( 'Roll back by itself after a red run, when the errors come from the updated plugin or theme, or only one item was updated. Database changes are not undone.', 'axle-smoke-test' ), $s );
		echo '</table>';
		echo '</section>';

		echo '<section class="axle-card">';
		echo '<h2 class="axle-card__title">' . esc_html__( 'Access', 'axle-smoke-test' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		$this->checkbox_row( 'cron_admin_checks', __( 'wp-admin checks from cron', 'axle-smoke-test' ), __( 'Runs started by WP-Cron or WP-CLI open wp-admin URLs as the user below, with a single-use login that lasts 60 seconds. Off: they only check that the login page loads.', 'axle-smoke-test' ), $s );
		echo '<tr><th scope="row"><label for="ast-cron-user">' . esc_html__( 'Cron user', 'axle-smoke-test' ) . '</label></th><td>';
		wp_dropdown_users(
			array(
				'name'              => 'settings[cron_user]',
				'id'                => 'ast-cron-user',
				'selected'          => (int) $s['cron_user'],
				'role__in'          => array( 'administrator' ),
				'show_option_none'  => __( 'Nobody', 'axle-smoke-test' ),
				'option_none_value' => 0,
			)
		);
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="ast-auth-user">' . esc_html__( 'Basic auth user', 'axle-smoke-test' ) . '</label></th><td><input type="text" class="regular-text" id="ast-auth-user" name="settings[auth_user]" value="' . esc_attr( (string) $s['auth_user'] ) . '" autocomplete="off"><p class="description">' . esc_html__( 'For staging sites behind a password prompt.', 'axle-smoke-test' ) . '</p></td></tr>';
		$has_pass = '' !== $this->settings->secret( 'auth_pass' );
		echo '<tr><th scope="row"><label for="ast-auth-pass">' . esc_html__( 'Basic auth password', 'axle-smoke-test' ) . '</label></th><td><input type="password" class="regular-text" id="ast-auth-pass" name="settings[auth_pass]" value="" autocomplete="new-password"><p class="description">' . ( $has_pass ? esc_html__( 'Saved, encrypted. Leave empty to keep it.', 'axle-smoke-test' ) : esc_html__( 'Stored encrypted.', 'axle-smoke-test' ) ) . '</p>';
		if ( $has_pass ) {
			echo '<label><input type="checkbox" name="settings[clear_auth_pass]" value="1"> ' . esc_html__( 'Remove the saved password', 'axle-smoke-test' ) . '</label>';
		}
		echo '</td></tr>';
		echo '</table>';
		echo '</section>';

		echo '<section class="axle-card">';
		echo '<h2 class="axle-card__title">' . esc_html__( 'Retention', 'axle-smoke-test' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		$this->number_row( 'retention_days', __( 'Keep runs for', 'axle-smoke-test' ), __( 'Days. Baselines are always kept.', 'axle-smoke-test' ), $s, 1, 3650 );
		$this->number_row( 'retention_runs', __( 'Keep at most', 'axle-smoke-test' ), __( 'Runs.', 'axle-smoke-test' ), $s, 10, 100000 );
		echo '</table>';
		echo '</section>';

		submit_button( __( 'Save settings', 'axle-smoke-test' ) );
		echo '</form>';

		$this->mu_section();
	}

	/**
	 * Pages a shop or a multilingual site depends on, with one button to add them.
	 */
	private function suggestions_section(): void {
		$suggestions = Presets::suggestions( $this->urls );
		if ( ! $suggestions ) {
			return;
		}

		echo '<section class="axle-card" id="ast-suggestions" aria-labelledby="ast-suggestions-title">';
		echo '<h2 class="axle-card__title" id="ast-suggestions-title">' . esc_html__( 'Suggested pages', 'axle-smoke-test' ) . '</h2>';
		echo '<p>' . esc_html__( 'These pages are not checked yet. Each comes with rules for what it must show. They are only loaded, never filled in: nothing is submitted, added to a cart or ordered.', 'axle-smoke-test' ) . '</p>';
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Suggested pages', 'axle-smoke-test' ) . '">';
		echo '<table class="widefat striped ast-suggestions"><thead><tr><th scope="col">' . esc_html__( 'Page', 'axle-smoke-test' ) . '</th><th scope="col">' . esc_html__( 'URL', 'axle-smoke-test' ) . '</th><th scope="col">' . esc_html__( 'Why', 'axle-smoke-test' ) . '</th></tr></thead><tbody>';
		foreach ( $suggestions as $suggestion ) {
			echo '<tr><th scope="row">' . esc_html( (string) $suggestion['label'] ) . '</th><td><code>' . esc_html( (string) wp_parse_url( (string) $suggestion['url'], PHP_URL_PATH ) ) . '</code></td><td>' . esc_html( (string) $suggestion['reason'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		Page::post_button( 'add_suggestions', __( 'Add these pages', 'axle-smoke-test' ), array(), 'button button-primary' );
		echo '</section>';
	}

	/**
	 * The key URL list with rules, add and remove.
	 */
	private function urls_section(): void {
		echo '<section class="axle-card" id="ast-urls">';
		echo '<h2 class="axle-card__title">' . esc_html__( 'Key URLs', 'axle-smoke-test' ) . '</h2>';
		/* translators: %d: maximum number of URLs. */
		echo '<p>' . esc_html( sprintf( __( 'Every run checks these, up to %d. Pages picked from the site follow the boxes below; add your own for checkout, landing pages or anything that must never break.', 'axle-smoke-test' ), Urls::max() ) ) . '</p>';
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Key URLs', 'axle-smoke-test' ) . '">';
		echo '<table class="widefat striped ast-urls"><thead><tr>';
		foreach ( array( __( 'Page', 'axle-smoke-test' ), __( 'URL', 'axle-smoke-test' ), __( 'Expected status', 'axle-smoke-test' ), __( 'Browser check', 'axle-smoke-test' ), __( 'Source', 'axle-smoke-test' ), __( 'Rules', 'axle-smoke-test' ), __( 'Actions', 'axle-smoke-test' ) ) as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $this->urls->all() as $url ) {
			echo '<tr data-key="' . esc_attr( $url['key'] ) . '">';
			echo '<th scope="row">' . esc_html( $url['label'] ) . '</th>';
			echo '<td><code>' . esc_html( (string) wp_parse_url( $url['url'], PHP_URL_PATH ) . ( wp_parse_url( $url['url'], PHP_URL_QUERY ) ? '?' . wp_parse_url( $url['url'], PHP_URL_QUERY ) : '' ) ) . '</code></td>';
			echo '<td>' . esc_html( implode( ', ', $url['expected'] ) ) . '</td>';
			echo '<td>' . ( $url['browser'] ? esc_html__( 'Yes', 'axle-smoke-test' ) : esc_html__( 'No', 'axle-smoke-test' ) ) . '</td>';
			echo '<td>' . ( 'custom' === $url['source'] ? esc_html__( 'Added by you', 'axle-smoke-test' ) : esc_html__( 'Picked from the site', 'axle-smoke-test' ) ) . '</td>';
			echo '<td>';
			$this->rules_cell( $url );
			echo '</td>';
			echo '<td>';
			if ( 'custom' === $url['source'] ) {
				Page::confirm_button(
					'remove_url',
					__( 'Remove', 'axle-smoke-test' ),
					array( 'key' => $url['key'] ),
					__( 'This page is no longer checked, and its rules are deleted. Its past results stay. You can undo it right after.', 'axle-smoke-test' ),
					__( 'Remove this URL', 'axle-smoke-test' ),
					'button-link button-link-delete',
					/* translators: %s: page label. */
					sprintf( __( 'Remove %s', 'axle-smoke-test' ), $url['label'] )
				);
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '</section>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="axle-card ast-add-url">';
		wp_nonce_field( 'axle_smoke_test_add_url' );
		echo '<input type="hidden" name="action" value="axle_smoke_test_add_url">';
		echo '<h2 class="axle-card__title">' . esc_html__( 'Add a URL', 'axle-smoke-test' ) . '</h2>';
		echo '<p><label for="ast-url">' . esc_html__( 'URL or path', 'axle-smoke-test' ) . '</label><br><input type="text" class="regular-text" id="ast-url" name="url" placeholder="/checkout/" required></p>';
		echo '<p><label for="ast-label">' . esc_html__( 'Label', 'axle-smoke-test' ) . '</label><br><input type="text" class="regular-text" id="ast-label" name="label"></p>';
		echo '<p><label for="ast-expected">' . esc_html__( 'Expected status', 'axle-smoke-test' ) . '</label><br><input type="text" class="small-text" id="ast-expected" name="expected" value="200" aria-describedby="ast-expected-help"> <span class="description" id="ast-expected-help">' . esc_html__( 'One or more, like 200 or 200, 302.', 'axle-smoke-test' ) . '</span></p>';
		echo '<p><label><input type="checkbox" name="browser" value="1" checked> ' . esc_html__( 'Include in the browser check', 'axle-smoke-test' ) . '</label></p>';
		submit_button( __( 'Add URL', 'axle-smoke-test' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The rules of a URL: the ones built in, and a form for the person's own.
	 *
	 * @param array $url The URL entry.
	 */
	private function rules_cell( array $url ): void {
		$own   = (array) ( $url['own_rules'] ?? array() );
		$all   = (array) ( $url['rules'] ?? array() );
		$count = count( (array) ( $all['must'] ?? array() ) ) + count( (array) ( $all['must_not'] ?? array() ) );
		$id    = 'ast-rules-' . $url['key'];

		echo '<details class="ast-rules"><summary>' . esc_html(
			$count
				/* translators: %d: number of rules. */
				? sprintf( _n( '%d rule', '%d rules', $count, 'axle-smoke-test' ), $count )
				: __( 'Add rules', 'axle-smoke-test' )
		) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ast-rules-form">';
		wp_nonce_field( 'axle_smoke_test_save_rules' );
		echo '<input type="hidden" name="action" value="axle_smoke_test_save_rules"><input type="hidden" name="key" value="' . esc_attr( $url['key'] ) . '">';
		foreach ( array(
			'must'     => __( 'Must contain', 'axle-smoke-test' ),
			'must_not' => __( 'Must not contain', 'axle-smoke-test' ),
		) as $kind => $label ) {
			$built_in = array_diff( (array) ( $all[ $kind ] ?? array() ), (array) ( $own[ $kind ] ?? array() ) );
			echo '<p><label for="' . esc_attr( $id . '-' . $kind ) . '">' . esc_html( $label ) . '</label><br>';
			echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id . '-' . $kind ) . '" name="' . esc_attr( $kind ) . '" aria-describedby="' . esc_attr( $id . '-help' ) . '">' . esc_textarea( implode( "\n", (array) ( $own[ $kind ] ?? array() ) ) ) . '</textarea></p>';
			if ( $built_in ) {
				/* translators: %s: rules, separated by commas. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Built in: %s', 'axle-smoke-test' ), implode( ', ', array_map( array( Structure::class, 'plain' ), $built_in ) ) ) ) . '</p>';
			}
		}
		echo '<p class="description" id="' . esc_attr( $id . '-help' ) . '">' . esc_html__( 'One rule on each line. Plain text, or a simple CSS selector such as .buy-button or form#contact.', 'axle-smoke-test' ) . '</p>';
		submit_button( __( 'Save rules', 'axle-smoke-test' ), 'secondary small', 'submit', false );
		echo '</form></details>';
	}

	/**
	 * Install or remove the early collector.
	 */
	private function mu_section(): void {
		echo '<section class="axle-card">';
		echo '<h2 class="axle-card__title">' . esc_html__( 'Early collector', 'axle-smoke-test' ) . '</h2>';
		echo '<p>' . esc_html__( 'Without it, PHP warnings raised while other plugins load are only seen through debug.log or as fatal errors. With it, one small file in wp-content/mu-plugins starts the collector first, on smoke test requests only. It is removed when this plugin is deactivated.', 'axle-smoke-test' ) . '</p>';
		$installed = MuPlugin::installed();
		echo '<p>' . ( $installed ? '<span class="axle-badge axle-badge--good">' . esc_html__( 'Installed', 'axle-smoke-test' ) . '</span>' : '<span class="axle-badge axle-badge--neutral">' . esc_html__( 'Not installed', 'axle-smoke-test' ) . '</span>' ) . '</p>';
		if ( $installed ) {
			Page::confirm_button(
				'mu_plugin',
				__( 'Remove the early collector', 'axle-smoke-test' ),
				array( 'do' => 'remove' ),
				__( 'This deletes the small file from wp-content/mu-plugins. PHP warnings raised while other plugins load are then only seen through debug.log. You can install it again at any time.', 'axle-smoke-test' ),
				__( 'Remove the file', 'axle-smoke-test' )
			);
		} else {
			Page::post_button( 'mu_plugin', __( 'Install the early collector', 'axle-smoke-test' ), array( 'do' => 'install' ), 'button' );
		}
		echo '</section>';
	}

	/**
	 * The card for checking pages as a role.
	 *
	 * @param array $s Settings.
	 */
	private function role_card( array $s ): void {
		$roles = RoleUser::roles();
		echo '<section class="axle-card" id="ast-roles">';
		echo '<h2 class="axle-card__title">' . esc_html__( 'Check as a role', 'axle-smoke-test' ) . '</h2>';
		echo '<p>' . esc_html__( 'Open the dashboard and account pages as a test user with the role you choose, to catch a page that breaks for editors or customers but not for admins. The user is made by this plugin, has no password and cannot sign in. It is removed when you switch this off or uninstall the plugin. Pages are only viewed: nothing is submitted.', 'axle-smoke-test' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$this->checkbox_row( 'role_checks', __( 'Check as a role', 'axle-smoke-test' ), __( 'Check the pages below as a test user.', 'axle-smoke-test' ), $s );
		echo '<tr><th scope="row"><label for="ast-role">' . esc_html__( 'Role', 'axle-smoke-test' ) . '</label></th><td><select id="ast-role" name="settings[role_check_role]">';
		foreach ( $roles as $slug => $name ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $slug ), selected( $s['role_check_role'], (string) $slug, false ), esc_html( translate_user_role( $name ) ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Pages', 'axle-smoke-test' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Pages to check as the role', 'axle-smoke-test' ) . '</legend>';
		foreach ( RoleUser::pages() as $name => $page ) {
			printf(
				'<label><input type="checkbox" name="settings[role_check_pages][%1$s]" value="1"%2$s> %3$s</label><br>',
				esc_attr( (string) $name ),
				checked( in_array( (string) $name, (array) $s['role_check_pages'], true ), true, false ),
				esc_html( (string) $page['label'] )
			);
		}
		echo '</fieldset></td></tr>';
		echo '</table>';
		echo '</section>';
	}

	/**
	 * A checkbox row.
	 *
	 * @param string $key         Setting.
	 * @param string $label       Label.
	 * @param string $description Description.
	 * @param array  $s           Settings.
	 */
	private function checkbox_row( string $key, string $label, string $description, array $s ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="settings[%2$s]" value="1"%3$s> %4$s</label></td></tr>',
			esc_html( $label ),
			esc_attr( $key ),
			checked( ! empty( $s[ $key ] ), true, false ),
			esc_html( $description )
		);
	}

	/**
	 * A number row.
	 *
	 * @param string $key         Setting.
	 * @param string $label       Label.
	 * @param string $description Description.
	 * @param array  $s           Settings.
	 * @param int    $min         Minimum.
	 * @param int    $max         Maximum.
	 */
	private function number_row( string $key, string $label, string $description, array $s, int $min, int $max ): void {
		$id = 'ast-' . str_replace( '_', '-', $key );
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" class="small-text" id="%1$s" name="settings[%3$s]" value="%4$d" min="%5$d" max="%6$d" aria-describedby="%1$s-help"> <span class="description" id="%1$s-help">%7$s</span></td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $key ),
			(int) $s[ $key ],
			(int) $min,
			(int) $max,
			esc_html( $description )
		);
	}
}
