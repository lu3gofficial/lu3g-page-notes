# LU3G Page Notes

Note sulle pagine del sito, direttamente dal frontend. Si disegna una zona della pagina, si scrive un commento e i colleghi rispondono, sempre guardando il sito.

## Uso

1. Collegati al sito con un utente che ha il permesso (di serie: editor e amministratori).
2. Nella barra admin in alto premi **Note LU3G**: compaiono le zone già commentate e una barra in basso a destra.
3. **+ Nuova nota**, poi trascina per disegnare la zona (un clic crea una zona piccola), scrivi il testo e premi **Salva nota** (o Ctrl+Invio).
4. Clicca una zona per leggere la nota, rispondere, segnarla come **risolta** o riaprirla. Chi l'ha scritta (o un amministratore) può modificarla ed eliminarla.
5. **Elenco** mostra tutte le note della pagina; la spunta **Risolte** mostra anche quelle chiuse.

In bacheca il menu **Note LU3G** elenca le note di tutto il sito, con filtro per stato e il link **Apri sul sito** che apre la pagina con la nota già aperta (`?lu3g-note=ID`).

## Impostazioni

*Impostazioni → Note LU3G*: chi vede e scrive le note (solo amministratori / editor e amministratori / anche autori e collaboratori). I visitatori non vedono niente e non scaricano nessun file del plugin; l'API risponde solo agli utenti con il permesso.

## Come funziona

| File | Contenuto |
|---|---|
| `lu3g-page-notes.php` | Bootstrap, intestazione con `Update URI`, costanti `LU3G_PN_*` |
| `includes/class-notes.php` | Tipi di contenuto `lu3g_note` (nota) e `lu3g_note_reply` (risposta, figlia della nota), permessi, chiave della pagina, formato per l'API |
| `includes/class-rest.php` | API `lu3g-notes/v1`: elenco, creazione, modifica, eliminazione di note e risposte |
| `includes/class-frontend.php` | Pulsante nella barra admin e caricamento degli asset solo per chi ha il permesso |
| `includes/class-admin.php` | Elenco in bacheca, filtro per stato, riquadro con le risposte, impostazioni |
| `includes/class-updater.php` | Aggiornamenti dalle release GitHub, come negli altri plugin LU3G |
| `assets/js/page-notes.js` | Livello delle note: disegno, popover, risposte, elenco (JavaScript senza dipendenze) |
| `assets/css/page-notes.css` | Stili, tutti sotto `.lu3g-pn-*` |

**Pagina.** Le note si legano all'indirizzo, ridotto a percorso relativo alla home senza parametri di tracciamento (`/chi-siamo/?utm_source=x` → `chi-siamo`). Funziona per pagine, articoli, archivi e prodotti.

**Posizione delle zone.** Ogni zona si salva in due modi:
- agganciata all'elemento della pagina che la contiene (selettore CSS che preferisce `id` e `data-id` di Elementor) con coordinate in frazioni del suo riquadro: se la finestra si stringe o si passa al mobile, la zona segue l'elemento;
- in coordinate della pagina intera, usate se l'elemento non c'è più o è nascosto a quella larghezza. In quel caso la zona ha il bordo puntinato e la nota dice che la posizione è approssimativa.

**Hook** per estensioni: `lu3g_page_notes_created( $note_id )`, `lu3g_page_notes_replied( $reply_id, $note_id )`, filtri `lu3g_page_notes_capability` e `lu3g_page_notes_active`.

## Rilascio

Come gli altri plugin LU3G: aggiorna `Version:` e `LU3G_PN_VERSION` in `lu3g-page-notes.php`, `Stable tag:` in `readme.txt`, aggiungi la voce in `CHANGELOG.md`, poi invia il tag `vX.Y.Z`. La GitHub Action controlla la versione, crea `lu3g-page-notes.zip` con `bin/build.sh` e pubblica la release.

LU3G Hub lo trova da solo se il repository è pubblico, ha la proprietà `Plugin_Wordpress` = `yes` e l'ultima release ha lo zip.
