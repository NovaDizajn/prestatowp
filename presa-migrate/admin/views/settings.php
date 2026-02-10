<?php
/**
 * Admin view: Presa Migrate settings (URL + API key).
 *
 * @package Presa_Migrate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$url     = get_option( 'presa_prestashop_url', '' );
$key     = get_option( 'presa_prestashop_api_key', '' );
$mode    = get_option( 'presa_prestashop_api_mode', 'api' );
$db_host = get_option( 'presa_db_host', '' );
$db_user = get_option( 'presa_db_user', '' );
$db_pass = get_option( 'presa_db_password', '' );
$db_name = get_option( 'presa_db_name', '' );
$db_prefix   = get_option( 'presa_db_prefix', 'ps_' );
$db_lang_iso = get_option( 'presa_db_lang_iso', 'sr' );
$presa_lang_id = (int) get_option( 'presa_lang_id', 2 );
$presa_lang_id = $presa_lang_id >= 1 ? $presa_lang_id : 2;
$db_shop_id  = get_option( 'presa_db_shop_id', '' );
?>
<form method="post" action="options.php" id="presa-migrate-settings-form">
	<?php settings_fields( 'presa_migrate_settings' ); ?>
	<table class="form-table">
		<tr>
			<th scope="row">
				<label for="presa_prestashop_url"><?php esc_html_e( 'PrestaShop URL', 'presa-migrate' ); ?></label>
			</th>
			<td>
				<input type="url"
					id="presa_prestashop_url"
					name="presa_prestashop_url"
					value="<?php echo esc_attr( $url ); ?>"
					class="regular-text"
					placeholder="https://moj-prestashop.rs" />
				<p class="description"><?php esc_html_e( 'Osnovni URL PrestaShop sajta (bez / na kraju).', 'presa-migrate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="presa_prestashop_api_key"><?php esc_html_e( 'API ključ', 'presa-migrate' ); ?></label>
			</th>
			<td>
				<input type="password"
					id="presa_prestashop_api_key"
					name="presa_prestashop_api_key"
					value="<?php echo esc_attr( $key ); ?>"
					class="regular-text"
					autocomplete="off" />
				<p class="description"><?php esc_html_e( 'Webservice ključ (Advanced Parameters → Web service u PrestaShop adminu).', 'presa-migrate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="presa_prestashop_api_mode"><?php esc_html_e( 'Način pristupa API-ju', 'presa-migrate' ); ?></label>
			</th>
			<td>
				<select id="presa_prestashop_api_mode" name="presa_prestashop_api_mode">
					<option value="api" <?php selected( $mode, 'api' ); ?>><?php esc_html_e( 'Standard (/api/) — kada URL prepisivanje radi', 'presa-migrate' ); ?></option>
					<option value="dispatcher" <?php selected( $mode, 'dispatcher' ); ?>><?php esc_html_e( 'Dispatcher (webservice/dispatcher.php) — ako dobijate 404', 'presa-migrate' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Ako „Testiraj konekciju” vraća 404, izaberite „Dispatcher”. PrestaShop onda koristi webservice/dispatcher.php umesto /api/.', 'presa-migrate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Test konekcije', 'presa-migrate' ); ?></th>
			<td>
				<button type="button" id="presa-test-connection" class="button">
					<?php esc_html_e( 'Testiraj konekciju', 'presa-migrate' ); ?>
				</button>
				<span id="presa-test-result" class="presa-test-result" aria-live="polite"></span>
			</td>
		</tr>
	</table>

	<h2 class="title"><?php esc_html_e( 'PrestaShop baza (MySQL)', 'presa-migrate' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Za direktan import iz baze unesite kredencijale. URL iznad se koristi za preuzimanje slika.', 'presa-migrate' ); ?></p>
	<table class="form-table">
		<tr>
			<th scope="row"><label for="presa_db_host"><?php esc_html_e( 'DB host', 'presa-migrate' ); ?></label></th>
			<td>
				<input type="text" id="presa_db_host" name="presa_db_host" value="<?php echo esc_attr( $db_host ); ?>"
					class="regular-text" placeholder="localhost" />
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="presa_db_user"><?php esc_html_e( 'DB korisnik', 'presa-migrate' ); ?></label></th>
			<td>
				<input type="text" id="presa_db_user" name="presa_db_user" value="<?php echo esc_attr( $db_user ); ?>"
					class="regular-text" autocomplete="off" />
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="presa_db_password"><?php esc_html_e( 'DB lozinka', 'presa-migrate' ); ?></label></th>
			<td>
				<input type="password" id="presa_db_password" name="presa_db_password" value="<?php echo esc_attr( $db_pass ); ?>"
					class="regular-text" autocomplete="new-password" />
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="presa_db_name"><?php esc_html_e( 'DB ime', 'presa-migrate' ); ?></label></th>
			<td>
				<input type="text" id="presa_db_name" name="presa_db_name" value="<?php echo esc_attr( $db_name ); ?>"
					class="regular-text" />
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="presa_db_prefix"><?php esc_html_e( 'Prefiks tabela', 'presa-migrate' ); ?></label></th>
			<td>
				<input type="text" id="presa_db_prefix" name="presa_db_prefix" value="<?php echo esc_attr( $db_prefix ); ?>"
					class="small-text" placeholder="ps_" />
				<p class="description"><?php esc_html_e( 'Npr. ps_ za PrestaShop 1.6/1.7.', 'presa-migrate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="presa_db_lang_iso"><?php esc_html_e( 'Jezik (ISO kod)', 'presa-migrate' ); ?></label></th>
			<td>
				<input type="text" id="presa_db_lang_iso" name="presa_db_lang_iso" value="<?php echo esc_attr( $db_lang_iso ); ?>"
					class="small-text" placeholder="sr" maxlength="5" />
				<p class="description"><?php esc_html_e( 'ISO kod jezika iz ps_lang (npr. sr, en). Podrazumevano: sr.', 'presa-migrate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="presa_lang_id"><?php esc_html_e( 'Language ID (id_lang)', 'presa-migrate' ); ?></label></th>
			<td>
				<input type="number" id="presa_lang_id" name="presa_lang_id" value="<?php echo esc_attr( $presa_lang_id ); ?>"
					class="small-text" min="1" step="1" />
				<p class="description"><?php esc_html_e( 'PrestaShop id_lang used for *_lang tables (product_lang, category_lang, attribute_lang, feature_lang, manufacturer_lang).', 'presa-migrate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="presa_db_shop_id"><?php esc_html_e( 'ID shopa (opciono)', 'presa-migrate' ); ?></label></th>
			<td>
				<input type="number" id="presa_db_shop_id" name="presa_db_shop_id" value="<?php echo esc_attr( $db_shop_id ); ?>"
					class="small-text" min="1" step="1" placeholder="" />
				<p class="description"><?php esc_html_e( 'Za multistore: id_shop iz ps_shop. Prazno = prvi aktivan shop.', 'presa-migrate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Test konekcije na bazu', 'presa-migrate' ); ?></th>
			<td>
				<button type="button" id="presa-test-db-connection" class="button">
					<?php esc_html_e( 'Testiraj konekciju na bazu', 'presa-migrate' ); ?>
				</button>
				<span id="presa-test-db-result" class="presa-test-result" aria-live="polite"></span>
			</td>
		</tr>
	</table>
	<?php submit_button( __( 'Sačuvaj podešavanja', 'presa-migrate' ) ); ?>
</form>
