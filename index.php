<?php
/**
 * Weekly Agenda - backend a singolo file.
 *
 * Rotte (tutte su index.php?action=...):
 *   GET  session      stato sessione + token CSRF
 *   POST login        { username, password }
 *   POST logout
 *   GET  data         template settimanale + stato "fatto" (azzerato a inizio settimana)
 *   POST task/save    { day, task: { id?, title, time, end, color, icon?, note? } }
 *   POST task/delete  { day, id }
 *   POST task/toggle  { day, id }
 *   POST day/copy     { from, to: [giorni] }
 *   GET  ics          feed iCalendar sottoscrivibile (token nell'URL, niente sessione)
 *   (default)         serve il frontend app.html
 */

declare(strict_types=1);

const DAYS = ['lunedi', 'martedi', 'mercoledi', 'giovedi', 'venerdi', 'sabato', 'domenica'];
const MAX_TITLE_LEN = 120;
const MAX_NOTE_LEN  = 500;
const MAX_ICON_GRAPHEMES = 2;   // "caratteri" percepiti: 👨‍💻 ne è uno solo
const MAX_TASKS_PER_DAY = 100;
const DEFAULT_DURATION = 30;   // minuti, per i task migrati senza durata leggibile
const DAY_END = 23 * 60 + 59;  // ultimo minuto rappresentabile in un giorno

const CONFIG_PATH = __DIR__ . '/config.php';
const DATA_PATH   = __DIR__ . '/data.json';
const LOCK_PATH   = __DIR__ . '/data.lock';
const BACKUP_DIR  = __DIR__ . '/backups';

// Lunedì 1 gennaio 2024: ancora fissa da cui parte ogni serie settimanale del feed
// ICS. Deve restare immutata, altrimenti i client ricreano tutti gli eventi.
const ICS_ANCHOR = '20240101';
const ICS_WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

// ---------------------------------------------------------------- infrastruttura

function config(): array
{
    static $config = null;
    if ($config === null) {
        if (!is_file(CONFIG_PATH)) {
            fail('config.php mancante: copia config.example.php in config.php', 500);
        }
        $config = require CONFIG_PATH;
        if (!is_array($config)) {
            fail('config.php non restituisce un array', 500);
        }
    }
    return $config;
}

/**
 * Categorie normalizzate dalla configurazione: chiave => { label, color }.
 * Una voce malformata viene scartata invece di far cadere tutta l'app.
 */
function categories(): array
{
    static $categories = null;
    if ($categories !== null) {
        return $categories;
    }

    $categories = [];
    foreach ((array) (config()['CATEGORIES'] ?? []) as $key => $entry) {
        if (!is_string($key) || !preg_match('/^[a-z0-9_-]{1,32}$/', $key)) {
            continue;
        }
        $color = is_array($entry) ? (string) ($entry['color'] ?? '') : '';
        $categories[$key] = [
            'label' => is_array($entry) ? (string) ($entry['label'] ?? $key) : (string) $entry,
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : '#64748b',
        ];
    }

    if ($categories === []) {
        $categories = ['altro' => ['label' => 'Altro', 'color' => '#64748b']];
    }
    return $categories;
}

/** Categoria di ripiego per i valori sconosciuti. */
function defaultCategory(): string
{
    $categories = categories();
    return isset($categories['altro']) ? 'altro' : (string) array_key_first($categories);
}

function jsonOut(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): never
{
    jsonOut(['error' => $message], $status);
}

function bootSession(): void
{
    $cfg = config();
    session_name($cfg['SESSION_NAME'] ?? 'weekplan');
    session_set_cookie_params([
        'lifetime' => (int) ($cfg['SESSION_LIFETIME'] ?? 2592000),
        'path'     => dirname($_SERVER['SCRIPT_NAME'] ?? '/') ?: '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function requireAuth(): void
{
    if (empty($_SESSION['authenticated'])) {
        fail('Sessione scaduta, effettua di nuovo il login', 401);
    }
}

function requireCsrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals((string) $_SESSION['csrf'], $sent)) {
        fail('Sessione non valida, ricarica la pagina', 401);
    }
}

function requirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail('Metodo non consentito', 405);
    }
}

