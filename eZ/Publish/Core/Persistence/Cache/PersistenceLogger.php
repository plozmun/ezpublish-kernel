<?php

/**
 * File containing the Persistence Cache SPI logger class.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace eZ\Publish\Core\Persistence\Cache;

/**
 * Log un-cached & cached use of SPI Persistence.
 */
class PersistenceLogger
{
    const NAME = 'PersistenceLogger';

    /**
     * @var int[]
     */
    protected $stats = [
        'calls' => 0,
        'misses' => 0,
        'hits' => 0,
        'memory' => 0,
    ];

    /**
     * @var bool
     */
    protected $logCalls = true;

    /**
     * @var array
     */
    protected $calls = [];

    /**
     * @var array
     */
    protected $cached = [];

    /**
     * @var array
     */
    protected $unCachedHandlers = [];

    /**
     * @param bool $logCalls Flag to enable logging of calls or not, provides extra debug info about calls made to SPI
     *                       level, including where they come form. However this uses quite a bit of memory.
     */
    public function __construct(bool $logCalls = true)
    {
        $this->logCalls = $logCalls;
    }

    /**
     * Log uncached SPI calls with method name and arguments.
     *
     * NOTE: As of 7.5 this method is meant for logging calls to uncached spi method calls,
     *       for cache miss calls to cached SPI methods migrate to use {@see logCacheMiss()}.
     *
     * @param string $method
     * @param array $arguments
     */
    public function logCall(string $method, array $arguments = []): void
    {
        ++$this->stats['calls'];
        if (!$this->logCalls) {
            return;
        }

        $this->calls[] = $this->getCacheCallData(
            $method,
            $arguments,
            \array_slice(
                \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 7),
                2
            ),
            'call'
        );
    }

    /**
     * Log Cache miss, gets info it needs by backtrace if needed.
     *
     * @since 7.5
     *
     * @param array $arguments
     * @param int $traceOffset
     */
    public function logCacheMiss(array $arguments = [], int $traceOffset = 2): void
    {
        ++$this->stats['misses'];
        if (!$this->logCalls) {
            return;
        }

        $trace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 7);
        $this->cached[] = $this->getCacheCallData(
            $trace[$traceOffset - 1]['function'],
            $arguments,
            \array_slice($trace, $traceOffset),
            'miss'
        );
    }

    /**
     * Log a Cache hit, gets info it needs by backtrace if needed.
     *
     * @since 7.5
     *
     * @param array $arguments
     * @param int $traceOffset
     * @param bool $inMemory Denotes is cache hit was from memory (php variable), as opposed to from cache pool which
     *                       is usually disk or remote cache service.
     */
    public function logCacheHit(array $arguments = [], int $traceOffset = 2, bool $inMemory = false): void
    {
        if ($inMemory) {
            ++$this->stats['memory'];

            return;
        }

        ++$this->stats['hits'];
        if (!$this->logCalls) {
            return;
        }

        // @todo Check memory usage
        $trace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 7);
        $this->cached[] = $this->getCacheCallData(
            $trace[$traceOffset - 1]['class'] . '::' . $trace[$traceOffset - 1]['function'],
            $arguments,
            \array_slice($trace, $traceOffset),
            'hit'
        );
    }

    private function getCacheCallData($method, array $arguments, array $trimmedBacktrace, string $type): array
    {
        $callData = [
            'method' => $method,
            'arguments' => $arguments,
            'trace' => $this->getSimpleCallTrace($trimmedBacktrace),
            'type' => $type,
        ];

        return $callData;
    }

    private function getSimpleCallTrace(array $backtrace): array
    {
        $calls = [];
        foreach ($backtrace as $call) {
            if (!isset($call['class']) || strpos($call['class'], '\\') === false) {
                // skip if class has no namespace (Symfony lazy proxy or plain function)
                continue;
            }

            $calls[] = $call['class'] . $call['type'] . $call['function'] . '()';

            // Break out as soon as we have listed 1 class outside of kernel
            if (strpos($call['class'], 'eZ\\Publish\\Core\\') !== 0) {
                break;
            }
        }

        return $calls;
    }

    /**
     * Log un-cached handler being loaded.
     *
     * @param string $handler
     */
    public function logUnCachedHandler(string $handler): void
    {
        if (!isset($this->unCachedHandlers[$handler])) {
            $this->unCachedHandlers[$handler] = 0;
        }
        ++$this->unCachedHandlers[$handler];
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * Counts the total of spi calls (cache misses and uncached calls).
     *
     * @deprecated Since 7.5, use getStats().
     */
    public function getCount(): int
    {
        return $this->stats['calls'];
    }

    /**
     * Get stats (calls/misses/hits/memory).
     *
     * @since 7.5
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    public function isCallsLoggingEnabled(): bool
    {
        return $this->logCalls;
    }

    public function getCalls(): array
    {
        return $this->calls;
    }

    public function getCached(): array
    {
        return $this->cached;
    }

    public function getLoadedUnCachedHandlers(): array
    {
        return $this->unCachedHandlers;
    }
}
