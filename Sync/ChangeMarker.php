<?php

namespace KimaiPlugin\AutoRefreshBundle\Sync;

/**
 * One small file per user in var/data/auto-refresh/, rewritten whenever one of the user's timesheets changes. The open
 * event streams (StreamController) read it every second: a different content means news for that user's pages.
 * A file rather than the database, so that a waiting stream holds no database connection.
 */
final class ChangeMarker
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function mark(int $userId): void
    {
        $dir = $this->projectDir . '/var/data/auto-refresh';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . '/' . $userId, microtime(true) . '-' . getmypid(), LOCK_EX);
    }

    public function read(int $userId): string
    {
        $value = @file_get_contents($this->projectDir . '/var/data/auto-refresh/' . $userId);

        return $value === false ? '' : $value;
    }
}
