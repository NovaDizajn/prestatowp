=== Presa Migrate — PrestaShop to WooCommerce ===

Contributors: Presa
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Migrira proizvode sa PrestaShop-a na WooCommerce (naslov, opis, kategorije, slike, cena i ostali podaci).

== Description ==

* Unesite URL PrestaShop sajta i API ključ (Webservice key iz Advanced Parameters → Web service).
* Preuzmite listu proizvoda (crawl preko API-ja).
* Pokrenite migraciju u serijama; prenose se naslov, opis, kategorija, featured image, galerija, cena, SKU, stanje, dimenzije, EAN/UPC/ISBN.

Zahteva WooCommerce.

== Installation ==

1. Kopirajte folder `presa-migrate` u `wp-content/plugins/`.
2. Aktivirajte plugin u WordPress adminu.
3. U meniju "Presa Migrate" unesite PrestaShop URL i API ključ, pa koristite "Preuzmi listu proizvoda" i "Pokreni migraciju".

== PrestaShop priprema ==

* U PrestaShop adminu: Advanced Parameters → Web service → uključite "Enable PrestaShop Webservice".
* Kreirajte novi webservice key sa pravima za products, categories, images (bar GET).
