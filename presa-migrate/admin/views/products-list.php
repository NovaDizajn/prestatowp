<?php
/**
 * Admin view: Lista proizvoda (crawl) i migracija.
 *
 * @package Presa_Migrate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$url       = get_option( 'presa_prestashop_url', '' );
$key       = get_option( 'presa_prestashop_api_key', '' );
$db_host   = get_option( 'presa_db_host', '' );
$db_user   = get_option( 'presa_db_user', '' );
$db_name   = get_option( 'presa_db_name', '' );
$configured    = ! empty( $url ) && ! empty( $key );
$db_configured = ! empty( $db_host ) && ! empty( $db_user ) && ! empty( $db_name );
?>
<?php if ( $db_configured ) : ?>
<div class="presa-db-import-section presa-products-section" style="margin-bottom: 2em;">
	<h2 class="title"><?php esc_html_e( 'Import iz PrestaShop baze', 'presa-migrate' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Direktan import iz MySQL baze. Unesite kredencijale na tabu Podešavanja i testiraj konekciju. URL PrestaShop-a (iz podešavanja) koristi se za preuzimanje slika.', 'presa-migrate' ); ?></p>
	<p>
		<label class="presa-update-existing-wrap" style="display: inline-block; margin-right: 12px;">
			<input type="checkbox" id="presa-import-db-update-existing" value="1" />
			<?php esc_html_e( 'Ažuriraj postojeće proizvode', 'presa-migrate' ); ?>
		</label>
		<button type="button" id="presa-import-db-10" class="button button-primary">
			<?php esc_html_e( 'Import products (10)', 'presa-migrate' ); ?>
		</button>
		<button type="button" id="presa-import-db-all" class="button">
			<?php esc_html_e( 'Import sve proizvode', 'presa-migrate' ); ?>
		</button>
		<button type="button" id="presa-import-db-stop" class="button" style="display: none;">
			<?php esc_html_e( 'Zaustavi', 'presa-migrate' ); ?>
		</button>
		<span id="presa-import-db-10-spinner" class="spinner" style="float: none; margin: 0 0 0 8px; display: none;"></span>
	</p>
	<p class="description" style="margin-top: 4px;">
		<?php esc_html_e( 'Ako uključite „Ažuriraj postojeće”, proizvodi koji već postoje (po PrestaShop ID-u ili SKU referenci) biće ažurirani umesto preskakanja.', 'presa-migrate' ); ?>
	</p>
	<div id="presa-import-db-result" class="notice" style="display: none; margin-top: 10px;"></div>
	<div id="presa-import-db-log-wrap" style="display: none; margin-top: 10px;">
		<h4><?php esc_html_e( 'Log (šta je preskočeno i zašto)', 'presa-migrate' ); ?></h4>
		<ul id="presa-import-db-log" class="presa-live-log" style="max-height: 300px; overflow-y: auto;"></ul>
	</div>
</div>
<?php endif; ?>
<div class="presa-delete-imported-section presa-products-section" style="margin-bottom: 2em; padding-top: 1.5em; border-top: 1px solid #c3c4c7;">
	<h2 class="title"><?php esc_html_e( 'Obriši uvezene podatke', 'presa-migrate' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Trajno briše sve proizvode i sve medije (slike) koje je Presa Migrate uveo. Ne može se poništiti.', 'presa-migrate' ); ?></p>
	<p>
		<button type="button" id="presa-delete-imported" class="button button-link-delete">
			<?php esc_html_e( 'Obriši sve uvezene proizvode i medije', 'presa-migrate' ); ?>
		</button>
		<span id="presa-delete-imported-spinner" class="spinner" style="float: none; margin: 0 0 0 8px; display: none;"></span>
	</p>
	<div id="presa-delete-imported-result" class="notice" style="display: none; margin-top: 10px;"></div>
</div>
<div class="presa-products-section">
	<?php if ( ! $configured ) : ?>
		<p class="notice notice-warning inline">
			<?php esc_html_e( 'Unesite PrestaShop URL i API ključ na tabu Podešavanja.', 'presa-migrate' ); ?>
		</p>
	<?php else : ?>
		<div class="presa-live-log-wrap">
			<h3 class="presa-live-log-title"><?php esc_html_e( 'Šta se dešava', 'presa-migrate' ); ?></h3>
			<ul id="presa-live-log" class="presa-live-log" aria-live="polite" role="log">
				<li class="presa-live-log-idle"><?php esc_html_e( 'Kliknite „Preuzmi listu proizvoda” da vidite korake.', 'presa-migrate' ); ?></li>
			</ul>
		</div>
		<p>
			<button type="button" id="presa-fetch-products" class="button button-primary">
				<?php esc_html_e( 'Preuzmi listu proizvoda', 'presa-migrate' ); ?>
			</button>
		</p>
		<p class="description presa-fetch-note">
			<?php esc_html_e( 'Jedan zahtev povlači sve proizvode sa PrestaShop-a. Kod puno proizvoda može trajati 30 sekundi do nekoliko minuta — ne zatvarajte stranicu.', 'presa-migrate' ); ?>
		</p>
		<p>
			<button type="button" id="presa-debug-product" class="button button-small">
				<?php esc_html_e( 'Debug: prikaži strukturu proizvoda 1', 'presa-migrate' ); ?>
			</button>
			<button type="button" id="presa-debug-last-batch" class="button button-small">
				<?php esc_html_e( 'Šta je poslato mapper-u (poslednji batch)', 'presa-migrate' ); ?>
			</button>
			<span id="presa-debug-result" class="presa-debug-result"></span>
		</p>
		<div id="presa-debug-last-batch-result" class="presa-debug-result" style="display:none; margin-top:8px;"></div>
		<?php if ( defined( 'PRESA_DEBUG' ) && PRESA_DEBUG ) : ?>
		<p class="description" style="margin-top:4px;">
			<?php
			/* translators: path to log file */
			echo esc_html( sprintf( __( 'PRESA_DEBUG uključen: log se upisuje u %s', 'presa-migrate' ), 'presa-migrate/debug.log' ) );
			?>
		</p>
		<?php endif; ?>
		<div id="presa-products-loading" class="presa-loading" style="display:none;">
			<span class="spinner is-active"></span>
			<?php esc_html_e( 'Učitavanje…', 'presa-migrate' ); ?>
		</div>
		<div id="presa-products-result" class="presa-products-result" style="display:none;">
			<p class="presa-products-count"></p>
			<div class="presa-products-table-wrap">
				<table class="wp-list-table widefat fixed striped presa-products-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'presa-migrate' ); ?></th>
							<th><?php esc_html_e( 'Naziv', 'presa-migrate' ); ?></th>
							<th><?php esc_html_e( 'Referenca', 'presa-migrate' ); ?></th>
							<th><?php esc_html_e( 'Cena', 'presa-migrate' ); ?></th>
							<th><?php esc_html_e( 'Aktivan', 'presa-migrate' ); ?></th>
						</tr>
					</thead>
					<tbody id="presa-products-tbody"></tbody>
				</table>
			</div>
			<p class="presa-migrate-actions">
				<button type="button" id="presa-run-migration-test" class="button button-primary">
					<?php esc_html_e( 'Test migracija (10 proizvoda)', 'presa-migrate' ); ?>
				</button>
				<button type="button" id="presa-run-migration" class="button" disabled>
					<?php esc_html_e( 'Pokreni punu migraciju (svi proizvodi)', 'presa-migrate' ); ?>
				</button>
			</p>
		</div>
		<div id="presa-migration-progress" class="presa-migration-progress" style="display:none;">
			<p><?php esc_html_e( 'Migracija u toku…', 'presa-migrate' ); ?></p>
			<progress id="presa-migration-progress-bar" value="0" max="100"></progress>
			<p id="presa-migration-status" class="presa-migration-status"></p>
			<p id="presa-migration-errors" class="presa-migration-errors" style="display:none;"></p>
		</div>
		<div id="presa-migration-done" class="notice notice-success" style="display:none;"></div>
	<?php endif; ?>
</div>