function readInput(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Lunedì della settimana ISO che contiene la data: la settimana va da lunedì a
 * domenica, quindi la domenica appartiene ancora a quella iniziata sei giorni prima.
 */
function weekStart(?string $date = null): string
{
    $tz = new DateTimeZone(config()['TIMEZONE'] ?? 'Europe/Rome');
    $day = new DateTimeImmutable($date ?? 'now', $tz);
    return $day->setISODate((int) $day->format('o'), (int) $day->format('W'), 1)->format('Y-m-d');
}

function today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(config()['TIMEZONE'] ?? 'Europe/Rome')))
        ->format('Y-m-d');
}

function isClock(mixed $value): bool
{
    return is_string($value) && preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $value) === 1;
}

function toMinutes(string $clock): int
{
    [$h, $m] = array_map('intval', explode(':', $clock));
    return $h * 60 + $m;
}

function toClock(int $minutes): string
{
    $minutes = max(0, min(DAY_END, $minutes));
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

/** Legge il vecchio campo "duration" ("90m", "1h30m", "2 ore"...) in minuti. */
function durationToMinutes(string $duration): ?int
{
    $raw = strtolower(trim($duration));
    if ($raw === '' || !preg_match('/^(?:(\\d{1,2})\\s*(?:ore|ora|hr|h))?\\s*(?:(\\d{1,3})\\s*(?:minuti|minuto|min|m))?$/u', $raw, $m)) {
        return null;
    }
    $minutes = ((int) ($m[1] ?? 0)) * 60 + (int) ($m[2] ?? 0);
    return ($minutes > 0 && $minutes <= 24 * 60) ? $minutes : null;
}

function compareTasks(array $a, array $b): int
{
    return [$a['time'], $a['end']] <=> [$b['time'], $b['end']];
}

// ---------------------------------------------------------------- persistenza

/**
 * Esegue una lettura/scrittura di data.json sotto lock esclusivo.
 * Il reset settimanale si applica qui, prima di qualunque operazione: se il primo
 * gesto della settimana nuova è una spunta, deve finire nella settimana nuova e non
 * essere cancellata dal reset alla lettura successiva.
 */
function withData(callable $mutator): array
{
    $lock = fopen(LOCK_PATH, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        fail('Impossibile ottenere il lock sui dati', 500);
    }
    try {
        $stored = loadData();
        $current = applyWeeklyReset($stored);

        [$next, $result] = $mutator($current);
        if ($next !== null) {
            saveData($next);
        } elseif ($current !== $stored) {
            saveData($current);
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function loadData(): array
{
    if (!is_file(DATA_PATH)) {
        return normalizeData([]);
    }
    $contents = trim((string) file_get_contents(DATA_PATH));
    if ($contents === '') {
        return normalizeData([]);
    }
    $raw = json_decode($contents, true);
    if (!is_array($raw)) {
        // Meglio fermarsi che ripartire da zero e sovrascrivere la settimana.
        fail('data.json illeggibile o corrotto: ripristina un backup prima di continuare', 500);
    }
    return normalizeData($raw);
}

/** Garantisce che la struttura contenga tutte le chiavi attese e solo task validi. */
function normalizeData(array $raw): array
{
    $data = [
        'week_start'        => normalizeWeekStart($raw),
        'completion_status' => [],
        'days'              => [],
    ];

    foreach (DAYS as $day) {
        $tasks = [];
        foreach ((array) ($raw['days'][$day] ?? []) as $task) {
            if (!is_array($task) || !isset($task['id'])) {
                continue;
            }
            $tasks[] = normalizeTask($task);
        }
        usort($tasks, compareTasks(...));
        $data['days'][$day] = $tasks;

        $ids  = array_column($tasks, 'id');
        $done = array_values(array_intersect(
            array_map('strval', (array) ($raw['completion_status'][$day] ?? [])),
            $ids
        ));
        $data['completion_status'][$day] = $done;
    }

    return $data;
}

/**
 * Porta un task nella forma canonica { id, title, time, end, color, icon, note }.
 * I file più vecchi avevano "duration" al posto di "end" e non avevano icona e nota.
 */
function normalizeTask(array $task): array
{
    $time = isClock($task['time'] ?? null) ? (string) $task['time'] : '00:00';
    $end  = isClock($task['end'] ?? null) ? (string) $task['end'] : null;

    if ($end === null) {
        $minutes = durationToMinutes((string) ($task['duration'] ?? '')) ?? DEFAULT_DURATION;
        $end = toClock(toMinutes($time) + $minutes);
    }
    if (toMinutes($end) <= toMinutes($time)) {
        $end = toClock(DAY_END);
    }

    return [
        'id'    => (string) $task['id'],
        'title' => mb_substr(trim((string) ($task['title'] ?? '')), 0, MAX_TITLE_LEN),
        'time'  => $time,
        'end'   => $end,
        'color' => isset(categories()[$task['color'] ?? '']) ? (string) $task['color'] : defaultCategory(),
        'icon'  => cleanIcon($task['icon'] ?? null),
        'note'  => cleanNote($task['note'] ?? null),
    ];
}

/**
 * Conserva una copia di data.json com'era prima della prima scrittura di oggi,
 * cioè lo stato con cui si è chiusa la giornata precedente. Le copie più vecchie
 * oltre BACKUP_KEEP vengono eliminate.
 */
function rotateBackups(): void
{
    $keep = max(0, (int) (config()['BACKUP_KEEP'] ?? 7));
    if ($keep === 0 || !is_file(DATA_PATH)) {
        return;
    }
    if (!is_dir(BACKUP_DIR) && !@mkdir(BACKUP_DIR, 0775, true) && !is_dir(BACKUP_DIR)) {
        return; // niente backup non è un buon motivo per bloccare il salvataggio
    }

    // La cartella si porta dietro la propria protezione: così resta chiusa anche
    // se l'app viene spostata sotto una configurazione Apache diversa.
    $guard = BACKUP_DIR . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "Require all denied\n");
    }

    $target = BACKUP_DIR . '/data.' . today() . '.json';
    if (!is_file($target) && @copy(DATA_PATH, $target)) {
        @chmod($target, 0664);
    }

    $backups = glob(BACKUP_DIR . '/data.*.json') ?: [];
    sort($backups); // i nomi contengono la data ISO, quindi l'ordine è cronologico
    foreach (array_slice($backups, 0, max(0, count($backups) - $keep)) as $old) {
        @unlink($old);
    }
}

function saveData(array $data): void
{
    rotateBackups();

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp  = DATA_PATH . '.tmp';
    if ($json === false || file_put_contents($tmp, $json) === false) {
        @unlink($tmp);
        fail('Impossibile scrivere data.json: controlla i permessi della cartella', 500);
    }
    // Il rename sostituisce l'inode: senza chmod il file resta leggibile solo
    // dall'utente del web server e non è più modificabile a mano.
    @chmod($tmp, 0664);
    if (!rename($tmp, DATA_PATH)) {
        @unlink($tmp);
        fail('Impossibile aggiornare data.json', 500);
    }
}

/**
 * Lunedì della settimana a cui appartengono i "fatto" salvati.
 * I file precedenti avevano "last_reset_date" (reset giornaliero): la loro
 * settimana è quella che contiene quella data, così i "fatto" registrati da
 * lunedì in poi sopravvivono al passaggio al reset settimanale.
 */
function normalizeWeekStart(array $raw): string
{
    foreach (['week_start', 'last_reset_date'] as $key) {
        $value = $raw[$key] ?? null;
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return weekStart($value);
        }
    }
    return weekStart();
}

/** Azzera tutti i "fatto" quando inizia una nuova settimana, senza toccare la griglia. */
function applyWeeklyReset(array $data): array
{
    $current = weekStart();
    if ($data['week_start'] === $current) {
        return $data;
    }
    $data['week_start'] = $current;
    foreach (DAYS as $day) {
        $data['completion_status'][$day] = [];
    }
    return $data;
}

// ---------------------------------------------------------------- validazione

function validDay(mixed $day): string
{
    if (!is_string($day) || !in_array($day, DAYS, true)) {
        fail('Giorno non valido');
    }
    return $day;
}

function validTitle(mixed $title): string
{
    $title = is_string($title) ? trim($title) : '';
    if ($title === '') {
        fail('Il titolo è obbligatorio');
    }
    return mb_substr($title, 0, MAX_TITLE_LEN);
}

function validTime(mixed $time): string
{
    if (!isClock($time)) {
        fail('Ora di inizio non valida (formato HH:MM)');
    }
    return (string) $time;
}

function validEnd(mixed $end, string $start): string
{
    if (!isClock($end)) {
        fail('Ora di fine non valida (formato HH:MM)');
    }
    if (toMinutes((string) $end) <= toMinutes($start)) {
        fail("L'ora di fine deve essere successiva a quella di inizio");
    }
    return (string) $end;
}

/**
 * Un'emoji, o al massimo due segni. Si tengono lettere, numeri, simboli, segni
 * combinanti e lo zero-width joiner: senza quest'ultimo le emoji composte come
 * 👨‍💻 o 🏳️‍🌈 si spezzerebbero nei loro pezzi. Il limite è in grafemi, non in
 * code point, perché una singola emoji può valerne sette.
 */
function cleanIcon(mixed $icon): string
{
    if (!is_string($icon)) {
        return '';
    }
    // So = emoji, Sk = modificatori (tonalità di pelle), Sc = valute. Fuori i
    // simboli matematici, altrimenti "<" o "=" passerebbero come icona.
    $icon = preg_replace('/[^\p{L}\p{N}\p{So}\p{Sk}\p{Sc}\p{M}\x{200D}]/u', '', $icon) ?? '';
    if ($icon === '') {
        return '';
    }
    return function_exists('grapheme_substr')
        ? (string) grapheme_substr($icon, 0, MAX_ICON_GRAPHEMES)
        : mb_substr($icon, 0, 8);
}

/** Nota libera: si tengono gli a capo, si buttano gli altri caratteri di controllo. */
function cleanNote(mixed $note): string
{
    if (!is_string($note)) {
        return '';
    }
    $note = preg_replace('/[^\P{C}\n]/u', '', $note) ?? '';
    return mb_substr(trim($note), 0, MAX_NOTE_LEN);
}

function validColor(mixed $color): string
{
    return is_string($color) && isset(categories()[$color]) ? $color : defaultCategory();
}

// ---------------------------------------------------------------- feed ICS

/** Escaping dei valori TEXT: RFC 5545 §3.3.11. */
function icsEscape(string $value): string
{
    return str_replace(
        ["\\", ';', ',', "\r\n", "\n", "\r"],
        ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
        $value
    );
}

/**
 * Piega la riga a 75 ottetti come vuole l'RFC, contando byte ma tagliando fra
 * caratteri: spezzare un'emoji a metà produrrebbe UTF-8 non valido.
 */
function icsFold(string $line): string
{
    $folded = '';
    $current = '';
    $bytes = 0;

    foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
        $size = strlen($char);
        if ($bytes + $size > 73) {
            $folded .= $current . "\r\n ";
            $current = '';
            $bytes = 1; // lo spazio di continuazione occupa il primo ottetto
        }
        $current .= $char;
        $bytes += $size;
    }

    return $folded . $current;
}

