<?php

namespace Laravel\Prompts;

use Shmop;

class TaskActivePrompt
{
    /**
     * The Shmop instance used to share the line count.
     */
    protected static ?Shmop $shmop = null;

    public static function update(int $lineCount): void
    {
        $shmop = static::getShmop();

        if ($shmop === false) {
            return;
        }

        shmop_write($shmop, pack('S', $lineCount), 0);
    }

    public static function remove(): void
    {
        static::update(0);
    }

    public static function lineCount(): ?int
    {
        $shmId = static::getShmop();

        if ($shmId === false) {
            return null;
        }

        $data = shmop_read($shmId, 0, 2);

        if ($data === false) {
            return null;
        }

        $lineCount = unpack('S', $data)[1];

        return $lineCount === 0 ? null : $lineCount;
    }

    protected static function getShmop(): Shmop|false
    {
        if (static::$shmop !== null) {
            return static::$shmop;
        }

        return static::$shmop = @shmop_open(0x70726d74, 'c', 0644, 2);
    }
}
