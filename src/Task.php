<?php

namespace Laravel\Prompts;

use Closure;
use Laravel\Prompts\Support\Logger;
use Laravel\Prompts\Themes\Default\Concerns\InteractsWithStrings;
use RuntimeException;

class Task extends Prompt
{
    use InteractsWithStrings;

    /**
     * The minimum width for the longest line calculation.
     */
    protected int $minWidth = 0;

    /**
     * How long to wait between rendering each frame.
     */
    public int $interval = 100;

    /**
     * The number of times the task has been rendered.
     */
    public int $count = 0;

    /**
     * Whether the task can only be rendered once.
     */
    public bool $static = false;

    /**
     * The process ID after forking.
     */
    protected int $pid;

    /**
     * The socket for IPC communication.
     *
     * @var resource|null
     */
    protected $socket;

    /**
     * Pre-wrapped log lines for the scrolling output area.
     *
     * @var array<int, string>
     */
    public array $logs = [];

    /**
     * Stable status messages (success, warning, error).
     *
     * @var list<array{type: string, message: string}>
     */
    public array $stableMessages = [];

    /**
     * The maximum number of stable messages to display.
     */
    public int $maxStableMessages = 10;

    /**
     * The identifier for the task.
     */
    public string $identifier = '';

    /**
     * Whether the task has finished.
     */
    public bool $finished = false;

    /**
     * Buffer for incomplete lines from non-blocking socket reads.
     */
    protected string $buffer = '';

    /**
     * The log index where the current partial started, or null if not streaming.
     */
    protected ?int $partialStartIndex = null;

    /**
     * The current frame height plus the current prompt's frame height if one was rendered in the callback.
     */
    protected int $frameHeightWithPrompt;

    /**
     * The previous prompt's frame height if one was rendered in the callback.
     */
    protected ?int $prevPromptHeight = null;

    /**
     * Create a new Task instance.
     */
    public function __construct(public string $label = '', public int $limit = 10)
    {
        $this->identifier = uniqid();
    }

    /**
     * Render the task and execute the callback.
     *
     * @template TReturn of mixed
     *
     * @param  Closure(Logger): TReturn  $callback
     * @return TReturn
     */
    public function run(Closure $callback): mixed
    {
        $maxHeight = $this->terminal()->lines() - 10;

        $this->limit = min($this->limit, $maxHeight);
        // Max height - limit - divider - task label
        $this->maxStableMessages = max(0, $maxHeight - $this->limit - 2);

        $this->capturePreviousNewLines();

        if (!function_exists('pcntl_fork')) {
            return $this->renderStatically($callback);
        }

        $originalAsync = pcntl_async_signals(true);

        pcntl_signal(SIGINT, fn() => exit());

        try {
            $this->hideCursor();
            $this->render();

            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($sockets === false) {
                return $this->renderStatically($callback);
            }

            $this->pid = pcntl_fork();

            if ($this->pid === 0) {
                fclose($sockets[1]);
                $childSocket = $sockets[0];
                stream_set_blocking($childSocket, false);

                // @phpstan-ignore-line-next-line
                while (true) {
                    $this->receiveMessages($childSocket);

                    if (!$this->finished) {
                        $this->render();
                        $this->count++;
                    }

                    usleep($this->interval * 1000);
                }
            } else {
                fclose($sockets[0]);
                $this->socket = $sockets[1];

                $logger = new Logger($this->identifier, $this->socket);
                $result = $callback($logger);

                if ($this->socket !== null) {
                    // Send a reset message to the parent process to reset the terminal.
                    fwrite($this->socket, $this->identifier . '_' . 'reset:' . ($originalAsync ? 1 : 0) . PHP_EOL);
                    usleep($this->interval * 2000);
                }

                return $result;
            }
        } catch (\Throwable $e) {
            $this->resetTerminal($originalAsync);

            throw $e;
        }
    }

    /**
     * Receive and process messages from the parent process.
     *
     * @param  resource  $socket
     */
    protected function receiveMessages($socket): void
    {
        $prefix = preg_quote($this->identifier, '/');

        while (($data = fgets($socket)) !== false) {
            // Buffer incomplete lines from non-blocking reads.
            if (!str_ends_with($data, PHP_EOL)) {
                $this->buffer .= $data;

                continue;
            }

            $line = rtrim($this->buffer . $data, PHP_EOL);
            $this->buffer = '';

            if ($line === '') {
                continue;
            }

            // Check for typed messages: {id}_{type}:{content}
            if (
                preg_match(
                    '/^' . $prefix . '_(success|warning|error|label|reset|partial|commitpartial|clear):(.*)/',
                    $line,
                    $matches,
                )
            ) {
                $type = $matches[1];
                $content = $matches[2];

                if ($type === 'clear') {
                    $this->logs = [];
                    $this->partialStartIndex = null;

                    continue;
                }

                if ($type === 'reset') {
                    $this->resetTerminal((bool) $content);

                    continue;
                }

                if ($type === 'partial') {
                    $this->replacePartialLines($content);

                    continue;
                }

                if ($type === 'commitpartial') {
                    $this->partialStartIndex = null;

                    continue;
                }

                if ($type === 'label') {
                    $this->label = $content;
                } else {
                    $this->stableMessages[] = ['type' => $type, 'message' => $content];
                    $this->logs = [];
                    $this->partialStartIndex = null;
                }

                while (count($this->stableMessages) > $this->maxStableMessages) {
                    array_shift($this->stableMessages);
                }

                continue;
            }

            // Regular log line — strip cursor-reset control sequences.
            $line = preg_replace('/\e\[(?:1)?G\e\[2K/', '', $line);

            // Wrap and add to ring buffer.
            $this->addLogLines($line);
        }
    }