function icsLine(string $property, string $value): string
{
    return icsFold($property . ':' . $value) . "\r\n";
}

/** Il feed pubblica la griglia, non lo stato "fatto": è un modello, non un diario. */
function buildIcs(array $data, array $onlyCategories = []): string
{
    $cfg = config();
    $categories = categories();
    $stamp = gmdate('Ymd\THis\Z');
    $busy = !empty($cfg['ICS_BUSY']);
    $alarm = $cfg['ICS_ALARM_MINUTES'] ?? null;

    $ics  = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//weekplan//Agenda settimanale//IT\r\n";
    $ics .= "CALSCALE:GREGORIAN\r\n";
    $ics .= "METHOD:PUBLISH\r\n";
    $ics .= icsLine('NAME', icsEscape((string) ($cfg['ICS_NAME'] ?? 'Routine settimanale')));
    $ics .= icsLine('X-WR-CALNAME', icsEscape((string) ($cfg['ICS_NAME'] ?? 'Routine settimanale')));
    $ics .= "REFRESH-INTERVAL;VALUE=DURATION:PT1H\r\n";
    $ics .= "X-PUBLISHED-TTL:PT1H\r\n";

    foreach (DAYS as $index => $day) {
        $date = (new DateTimeImmutable(ICS_ANCHOR))->modify('+' . $index . ' day')->format('Ymd');

        foreach ($data['days'][$day] as $task) {
            if ($onlyCategories !== [] && !in_array($task['color'], $onlyCategories, true)) {
                continue;
            }

            $label = $categories[$task['color']]['label'] ?? $task['color'];
            $summary = trim(($task['icon'] !== '' ? $task['icon'] . ' ' : '') . $task['title']);

            // Orari "floating": niente Z e niente TZID, così le 06:30 restano le
            // 06:30 nel fuso del calendario che li mostra, cambi d'ora compresi.
            $ics .= "BEGIN:VEVENT\r\n";
            $ics .= icsLine('UID', $task['id'] . '@weekplan.local');
            $ics .= icsLine('DTSTAMP', $stamp);
            $ics .= icsLine('DTSTART', $date . 'T' . str_replace(':', '', $task['time']) . '00');
            $ics .= icsLine('DTEND', $date . 'T' . str_replace(':', '', $task['end']) . '00');
            $ics .= icsLine('RRULE', 'FREQ=WEEKLY;BYDAY=' . ICS_WEEKDAYS[$index]);
            $ics .= icsLine('SUMMARY', icsEscape($summary));
            if ($task['note'] !== '') {
                $ics .= icsLine('DESCRIPTION', icsEscape($task['note']));
            }
            $ics .= icsLine('CATEGORIES', icsEscape($label));
            $ics .= icsLine('TRANSP', $busy ? 'OPAQUE' : 'TRANSPARENT');
            if (is_int($alarm) && $alarm >= 0) {
                $ics .= "BEGIN:VALARM\r\nACTION:DISPLAY\r\n";
                $ics .= icsLine('TRIGGER', '-PT' . $alarm . 'M');
                $ics .= icsLine('DESCRIPTION', icsEscape($summary));
                $ics .= "END:VALARM\r\n";
            }
            $ics .= "END:VEVENT\r\n";
        }
    }

    return $ics . "END:VCALENDAR\r\n";
}

