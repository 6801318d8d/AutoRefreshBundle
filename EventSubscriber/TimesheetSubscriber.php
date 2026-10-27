<?php

namespace KimaiPlugin\AutoRefreshBundle\EventSubscriber;

use App\Entity\Timesheet;
use App\Event\AbstractTimesheetEvent;
use App\Event\AbstractTimesheetMultipleEvent;
use App\Event\TimesheetCreatePostEvent;
use App\Event\TimesheetDeleteMultiplePreEvent;
use App\Event\TimesheetDeletePreEvent;
use App\Event\TimesheetDuplicatePostEvent;
use App\Event\TimesheetRestartPostEvent;
use App\Event\TimesheetStopPostEvent;
use App\Event\TimesheetUpdateMultiplePostEvent;
use App\Event\TimesheetUpdatePostEvent;
use KimaiPlugin\AutoRefreshBundle\Sync\ChangeMarker;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Notes whose timesheets were created, changed, stopped, duplicated, restarted or deleted (web pages, API, mobile app,
 * console) and marks those users as changed once the request is over: after the response and after the database
 * transaction, so that a page refreshed because of the mark reads the new data.
 */
final class TimesheetSubscriber implements EventSubscriberInterface
{
    /** @var array<int, true> */
    private array $users = [];

    public function __construct(private readonly ChangeMarker $marker)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TimesheetCreatePostEvent::class => 'onTimesheet',
            TimesheetUpdatePostEvent::class => 'onTimesheet',
            TimesheetStopPostEvent::class => 'onTimesheet',
            TimesheetDuplicatePostEvent::class => 'onTimesheet',
            TimesheetRestartPostEvent::class => 'onTimesheet',
            TimesheetDeletePreEvent::class => 'onTimesheet',
            TimesheetUpdateMultiplePostEvent::class => 'onTimesheets',
            TimesheetDeleteMultiplePreEvent::class => 'onTimesheets',
            KernelEvents::TERMINATE => 'flush',
            ConsoleEvents::TERMINATE => 'flush',
        ];
    }

    public function onTimesheet(AbstractTimesheetEvent $event): void
    {
        $this->note($event->getTimesheet());
    }

    public function onTimesheets(AbstractTimesheetMultipleEvent $event): void
    {
        foreach ($event->getTimesheets() as $timesheet) {
            $this->note($timesheet);
        }
    }

    public function flush(): void
    {
        foreach (array_keys($this->users) as $userId) {
            $this->marker->mark($userId);
        }
        $this->users = [];
    }

    private function note(Timesheet $timesheet): void
    {
        $id = $timesheet->getUser()?->getId();
        if ($id !== null) {
            $this->users[$id] = true;
        }
    }
}
