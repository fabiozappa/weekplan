# Weekplan

Un tracciatore di routine settimanali da installare sul proprio server. Non è un
calendario: sono sette giorni fissi che pianifichi una volta e spunti ogni settimana.

[![Licenza: MIT](https://img.shields.io/badge/Licenza-MIT-blue.svg)](LICENSE)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)

🇬🇧 **[Read me in English](README.md)**

---

## Cos'è

Le app di calendario rispondono alla domanda *"cosa faccio il 14 marzo?"*. Weekplan
ne risponde un'altra: *"com'è fatto un martedì normale, e l'ho davvero rispettato?"*

Non ci sono date né calendario perpetuo. Ci sono sette giorni — da lunedì a
domenica — che si ripetono per sempre. Costruisci la griglia una volta, poi ogni
settimana spunti le attività e guardi un punteggio. Il lunedì il punteggio si
azzera e la griglia resta.

È un file PHP e un file HTML. Niente database, niente Composer, niente build,
niente npm. I dati stanno in un JSON che puoi leggere, modificare e salvare con `cp`.

### Cosa non è

- Non è un calendario: niente date, niente ricorrenze, niente eccezioni, niente
  "sposta questo a giovedì prossimo".
- Non è multiutente: una sola credenziale, una sola griglia.
- Non manda notifiche (ma può farlo il tuo calendario, tramite il
  [feed ICS](#sottoscrizione-da-calendario)).

Se ti serve una di queste cose, ti serve un calendario, non questo.

---

## Funzioni

- **Sette giorni fissi.** Schede sul telefono, griglia settimanale sul computer.
- **Timeline verticale** in stile Structured: card collegate da una linea, altezza
  proporzionale alla durata, tempo libero mostrato come spazio vuoto, una riga
  rossa sull'ora corrente.
- **Vista settimanale su desktop**: una colonna per giorno su un asse orario comune,
  con il punteggio del giorno (`12/21`) e quello della settimana (`47/129`).
- **Punteggio settimanale.** Le spunte durano tutta la settimana e si azzerano
  lunedì alle 00:00.
- **Rilevamento delle sovrapposizioni**: le attività che si accavallano vengono
  raggruppate, segnalate e disegnate affiancate.
- **Categorie** definite in un file di configurazione: etichetta e colore, tutto il
  resto lo deriva l'interfaccia.
- **Emoji e note** su ogni attività.
- **Copia di un giorno** su uno o più altri giorni.
- **Tema chiaro e scuro**, che segue il sistema finché non scegli.
- **Feed ICS** per vedere la routine dentro iCal, Google Calendar o Outlook,
  accanto agli impegni veri.
- **Backup rotativi** del file dati, uno al giorno.

---

## Requisiti

- **PHP 8.1 o superiore** (usa la sintassi dei callable di prima classe).
- Un server che esegua PHP: Apache, nginx con PHP-FPM, Caddy, o il server
  integrato di PHP per l'uso locale.
- L'estensione `intl` è consigliata ma non necessaria: senza, le emoji composte
  (👨‍💻, 🏳️‍🌈) vengono tagliate in modo meno preciso.
- Permessi di scrittura nella cartella del progetto, per `data.json` e `backups/`.

L'interfaccia carica Tailwind CSS e il font Inter da CDN, quindi al primo
caricamento serve una connessione. Tutto il resto è locale.

---

## Avvio rapido

```bash
git clone https://github.com/fabiozappa/weekplan.git
cd weekplan
cp config.example.php config.php
cp data.example.json data.json   # facoltativo: una settimana di esempio
php -S localhost:8000
```

Apri <http://localhost:8000> ed entra con le credenziali di `config.php`
(`admin` / `cambiami` finché non le cambi — cambiale).

> Il server integrato di PHP ignora `.htaccess`, quindi `data.json` sarebbe
> scaricabile. Usalo solo in locale.

---

## Installazione su un server

### Apache

Copia i file in una cartella servita da PHP e rendila scrivibile dall'utente del
web server:

```bash
chmod 775 .
```

L'`.htaccess` incluso blocca l'accesso diretto ai file `.json`, `.lock`, `.tmp` e
`.md`, a `config.php` e ad `app.html`, e disattiva l'elenco delle cartelle.
Richiede `AllowOverride All`, altrimenti Apache lo ignora — verifica con:

```bash
curl -o /dev/null -w '%{http_code}\n' https://tuo-host/config.php   # deve dare 403
```

### nginx

`.htaccess` non ha effetto: replica le regole nel server block.

```nginx
location ~* \.(json|lock|tmp|md)$ { deny all; }
location = /config.php            { deny all; }
location = /app.html              { deny all; }
location /backups/                { deny all; }
```

### HTTPS

Non serve per far funzionare l'app, ma serve in pratica per sottoscrivere il feed
ICS da telefono o da Google Calendar: quei servizi scaricano il feed dai propri
server, quindi l'app dev'essere raggiungibile da internet.

---

## Configurazione

Sta tutta in `config.php`, che restituisce un array PHP. È escluso da git ed
essendo un `.php` non viene mai servito come testo.

| Chiave | A cosa serve |
|---|---|
| `USERNAME`, `PASSWORD` | Credenziali di accesso, in chiaro. |
| `PASSWORD_HASH` | Facoltativo e consigliato: se valorizzato ha la precedenza su `PASSWORD`. |
| `SESSION_NAME`, `SESSION_LIFETIME` | Nome e durata del cookie di sessione (30 giorni). |
| `TIMEZONE` | Usato per il reset settimanale e per la riga dell'ora corrente. |
| `BACKUP_KEEP` | Quante copie giornaliere di `data.json` conservare (7). |
| `CATEGORIES` | Le categorie: chiave, etichetta e colore. |
| `ICS_TOKEN` | Segreto nell'URL del feed. Vuoto disattiva il feed. |
| `ICS_NAME` | Nome del calendario nei client. |
| `ICS_BUSY` | `true` segna gli eventi come occupato, `false` li mostra soltanto. |
| `ICS_ALARM_MINUTES` | Minuti di preavviso del promemoria, `null` per nessuno. |

Per generare un hash della password e un token del feed:

```bash
php -r "echo password_hash('la-tua-password', PASSWORD_DEFAULT), PHP_EOL;"
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
```

### Categorie

Se ne aggiunge una con una riga:

```php
'famiglia' => ['label' => 'Famiglia', 'color' => '#ec4899'],
```

Il colore è l'unica cosa da indicare: pallino, bordo, etichetta e barra del grafico
vengono derivati da lì, schiariti sul tema scuro e scuriti su quello chiaro. Il
server rifiuta le categorie che non compaiono qui. La chiave è ciò che finisce in
`data.json`, quindi rinominare una chiave già in uso fa ricadere quelle attività
su `altro`.

---

## Come si usa

### Vista giorno (telefono, e computer a richiesta)

Una timeline verticale. Ogni card mostra orari, durata, emoji, titolo, nota e
categoria. Tocca il cerchio per spuntare, tocca la card per modificare, scorri a
destra e sinistra per cambiare giorno. Il pulsante `+` aggiunge un'attività.

L'altezza della card segue la durata, entro due limiti: sotto i ~50 minuti sono
tutte uguali, perché più in basso non ci starebbe il titolo. I buchi fra
un'attività e l'altra diventano spazio vuoto con la durata indicata.

### Vista settimanale (computer, da 1024px di larghezza)

Sette colonne, il tempo che scorre dall'alto in basso, il punteggio di ogni giorno
nell'intestazione e il totale della settimana in alto. Le intestazioni restano
fisse mentre si scorre.

- **Clic** su un'attività per spuntarla, o per toglierla.
- **Clic destro** per modificarla.
- **Clic sul nome del giorno** per aprirlo nella vista giorno.
- **`+` accanto al giorno** per aggiungere un'attività lì.

L'asse verticale è condiviso da tutte le colonne ed è **lineare a tratti**: ogni
orario di inizio e fine è un punto fisso, quindi le 08:30 cadono alla stessa altezza
in ogni colonna, e ogni tratto è alto quanto la sua durata ma mai meno di un minimo
leggibile. Le attività brevi risultano quindi un po' più lunghe di quanto sono
rispetto a quelle lunghe; ordine e allineamento restano esatti. Le sovrapposizioni
si affiancano, calcolate per singolo gruppo, così una sola collisione non restringe
l'intera giornata.

### Copiare un giorno

Il pulsante accanto al nome del giorno copia le sue attività su uno o più altri
giorni. Le copie ricevono id nuovi e partono non spuntate. **Le attività già
presenti nei giorni di destinazione vengono sostituite, non aggiunte.**

---

## Punteggio e reset settimanale

Le spunte valgono da lunedì a domenica e si azzerano quando inizia una settimana
nuova: la domenica è l'ultimo giorno in cui contano, lunedì alle 00:00 si riparte da
zero. La griglia delle attività non viene mai toccata.

`data.json` contiene `week_start`, il lunedì della settimana a cui appartengono le
spunte salvate (settimana ISO, nel `TIMEZONE` configurato). Il controllo avviene
dentro ogni operazione, non solo alla lettura: se il primo gesto della settimana
nuova è una spunta fatta da una pagina rimasta aperta dalla domenica, finisce nella
settimana nuova invece di essere cancellata subito dopo.

**Non c'è storico**: passata la settimana, il punteggio precedente è perso. L'unica
traccia è il backup giornaliero, che però ruota dopo una settimana.

---

## Sottoscrizione da calendario

La routine si pubblica come calendario in sola lettura, che iCal, Google Calendar o
Outlook rileggono da soli. Serve a vedere gli impegni fissi accanto a quelli veri e
a piazzarci sopra call e appuntamenti.

Imposta `ICS_TOKEN` in `config.php` e usa il pulsante col calendario nell'header:
mostra l'indirizzo, un link `webcal://` che apre direttamente l'app Calendario, e le
caselle delle categorie.

```
https://tuo-host/index.php?action=ics&token=…
https://tuo-host/index.php?action=ics&token=…&categories=lavoro,progetti
```

Puoi creare più sottoscrizioni con gruppi di categorie diversi — una per il lavoro,
una personale — ognuna un calendario a sé con il suo colore e il suo interruttore.
Tieni i gruppi disgiunti, altrimenti le attività presenti in entrambi compaiono due
volte.

**Come è modellato.** Ogni attività è un evento settimanale ricorrente: `DTSTART`
su un lunedì fisso (1 gennaio 2024) più l'offset del giorno, poi
`RRULE:FREQ=WEEKLY`. L'ancora non va mai cambiata, altrimenti i client ricreano
tutti gli eventi. L'`UID` è l'id del task, quindi modificare un'attività aggiorna
l'evento invece di duplicarlo. Gli orari sono **floating** — niente `Z`, niente
`TZID` — così le 06:30 restano le 06:30 nel fuso del calendario che le mostra, cambi
d'ora compresi.

**L'URL è la credenziale.** La sottoscrizione avviene senza login, perché nessun
client di calendario sa autenticarsi. Chi ha l'indirizzo può leggere la routine (non
modificarla). Cambiare `ICS_TOKEN` revoca tutte le sottoscrizioni insieme.

---

## Dati e backup

`data.json` è l'intero database:

```json
{
  "week_start": "2026-01-05",
  "completion_status": { "lunedi": ["task-a1b2c3d4e5f60718"], "martedi": [] },
  "days": {
    "lunedi": [
      {
        "id": "task-a1b2c3d4e5f60718",
        "title": "Palestra",
        "time": "06:30",
        "end": "08:00",
        "color": "fitness",
        "icon": "🏋️",
        "note": "Allenamento pesi"
      }
    ],
    "martedi": []
  }
}
```

Le chiavi dei giorni sono `lunedi martedi mercoledi giovedi venerdi sabato domenica`.
Gli orari sono `HH:MM` con `end` successiva a `time`; un'attività non può mai
scavalcare la mezzanotte.

Le scritture sono atomiche — file temporaneo più `rename`, sotto lock esclusivo su
`data.lock` — quindi un'interruzione non può lasciare il file a metà, e due schede
del browser non possono sovrascriversi a vicenda. Se `data.json` risulta illeggibile
l'app si ferma con un errore chiaro invece di ripartire da zero e sovrascrivere la
settimana.

Prima della prima scrittura di ogni giornata il file viene copiato in
`backups/data.AAAA-MM-GG.json`, che contiene quindi lo stato con cui si è chiusa la
giornata precedente. Le copie oltre `BACKUP_KEEP` vengono eliminate. Per
ripristinare:

```bash
cp backups/data.2026-01-12.json data.json
```

---

## Sicurezza

Ragionevole per un'app a utente singolo sul proprio server, e nulla di più:

- Cookie di sessione con `HttpOnly` e `SameSite=Lax`, rigenerato al login.
- Token CSRF obbligatorio su ogni POST.
- `hash_equals` per il confronto delle credenziali, più una breve attesa sui
  tentativi falliti.
- Tutto ciò che arriva dal client è validato lato server: giorno, orari, categoria,
  lunghezza del titolo, numero massimo di attività per giorno.
- Il testo inserito dall'utente viene sempre escapato prima di finire nel DOM.
- L'`.htaccess` blocca l'accesso diretto ai file di dati e di configurazione.

**Non c'è un limite ai tentativi di accesso** oltre a quella attesa, né un secondo
fattore. Se la metti su internet pubblica, usa HTTPS, imposta `PASSWORD_HASH` e
valuta di proteggerla anche con autenticazione del web server o una VPN.

---

## Com'è fatto

```
index.php            front controller: API JSON + serve il frontend
app.html             tutta l'interfaccia (HTML + Tailwind da CDN + JS vanilla)
config.php           credenziali e impostazioni (escluso da git)
config.example.php   modello da copiare
data.json            i tuoi dati (esclusi da git)
data.example.json    una settimana di esempio
backups/             copie giornaliere (escluse da git)
.htaccess            blocca l'accesso diretto a dati e configurazione
```

Tutte le rotte stanno su `index.php?action=…`. Le richieste POST richiedono
l'header `X-CSRF-Token` con il token ottenuto da `session` o `login`.

| Metodo | Action | Corpo |
|---|---|---|
| GET | `session` | – |
| POST | `login` | `{ username, password }` |
| POST | `logout` | `{}` |
| GET | `data` | – |
| POST | `task/save` | `{ day, task: { id?, title, time, end, color, icon?, note? } }` |
| POST | `task/delete` | `{ day, id }` |
| POST | `task/toggle` | `{ day, id }` |
| POST | `day/copy` | `{ from, to: [giorni] }` |
| GET | `ics` | `?token=…&categories=…` (senza sessione) |

Qualche scelta che conviene conoscere prima di metterci le mani:

- **La scala `gray` non contiene grigi.** Sono ruoli semantici agganciati a
  variabili CSS — `gray-950` è lo sfondo pagina, `gray-100` il testo principale — e
  il tema chiaro rovescia la rampa. È per questo che il tema esiste senza varianti
  `dark:` sparse nel markup. Gli accenti non grigi hanno i loro ruoli: `danger`,
  `warn`, `now`. I due blocchi da modificare sono `:root` e
  `:root[data-theme="light"]` in `app.html`.
- **I colori delle categorie sono calcolati in JavaScript**, schiariti sul tema
  scuro e scuriti su quello chiaro, per restare leggibili in entrambi.
- **La piegatura delle righe ICS** conta byte ma taglia fra caratteri, così le
  emoji sopravvivono intere al limite dei 75 ottetti.

---

## Tradurre l'interfaccia

L'interfaccia è in italiano, concentrata in pochi punti:

- `app.html`: gli oggetti `DAY_SHORT` e `DAY_LONG`, più le stringhe nel markup e
  nelle funzioni di rendering.
- `config.php`: le etichette delle categorie.
- `index.php`: i messaggi d'errore restituiti dall'API.

Le **chiavi** dei giorni (`lunedi`, `martedi`, …) sono usate in `data.json` e
nell'API: cambiarle richiede di migrare i dati esistenti, quindi conviene lasciarle
stare e tradurre solo ciò che si vede.

---

## Contribuire

Issue e pull request sono benvenute. Non c'è build né suite di test: basta clonare,
lanciare `php -S localhost:8000` e passare `php -l` sui file toccati.

L'importante è restare senza dipendenze: è il senso del progetto.

## Licenza

[MIT](LICENSE) — usalo, modificalo, vendilo, basta mantenere l'avviso di copyright.