/** URL assoluto del feed, null se il token non è configurato. */
function icsUrl(): ?string
{
    $token = (string) (config()['ICS_TOKEN'] ?? '');
    if ($token === '') {
        return null;
    }
    // L'host arriva dalla richiesta: si tiene solo ciò che può stare in un hostname.
    $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';

    return $scheme . '://' . $host . ($_SERVER['SCRIPT_NAME'] ?? '/index.php')
        . '?action=ics&token=' . rawurlencode($token);
}

/** Rotta pubblica: nessuna sessione, autorizzata dal solo token nell'URL. */
function serveIcs(): never
{
    $expected = (string) (config()['ICS_TOKEN'] ?? '');
    $given = (string) ($_GET['token'] ?? '');

    if ($expected === '' || strlen($given) !== strlen($expected) || !hash_equals($expected, $given)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Feed non disponibile\n";
        exit;
    }

    // Il filtro è opzionale, ma se c'è dev'essere sensato: restituire tutto in
    // risposta a un refuso significherebbe non accorgersene mai.
    $wanted = [];
    if (isset($_GET['categories']) && trim((string) $_GET['categories']) !== '') {
        $asked = array_filter(array_map('trim', explode(',', (string) $_GET['categories'])));
        $wanted = array_values(array_filter($asked, static fn(string $c): bool => isset(categories()[$c])));
        if ($wanted === []) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Categorie sconosciute: " . implode(', ', $asked) . "\n";
            echo "Disponibili: " . implode(', ', array_keys(categories())) . "\n";
            exit;
        }
    }

    $body = buildIcs(loadData(), $wanted);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="weekplan.ics"');
    header('Cache-Control: max-age=900, public');
    echo $body;
    exit;
}

