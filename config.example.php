<?php
/**
 * Copia questo file in config.php e cambia le credenziali.
 */

return [
    'USERNAME' => 'admin',
    'PASSWORD' => 'cambiami',

    // Opzionale, consigliato: se valorizzato ha la precedenza su PASSWORD.
    //   php -r "echo password_hash('la-tua-password', PASSWORD_DEFAULT), PHP_EOL;"
    'PASSWORD_HASH' => '',

    'SESSION_NAME'     => 'weekplan',
    'SESSION_LIFETIME' => 60 * 60 * 24 * 30,

    'TIMEZONE' => 'Europe/Rome',

    /*
     * Feed ICS sottoscrivibile da iCal / Google Calendar / Outlook.
     * L'URL è l'unica credenziale: chi ce l'ha vede la routine, senza login.
     * Per revocarlo basta cambiare il token (poi si ri-sottoscrive il calendario):
     *   php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
     * Stringa vuota = feed disattivato.
     */
    'ICS_TOKEN' => '',
    'ICS_NAME'  => 'Routine settimanale',

    // true = gli eventi risultano "occupato" agli altri; false = solo visibili.
    'ICS_BUSY' => false,

    // Minuti di preavviso per il promemoria, null per nessuno. Apple lo rispetta
    // sui calendari sottoscritti, Google no.
    'ICS_ALARM_MINUTES' => null,

    // Quante copie giornaliere di data.json conservare in backups/.
    'BACKUP_KEEP' => 7,

    /*
     * Categorie delle attività: la chiave finisce in data.json, "label" è quella
     * mostrata e "color" è l'unico colore da cui l'interfaccia deriva tutto il
     * resto (pallino, bordo, etichetta). Aggiungerne una qui basta: server e
     * frontend la prendono da qui, non c'è nient'altro da toccare.
     */
    'CATEGORIES' => [
        'fitness' => ['label' => 'Fitness', 'color' => '#10b981'],
        'lavoro'  => ['label' => 'Lavoro',  'color' => '#3b82f6'],
        'studio'  => ['label' => 'Studio',  'color' => '#f59e0b'],
        'cane'    => ['label' => 'Cane',    'color' => '#f97316'],
        'casa'    => ['label' => 'Casa',    'color' => '#a855f7'],
        'progetti'=> ['label' => 'Progetti', 'color' => '#ec4899'],
        'altro'   => ['label' => 'Altro',   'color' => '#64748b'],
    ],
];
