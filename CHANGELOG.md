# Registro delle modifiche

## 1.0.1
- `includes/class-updater.php` ora è generato dal modello comune di [lu3g-updater](https://github.com/lu3gofficial/lu3g-updater) (1.0.0), a partire dal nuovo file `.lu3g-updater`. Il codice dell'updater è lo stesso di prima: cambia solo il commento in cima.
- Release avviabile anche da GitHub, senza computer: *Actions → Release → Run workflow* sul branch `main`. Il tag lo crea l'automazione dalla `Version` del plugin, e si ferma se quella versione è già pubblicata.

## 1.0.0
- Prima versione: note disegnate sulle pagine dal frontend, con risposte, stato aperta/risolta, modifica ed eliminazione.
- Zone agganciate agli elementi della pagina (id, data-id di Elementor o percorso CSS) con posizione di riserva sulla pagina intera.
- Pulsante nella barra admin con il numero di note aperte, elenco delle note della pagina, link diretto a una nota (`?lu3g-note=ID`).
- Bacheca: menu "Note LU3G" con pagina, stato e risposte, filtro aperte/risolte; impostazioni per scegliere chi usa le note.
- API REST `lu3g-notes/v1`, riservata agli utenti con il permesso.
- Aggiornamenti automatici dalle release GitHub, come gli altri plugin LU3G.
