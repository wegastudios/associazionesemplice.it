<?php
namespace Portale\Plugin;

/**
 * Catalogo dei piani del plugin (formato «catalogo/v1», docs/ARCHITETTURA_DOMINI.md §4.1).
 * È l'UNICA porta d'ingresso del negozio e dell'area clienti verso piani, prezzi e limiti del plugin:
 * niente query dirette alle tabelle dei piani, niente lettura di `plans.*` da Config fuori da `Portale\Plugin`.
 */
interface Catalog {

	const SCHEMA = 'catalogo/v1';

	/** Documento completo (stesso formato dell'API pubblica `/wordpress/api/catalogo/v1`). */
	public function document(): array;

	/** Un piano per codice stabile (es. `procontabile`), o null se non esiste o non è visibile. */
	public function plan( string $code ): ?array;

	/** Prezzo di un piano per periodo (`month`|`year`): `{cents, currency}`, o null se non in vendita. */
	public function price( string $code, string $period ): ?array;
}
