# Weekplan

A self-hosted weekly routine tracker. Not a calendar — seven fixed days you plan
once and tick off every week.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)

🇮🇹 **[Leggimi in italiano](README.it.md)**

> **Heads up:** the interface is in Italian (day names, categories, labels), and so
> are the day keys in the data file. Everything is plain text in two files, so
> translating it is a find-and-replace away — see [Translating](#translating).

---

## What it is

Most calendar apps answer *"what am I doing on 14 March?"*. Weekplan answers a
different question: *"what does a normal Tuesday look like, and did I actually do
it?"*

There are no dates and no infinite calendar. There are seven days — Monday to
Sunday — that repeat forever. You build the grid once, then every week you tick
things off and watch a score. On Monday the score resets and the grid stays.

It is a single PHP file plus a single HTML file. No database, no Composer, no
build step, no npm. Data lives in one JSON file you can read, edit and back up
with `cp`.

### What it is not

- Not a calendar: no dates, no recurrence rules, no exceptions, no "move this to
  next Thursday".
- Not multi-user: one set of credentials, one grid.
- Not a notification system: it does not push reminders (though your calendar app
  can, via the [ICS feed](#subscribe-from-your-calendar)).

If you need any of those, you need a calendar, not this.

---

## Features

- **Seven fixed days.** Tabs on mobile, a week grid on desktop.
- **Vertical timeline** in the Structured style: cards connected by a line, height
  proportional to duration, free time shown as empty space, a red line marking the
  current time.
- **Week view on desktop** — one column per day on a shared time axis, with a score
  per day (`12/21`) and for the whole week (`47/129`).
- **Weekly scoring.** Ticks last all week and reset on Monday at 00:00.
- **Overlap detection.** Activities that collide are grouped, highlighted and drawn
  side by side.
- **Categories** defined in a config file: label and colour, and the interface
  derives everything else.
- **Emoji and notes** on each activity.
- **Copy a day** onto one or more other days.
- **Light and dark theme**, following the system until you choose.
- **ICS feed** so iCal, Google Calendar or Outlook can show your routine next to
  your real appointments.
- **Rotating backups** of the data file, one per day.

---

## Requirements

- **PHP 8.1 or newer** (it uses first-class callable syntax).
- A web server that runs PHP: Apache, nginx + PHP-FPM, Caddy, or PHP's built-in
  server for local use.
- The `intl` extension is recommended, not required: without it, composed emoji
  (👨‍💻, 🏳️‍🌈) are trimmed slightly less precisely.
- Write access to the project directory, for `data.json` and `backups/`.

The frontend loads Tailwind CSS and the Inter font from a CDN, so the browser needs
internet access on first load. Everything else is local.

---

## Quick start

```bash
git clone https://github.com/fabiozappa/weekplan.git
cd weekplan
cp config.example.php config.php
cp data.example.json data.json   # optional: a sample week to start from
php -S localhost:8000
```

Open <http://localhost:8000> and log in with the credentials in `config.php`
(`admin` / `cambiami` until you change them — do change them).

> PHP's built-in server ignores `.htaccess`, so `data.json` would be downloadable.
> Use it for local testing only.

---

## Installing on a server

### Apache

Copy the files into a directory served by PHP, then make sure the directory is
writable by the web server user:

```bash
chmod 775 .
```

The bundled `.htaccess` blocks direct access to `.json`, `.lock`, `.tmp` and `.md`
files, to `config.php` and to `app.html`, and disables directory listings. It needs
`AllowOverride All` on that directory, otherwise Apache ignores it — check with:

```bash
curl -o /dev/null -w '%{http_code}\n' https://your-host/config.php   # must be 403
```

### nginx

`.htaccess` does nothing here; replicate the rules in your server block:

```nginx
location ~* \.(json|lock|tmp|md)$ { deny all; }
location = /config.php            { deny all; }
location = /app.html              { deny all; }
location /backups/                { deny all; }
```

### HTTPS

Not required to run, but required in practice if you want to subscribe to the ICS
feed from a phone or from Google Calendar — those fetch the feed from their own
servers, so the app has to be reachable from the internet.

---

## Configuration

Everything lives in `config.php`, which returns a plain PHP array. It is excluded
from git and, being a `.php` file, is never served as text.

| Key | What it does |
|---|---|
| `USERNAME`, `PASSWORD` | Login credentials, in clear text. |
| `PASSWORD_HASH` | Optional and recommended: if set, it wins over `PASSWORD`. |
| `SESSION_NAME`, `SESSION_LIFETIME` | Session cookie name and duration (default 30 days). |
| `TIMEZONE` | Used for the weekly reset and the "now" marker. |
| `BACKUP_KEEP` | How many daily copies of `data.json` to keep (default 7). |
| `CATEGORIES` | The categories: key, label and colour. |
| `ICS_TOKEN` | Secret in the feed URL. Empty disables the feed. |
| `ICS_NAME` | Calendar name shown in clients. |
| `ICS_BUSY` | `true` marks events as busy, `false` only shows them. |
| `ICS_ALARM_MINUTES` | Reminder lead time, `null` for none. |

Generate a password hash and a feed token:

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
```

### Categories

Add one with a single line:

```php
'family' => ['label' => 'Family', 'color' => '#ec4899'],
```

The colour is the only thing you provide; dot, border, chip and chart bar are
derived from it, lightened on dark backgrounds and darkened on light ones. The
server rejects categories that are not listed here. The key is what gets stored in
`data.json`, so renaming a key in use drops those activities back to `altro`.

---

## Using it

### Day view (phone, and desktop on request)

A vertical timeline. Each card shows time range, duration, emoji, title, note and
category. Tap the circle to tick an activity, tap the card to edit it, swipe left
and right to change day. The `+` button adds an activity.

Card height follows duration, within limits: below roughly 50 minutes everything
is the same height, because under that a card has no room for its own title. Gaps
between activities appear as empty space labelled with their length.

### Week view (desktop, from 1024px wide)

Seven columns, time running top to bottom, with the score for each day in its
header and the week total above. The day headers stay pinned while you scroll.

- **Click** an activity to tick it, or untick it.
- **Right-click** to edit it.
- **Click a day name** to open that day in the day view.
- **`+` next to a day** to add an activity there.

The vertical axis is shared by all columns and **piecewise linear**: every start
and end time is a fixed point, so 08:30 sits at the same height in every column,
and each stretch is as tall as its duration but never shorter than a readable
minimum. Short activities therefore look slightly longer than they are, relative
to long ones; order and alignment stay exact. Overlapping activities sit side by
side, computed per cluster, so one collision does not shrink the whole day.

### Copying a day

The button next to the day name copies that day's activities onto one or more
other days. Copies get fresh ids and start unticked. **Activities already in the
target days are replaced, not merged.**

---

## Scoring and the weekly reset

Ticks last from Monday to Sunday and are cleared when a new week starts — Sunday
is the last day they count, Monday at 00:00 starts from zero. The activity grid is
never touched.

`data.json` stores `week_start`, the Monday of the week the saved ticks belong to
(ISO week, in the configured `TIMEZONE`). The check runs inside every operation,
not just when loading: if the first thing you do in a new week is tick something
from a page left open since Sunday, that tick belongs to the new week instead of
being wiped a moment later.

There is **no history**: once the week turns over, last week's score is gone. The
daily backup is the only trace, and it rotates after a week.

---

## Subscribe from your calendar

The routine can be published as a read-only calendar that iCal, Google Calendar or
Outlook refresh on their own. The point is to see your fixed commitments next to
the real ones, and to book calls around them.

Set `ICS_TOKEN` in `config.php`, then use the calendar button in the header: it
shows the address, a `webcal://` link that opens the Calendar app directly, and
category checkboxes.

```
https://your-host/index.php?action=ics&token=…
https://your-host/index.php?action=ics&token=…&categories=lavoro,progetti
```

You can create several subscriptions with different category groups — one for
work, one for personal — each a separate calendar with its own colour and its own
show/hide switch. Keep the groups disjoint, or activities in both appear twice.

**How it is modelled.** Each activity is a weekly recurring event: `DTSTART` on a
fixed Monday (1 January 2024) plus the day offset, then `RRULE:FREQ=WEEKLY`. The
anchor must never change, or clients recreate every event. The `UID` is the task
id, so editing an activity updates its event instead of duplicating it. Times are
**floating** — no `Z`, no `TZID` — so 06:30 stays 06:30 in whatever timezone the
calendar displays, daylight saving included.

**The URL is the credential.** Subscription happens without a login, because no
calendar client can log in. Anyone holding the address can read the routine (not
change it). Changing `ICS_TOKEN` revokes every subscription at once.

---

## Data and backups

`data.json` is the whole database:

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

Day keys are `lunedi martedi mercoledi giovedi venerdi sabato domenica`. Times are
`HH:MM` with `end` after `time`; an activity is never allowed to cross midnight.

Writes are atomic — a temporary file plus `rename`, under an exclusive lock on
`data.lock` — so a crash cannot leave the file half written, and two browser tabs
cannot overwrite each other. If `data.json` is unreadable the app stops with a
clear error instead of starting from scratch and overwriting your week.

Before the first write of each day, the file is copied to
`backups/data.YYYY-MM-DD.json`, which therefore holds the state the previous day
ended in. Copies beyond `BACKUP_KEEP` are deleted. To restore:

```bash
cp backups/data.2026-01-12.json data.json
```

---

## Security

Reasonable for a single-user app on your own server, and no more than that:

- Session cookie with `HttpOnly` and `SameSite=Lax`, regenerated on login.
- CSRF token required on every POST.
- `hash_equals` for credential comparison, plus a short delay on failed attempts.
- Everything written by the client is validated server-side: day, times, category,
  title length, maximum activities per day.
- User text is escaped before reaching the DOM.
- `.htaccess` blocks direct access to the data and config files.

There is **no rate limiting** beyond that delay, and no second factor. If you put
this on the public internet, use HTTPS, set `PASSWORD_HASH`, and consider putting
it behind basic auth or a VPN as well.

---

## How it works

```
index.php            front controller: JSON API + serves the frontend
app.html             the entire interface (HTML + Tailwind via CDN + vanilla JS)
config.php           credentials and settings (gitignored)
config.example.php   template to copy
data.json            your data (gitignored)
data.example.json    a sample week
backups/             daily copies (gitignored)
.htaccess            blocks direct access to data and config
```

All routes live on `index.php?action=…`. POST requests need an `X-CSRF-Token`
header with the token from `session` or `login`.

| Method | Action | Body |
|---|---|---|
| GET | `session` | – |
| POST | `login` | `{ username, password }` |
| POST | `logout` | `{}` |
| GET | `data` | – |
| POST | `task/save` | `{ day, task: { id?, title, time, end, color, icon?, note? } }` |
| POST | `task/delete` | `{ day, id }` |
| POST | `task/toggle` | `{ day, id }` |
| POST | `day/copy` | `{ from, to: [days] }` |
| GET | `ics` | `?token=…&categories=…` (no session) |

A few choices worth knowing if you plan to change things:

- **The `gray` palette is not grey.** It is a set of semantic roles backed by CSS
  variables — `gray-950` is the page background, `gray-100` the main text — and
  the light theme flips the ramp. That is why the theme exists without `dark:`
  variants scattered through the markup. Non-grey accents have their own roles:
  `danger`, `warn`, `now`. The two blocks to edit are `:root` and
  `:root[data-theme="light"]` in `app.html`.
- **Category colours are computed in JavaScript**, lightened on dark and darkened
  on light, so they stay readable in both themes.
- **The ICS line folding** counts bytes but splits between characters, so emoji
  survive the 75-octet limit intact.

---

## Translating

The interface is Italian, held in a few obvious places:

- `app.html`: the `DAY_SHORT` and `DAY_LONG` objects, plus the literal strings in
  the markup and in the render functions.
- `config.php`: the category labels.
- `index.php`: the error messages returned by the API.

The day **keys** (`lunedi`, `martedi`, …) are used in `data.json` and in the API;
changing them means migrating existing data, so it is easier to leave them alone
and translate only what is shown.

---

## Contributing

Issues and pull requests are welcome. There is no build step and no test suite:
clone it, run `php -S localhost:8000`, and check `php -l` on the files you touch.

Keep it dependency-free — that is the point of the project.

## License

[MIT](LICENSE) — use it, change it, sell it, just keep the copyright notice.
