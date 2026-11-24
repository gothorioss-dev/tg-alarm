<?php

namespace App\Monitor;

/** Resources of the server. Containers without limits see the numbers of the host. */
class SystemInfo
{
    public function freeDisk(): ?int
    {
        $bytes = @disk_free_space((string) config('monitor.path'));

        return $bytes === false ? null : intdiv((int) $bytes, 1024 * 1024);
    }

    public function availableMemory(): ?int
    {
        return preg_match('/^MemAvailable:\s+(\d+) kB/m', (string) @file_get_contents('/proc/meminfo'), $match)
            ? intdiv((int) $match[1], 1024)
            : null;
    }

    public function load(): ?float
    {
        $load = sys_getloadavg();

        return $load === false ? null : $load[0];
    }
}
