<?php

namespace KimaiPlugin\AutoRefreshBundle\Controller;

use App\Controller\AbstractController;
use Doctrine\DBAL\Connection;
use KimaiPlugin\AutoRefreshBundle\Sync\ChangeMarker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The event stream of an open page (Server-Sent Events): an "event: change" line as soon as one of the user's
 * timesheets changes anywhere, within about a second.
 *
 * Each connection lasts DURATION seconds, then the browser opens a new one by itself (EventSource), so that no web
 * server process stays busy forever. Before streaming, the session is closed (Kimai locks it while it is open: the
 * page's other requests would wait) and so is the database connection; while waiting, the stream only reads the
 * user's change marker once a second. The keep-alive comments let PHP notice a closed page within PING seconds.
 */
#[Route(path: '/auto-refresh/events', name: 'auto_refresh_events', methods: ['GET'])]
#[IsGranted('view_own_timesheet')]
final class StreamController extends AbstractController
{
    private const DURATION = 300;
    private const PING = 5;

    public function __invoke(Request $request, ChangeMarker $marker, Connection $connection): Response
    {
        $userId = (int) $this->getUser()->getId();
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }
        $connection->close();

        $response = new StreamedResponse(function () use ($marker, $userId): void {
            set_time_limit(0);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            $last = $marker->read($userId);
            // the browser reconnects 2 seconds after a connection ends
            echo "retry: 2000\n\n";
            flush();
            $end = time() + self::DURATION;
            $ping = time();
            while (time() < $end && !connection_aborted()) {
                sleep(1);
                $current = $marker->read($userId);
                if ($current !== $last) {
                    $last = $current;
                    echo "event: change\ndata: " . $current . "\n\n";
                } elseif (time() - $ping >= self::PING) {
                    echo ": ping\n\n";
                } else {
                    continue;
                }
                flush();
                $ping = time();
            }
        });
        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        // proxies that buffer responses (nginx) must pass this one through at once
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
