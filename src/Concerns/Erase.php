<?php

namespace Laravel\Prompts\Concerns;

trait Erase
{
    /**
     * Erase the given number of lines downwards from the cursor position.
     */
    public function eraseLines(int $count): void
    {
        if ($count < 1) {
            return;
        }

        $clear = '';

        for ($i = 0; $i < $count; $i++) {
            $clear .= "\e[2K";

            if ($i < $count - 1) {
                $clear .= "\e[1B";
            }
        }

        if ($count) {
            $clear .= "\e[" . $count - 1 . "A\e[G";
        }

        static::writeDirectly($clear);
    }

    /**
     * Erase from cursor until end of screen.
     */
    public function eraseDown(): void
    {
        static::writeDirectly("\e[J");
    }
}