// ---------------------------------------------------------------- rotte

$action = $_GET['action'] ?? '';

// Fuori dalla sessione: i calendari interrogano il feed di continuo e non hanno
// cookie, aprire una sessione a ogni fetch lascerebbe solo file di scarto.
if ($action === 'ics') {
    serveIcs();
}

bootSession();

switch ($action) {

    case 'session':
        jsonOut([
            'authenticated' => !empty($_SESSION['authenticated']),
            'csrf'          => $_SESSION['csrf'],
        ]);

    case 'login':
        requirePost();
        requireCsrf();
        $input = readInput();
        $cfg   = config();

        $userOk = hash_equals((string) ($cfg['USERNAME'] ?? ''), (string) ($input['username'] ?? ''));
        $pass   = (string) ($input['password'] ?? '');
        $passOk = !empty($cfg['PASSWORD_HASH'])
            ? password_verify($pass, (string) $cfg['PASSWORD_HASH'])
            : hash_equals((string) ($cfg['PASSWORD'] ?? ''), $pass);

        if (!$userOk || !$passOk) {
            usleep(300_000); // piccolo freno ai tentativi a raffica
            jsonOut(['error' => 'Credenziali non valide'], 403);
        }

        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        jsonOut(['success' => true, 'csrf' => $_SESSION['csrf']]);

    case 'logout':
        requirePost();
        requireCsrf();
        $_SESSION = [];
        session_destroy();
        jsonOut(['success' => true]);

    case 'data':
        requireAuth();
        $data = withData(static fn(array $data): array => [null, $data]);
        jsonOut($data + ['categories' => categories(), 'ics_url' => icsUrl()]);

    case 'task/save':
        requireAuth();
        requirePost();
        requireCsrf();
        $input = readInput();
        $day   = validDay($input['day'] ?? null);
        $task  = is_array($input['task'] ?? null) ? $input['task'] : [];

        $start = validTime($task['time'] ?? null);
        $payload = [
            'id'    => isset($task['id']) && is_string($task['id']) && $task['id'] !== '' ? $task['id'] : null,
            'title' => validTitle($task['title'] ?? null),
            'time'  => $start,
            'end'   => validEnd($task['end'] ?? null, $start),
            'color' => validColor($task['color'] ?? null),
            'icon'  => cleanIcon($task['icon'] ?? null),
            'note'  => cleanNote($task['note'] ?? null),
        ];

        jsonOut(withData(static function (array $data) use ($day, $payload): array {
            $tasks = $data['days'][$day];
            $index = $payload['id'] === null
                ? false
                : array_search($payload['id'], array_column($tasks, 'id'), true);

            if ($index === false) {
                if (count($tasks) >= MAX_TASKS_PER_DAY) {
                    fail('Hai raggiunto il numero massimo di attività per questo giorno');
                }
                $payload['id'] = 'task-' . bin2hex(random_bytes(8));
                $tasks[] = $payload;
            } else {
                $payload['id'] = $tasks[$index]['id'];
                $tasks[$index] = $payload;
            }

            usort($tasks, compareTasks(...));
            $data['days'][$day] = $tasks;
            return [$data, ['success' => true, 'task' => $payload, 'day' => $day]];
        }));

    case 'task/delete':
        requireAuth();
        requirePost();
        requireCsrf();
        $input = readInput();
        $day   = validDay($input['day'] ?? null);
        $id    = (string) ($input['id'] ?? '');

        jsonOut(withData(static function (array $data) use ($day, $id): array {
            $data['days'][$day] = array_values(array_filter(
                $data['days'][$day],
                static fn(array $t): bool => $t['id'] !== $id
            ));
            $data['completion_status'][$day] = array_values(array_filter(
                $data['completion_status'][$day],
                static fn(string $tid): bool => $tid !== $id
            ));
            return [$data, ['success' => true]];
        }));

    case 'task/toggle':
        requireAuth();
        requirePost();
        requireCsrf();
        $input = readInput();
        $day   = validDay($input['day'] ?? null);
        $id    = (string) ($input['id'] ?? '');

        jsonOut(withData(static function (array $data) use ($day, $id): array {
            if (!in_array($id, array_column($data['days'][$day], 'id'), true)) {
                fail('Attività inesistente', 404);
            }
            $done = $data['completion_status'][$day];
            $isDone = in_array($id, $done, true);
            $data['completion_status'][$day] = $isDone
                ? array_values(array_filter($done, static fn(string $t): bool => $t !== $id))
                : [...$done, $id];
            return [$data, ['success' => true, 'done' => !$isDone]];
        }));

    case 'day/copy':
        requireAuth();
        requirePost();
        requireCsrf();
        $input = readInput();
        $from  = validDay($input['from'] ?? null);
        $targets = array_values(array_unique(array_map(
            static fn(mixed $day): string => validDay($day),
            is_array($input['to'] ?? null) ? $input['to'] : []
        )));

        if ($targets === []) {
            fail('Scegli almeno un giorno di destinazione');
        }
        if (in_array($from, $targets, true)) {
            fail('Il giorno di partenza non può essere anche destinazione');
        }

        jsonOut(withData(static function (array $data) use ($from, $targets): array {
            $source = $data['days'][$from];
            if (count($source) > MAX_TASKS_PER_DAY) {
                fail('Il giorno di partenza contiene troppe attività');
            }

            foreach ($targets as $day) {
                // Ogni copia è un'attività nuova: id diverso e "fatto" azzerato,
                // altrimenti spuntarla di qua la spunterebbe anche di là.
                $copies = [];
                foreach ($source as $task) {
                    $task['id'] = 'task-' . bin2hex(random_bytes(8));
                    $copies[] = $task;
                }
                $data['days'][$day] = $copies;
                $data['completion_status'][$day] = [];
            }

            return [$data, ['success' => true, 'copied' => count($source), 'days' => $targets]];
        }));

    default:
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/app.html');
        exit;
}
