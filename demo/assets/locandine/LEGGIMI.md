# Locandine della demo

Un file per titolo: `<slug del titolo>.webp` (lo slug è quello di WordPress, per esempio «Concerto d'autunno» → `concerto-dautunno.webp`).

- Formato WebP verticale 1000×1250, leggero (max ~150 KB).
- La stessa immagine compare anche nella scheda a 16/9, tagliata dall'alto: il soggetto va nella metà alta.
- Niente persone riconoscibili, marchi o testo scritto.
- Il testo alternativo si mette da solo («Immagine illustrativa per «titolo»»).

`extra_poster()` in `extra-data.php` usa il file se c'è (a ogni azzeramento), altrimenti la locandina inventata. Per assegnarle agli eventi già presenti senza azzerare:
`ASEM_LOCANDINE_SECCO=1 wp eval-file ~/demo-provision/locandine.php` (solo elenco), poi `wp eval-file ~/demo-provision/locandine.php`.
Sul server la cartella è `~/demo-provision/locandine/`.
