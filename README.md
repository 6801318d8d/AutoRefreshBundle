# AutoRefreshBundle

A [Kimai](https://www.kimai.org) plugin that keeps open Kimai pages up to date with changes made elsewhere: start a
timer in the mobile app, and the page open on your computer shows it within a second; stop it on the computer, and
the phone shows it too. Without the plugin, a Kimai page only refreshes after actions taken on that same page.

What refreshes is what Kimai itself refreshes after a change made on the page: the running timer in the toolbar,
the lists of timesheets (*My times*...) and the calendar. Nothing is reloaded while nothing changes.

## How it works

Kimai pushes the news to the open pages itself, with no other service in between, through
[Server-Sent Events](https://developer.mozilla.org/docs/Web/API/Server-sent_events):

1. Whenever a timesheet is created, changed, stopped, duplicated, restarted or deleted, from the web pages, the API,
   the mobile app or the console, the plugin marks its user as changed (a small file in `var/data/auto-refresh/`),
   once the request is over and the data saved.
2. Every visible Kimai page keeps a connection open to `/{locale}/auto-refresh/events`, behind the login. The server
   side looks at the user's mark once a second and sends a line to the page as soon as it changes.
3. The page then reads the user's ten most recent timesheets from the API and, if they differ from what it shows,
   triggers Kimai's own `kimai.timesheetUpdate` event, so Kimai refreshes the toolbar, lists and calendar as usual.
   A change made on the page itself also comes back through the connection: it is recognised and causes no second
   refresh.

A hidden page (another tab, the app in the background) closes its connection, and checks once when it is shown
again. Each connection lasts five minutes, then the browser opens a new one by itself, so no server process stays
busy forever. In case the connection is down, the page also checks every 60 seconds. Errors (server unreachable,
session expired) are silent: the browser reconnects by itself.

## Requirements

Kimai 2. Tested with 2.69.0 (official Docker image, Apache), directly and behind Caddy.

- Each visible Kimai page holds one web server process while it is open (Apache's default limit is 150): fine for a
  personal or small-team Kimai, not for hundreds of users at once.
- A reverse proxy must pass the stream through without buffering it. Caddy and Apache do; for nginx the plugin sends
  `X-Accel-Buffering: no`.
- The stream releases the login session before it starts (Kimai locks the session while a request has it open), so
  the page's other requests never wait for it. A visible page keeps the session alive.

## Configuration

Nothing to configure. Optional environment variable of the Kimai process:

- `AUTO_REFRESH_SECONDS`: seconds between the safety checks while the connection is down (default 60, at least 10).

## Installation

The plugin directory must be called `AutoRefreshBundle`:

```sh
cd /path/to/kimai/var/plugins
git clone https://github.com/6801318d8d/AutoRefreshBundle.git
cd /path/to/kimai
bin/console kimai:reload --env=prod
```

With the official Docker image (replace `kimai` with the name of your container):

```sh
git clone https://github.com/6801318d8d/AutoRefreshBundle.git
docker cp AutoRefreshBundle kimai:/opt/kimai/var/plugins/
docker exec kimai chown -R www-data:www-data /opt/kimai/var/plugins/AutoRefreshBundle
docker exec -u www-data kimai /opt/kimai/bin/console kimai:reload --env=prod
```

Run `kimai:reload` as `www-data`: run as root, it leaves cache files that the web server cannot write. Mount
`/opt/kimai/var/plugins` on a volume, otherwise the plugin is lost when the container is recreated. Open pages pick
the plugin up when they are reloaded. To remove it, delete its directory and run `kimai:reload` again.

The page script hooks into Kimai's frontend (its JavaScript plugins and events), which is not a public API: a future
Kimai version may break it. In that case pages simply stop refreshing by themselves; please open an issue.

## License

MIT
