<?php

namespace KimaiPlugin\AutoRefreshBundle\EventSubscriber;

use App\Event\ThemeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Keeps an open Kimai page up to date with changes made elsewhere: the mobile app, another browser or device, the
 * API. Kimai's pages only refresh after actions taken on the same page.
 *
 * While the page is visible it listens to Kimai's event stream (StreamController, Server-Sent Events): when the user's
 * timesheets change, the script reads the ten most recent ones (GET /api/timesheets, ordered by begin, with the
 * session of the page) and, if they differ from what the page shows, triggers Kimai's own "kimai.timesheetUpdate"
 * event. Kimai then reloads the running timer in the toolbar, the lists that listen to it (My times...) and the
 * calendar, exactly as after a change made on the page. The same check runs when the page is shown again and, in
 * case the stream is down, every AUTO_REFRESH_SECONDS seconds (default 60, at least 10).
 * A hidden page closes its stream (no server process kept busy for it). A change made on the page itself comes back
 * through the stream too: within 3 seconds of it the check only takes the new state, so it causes no second refresh.
 * Errors (server down, session expired) are ignored silently: the browser reconnects the stream by itself and the
 * next check tries again.
 */
final class AutoRefreshSubscriber implements EventSubscriberInterface
{
    private const SCRIPT = <<<'HTML'
        <script>
        document.addEventListener('kimai.initialized', function (event) {
            const config = __AUTO_REFRESH_CONFIG__;
            let api, events;
            try {
                api = event.detail.kimai.getPlugin('api');
                events = event.detail.kimai.getPlugin('event');
            } catch (error) {
                console.warn('AutoRefreshBundle disabled:', error);
                return;
            }
            const query = {size: 10, orderBy: 'begin', order: 'DESC'};
            let shown = null;
            let busy = false;
            let ownEvent = false;
            let quietUntil = 0;
            let source = null;

            // what the page shows of the recent entries: the fields a change elsewhere can touch
            function fingerprint(entries) {
                return JSON.stringify(entries.map((t) => [t.id, t.begin, t.end, t.project, t.activity, t.description, t.tags]));
            }

            // refresh: whether a difference triggers Kimai's update event, or only becomes the new state
            function check(refresh) {
                if (busy || document.visibilityState !== 'visible') {
                    return;
                }
                busy = true;
                api.get(config.url, query, function (entries) {
                    busy = false;
                    const current = fingerprint(entries);
                    const changed = shown !== null && current !== shown;
                    shown = current;
                    if (changed && refresh) {
                        ownEvent = true;
                        try {
                            events.trigger('kimai.timesheetUpdate');
                        } finally {
                            ownEvent = false;
                        }
                    }
                }, function () {
                    // offline, server down, session expired: no alert, the next check tries again
                    busy = false;
                });
            }

            // a change made on this page: Kimai refreshes by itself, the result is the new state
            for (const name of ['kimai.timesheetUpdate', 'kimai.timesheetDelete']) {
                document.addEventListener(name, function () {
                    if (!ownEvent) {
                        quietUntil = Date.now() + 3000;
                        setTimeout(function () { check(false); }, 500);
                    }
                });
            }

            function connect() {
                if (source !== null || typeof EventSource === 'undefined') {
                    return;
                }
                source = new EventSource(config.stream);
                source.addEventListener('change', function () {
                    check(Date.now() > quietUntil);
                });
            }

            function disconnect() {
                if (source !== null) {
                    source.close();
                    source = null;
                }
            }

            function onVisibility() {
                if (document.visibilityState === 'visible') {
                    connect();
                    check(true);
                } else {
                    disconnect();
                }
            }

            check(false);
            if (document.visibilityState === 'visible') {
                connect();
            }
            setInterval(function () { check(true); }, config.interval * 1000);
            document.addEventListener('visibilitychange', onVisibility);
            window.addEventListener('focus', function () { check(true); });
            window.addEventListener('pagehide', disconnect);
        });
        </script>
        HTML;

    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ThemeEvent::JAVASCRIPT => ['onJavascript', 100]];
    }

    public function onJavascript(ThemeEvent $event): void
    {
        // the login page triggers the event too, without user
        if ($event->getUser() === null || !$this->auth->isGranted('view_own_timesheet')) {
            return;
        }
        $seconds = (int) ($_SERVER['AUTO_REFRESH_SECONDS'] ?? getenv('AUTO_REFRESH_SECONDS') ?: 60);
        $config = [
            'url' => $this->router->generate('get_timesheets'),
            'stream' => $this->router->generate('auto_refresh_events'),
            'interval' => max(10, $seconds),
        ];
        $json = json_encode($config, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_THROW_ON_ERROR);
        $event->addContent(str_replace('__AUTO_REFRESH_CONFIG__', $json, self::SCRIPT));
    }
}