    /**
     * Wrap a log line and append to the ring buffer, trimming to the limit.
     */
    protected function addLogLines(string $line): void
    {
        $width = $this->terminal()->cols() - 10;
        $plainText = $this->stripEscapeSequences($line);

        if (mb_strwidth($plainText) > $width) {
            $wrapped = $this->ansiWordwrap($line, $width);
        } else {
            $wrapped = [$line];
        }

        array_push($this->logs, ...$wrapped);

        while (count($this->logs) > $this->limit) {
            array_shift($this->logs);
        }
    }

    /**
     * Replace the in-progress partial lines with the full accumulated text.
     */
    protected function replacePartialLines(string $text): void
    {
        if ($this->partialStartIndex === null) {
            $this->partialStartIndex = count($this->logs);
        }

        // Truncate back to where the partial started.
        $this->logs = array_slice($this->logs, 0, $this->partialStartIndex);

        // Wrap and append the full accumulated partial text.
        $width = $this->terminal()->cols() - 10;
        $plainText = $this->stripEscapeSequences($text);

        if (mb_strwidth($plainText) > $width) {
            $wrapped = $this->ansiWordwrap($text, $width);
        } else {
            $wrapped = [$text];
        }

        array_push($this->logs, ...$wrapped);

        while (count($this->logs) > $this->limit) {
            array_shift($this->logs);
            $this->partialStartIndex = max(0, $this->partialStartIndex - 1);
        }
    }

    /**
     * Reset the terminal.
     */
    protected function resetTerminal(bool $originalAsync): void
    {
        $this->finished = true;

        pcntl_async_signals($originalAsync);
        pcntl_signal(SIGINT, SIG_DFL);

        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }

        $this->eraseRenderedLines();
    }

    /**
     * Render a static version of the task.
     *
     * @template TReturn of mixed
     *
     * @param  Closure(Logger): TReturn  $callback
     * @return TReturn
     */
    protected function renderStatically(Closure $callback): mixed
    {
        $this->static = true;

        try {
            $this->hideCursor();
            $this->render();

            $logger = new Logger($this->identifier);
            $result = $callback($logger);
        } finally {
            $this->eraseRenderedLines();
        }

        return $result;
    }

    /**
     * Disable prompting for input.
     *
     * @throws RuntimeException
     */
    public function prompt(): never
    {
        throw new RuntimeException('Task cannot be prompted.');
    }

    /**
     * Get the current value of the prompt.
     */
    public function value(): bool
    {
        return true;
    }

    /**
     * Clear the lines rendered by the task.
     */
    protected function eraseRenderedLines(): void
    {
        $lines = explode(PHP_EOL, $this->prevFrame);
        $this->moveCursor(-999, -$this->frameHeightWithPrompt + 1);
        $this->eraseDown();
    }

    /**
     * Clean up after the task.
     */
    public function __destruct()
    {
        if (!empty($this->pid)) {
            posix_kill($this->pid, SIGHUP);
        }

        parent::__destruct();
    }

    protected function render(): void
    {
        $this->terminal()->initDimensions();

        $frame = $this->renderTheme();

        if ($frame === $this->prevFrame) {
            return;
        }

        if ($this->state === 'initial') {
            static::output()->write($frame);

            $this->state = 'active';
            $this->prevFrame = $frame;
            $this->frameHeightWithPrompt = count(explode(PHP_EOL, $frame)) + (TaskActivePrompt::lineCount() ?? 0);

            return;
        }

        $terminalHeight = $this->terminal()->lines();
        $previousFrameHeight = count(explode(PHP_EOL, $this->prevFrame));
        $renderableLines = array_slice(explode(PHP_EOL, $frame), abs(min(0, $terminalHeight - $previousFrameHeight)));
        $promptLineCount = TaskActivePrompt::lineCount();
        $promptWasInactivated = $promptLineCount === null && $this->prevPromptHeight !== null;

        $this->frameHeightWithPrompt = count($renderableLines) + $promptLineCount;

        if ($promptLineCount !== null) {
            $this->moveCursorUp($previousFrameHeight + $promptLineCount - 2);
        } elseif ($this->prevPromptHeight !== null) {
            $this->moveCursorUp($previousFrameHeight + $this->prevPromptHeight - 2);
        } else {
            $this->moveCursorUp(min($terminalHeight, $previousFrameHeight) - 1);
        }

        /**
         * The newlines which create a gap between the task and the prompt.
         *
         * @see \Laravel\Prompts\Themes\Default\Renderer::__toString()
         * @see \Laravel\Prompts\Output\ConsoleOutput::doWrite()
         */
        $gapNewlines = max(2 - (strlen($frame) - strlen(rtrim($frame, PHP_EOL))), 0);

        /*
         * The previous frame is always erased and the prompt is erased only if it was inactivated in this render.
         * Then, if the prompt was inactivated in this render, we'll also include the gap newlines in the erased lines
         * because there's no longer a prompt to create a gap for.
         */
        $this->eraseLines(
            $previousFrameHeight - 1 +
                ($promptWasInactivated ? $this->prevPromptHeight : 0) -
                ($promptWasInactivated ? 0 : $gapNewlines),
        );
        $this->output()->write(implode(PHP_EOL, $renderableLines));

        if ($promptLineCount !== null) {
            $this->moveCursor(x: 0, y: $promptLineCount - 1);
        }

        $this->prevFrame = $frame;
        $this->prevPromptHeight = $promptLineCount;
    }
}
