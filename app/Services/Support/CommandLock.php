<?php

namespace App\Services\Support;

use RuntimeException;

class CommandLock
{
    /** @var resource|null */
    private $handle = null;

    public function acquire(string $name): bool
    {
        $directory = storage_path('framework/locks');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create lock directory: {$directory}");
        }

        $path = $directory.'/'.preg_replace('/[^a-z0-9_.-]+/i', '-', $name).'.lock';
        $handle = fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException("Cannot open lock file: {$path}");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if (! is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        $this->handle = null;
    }
}
