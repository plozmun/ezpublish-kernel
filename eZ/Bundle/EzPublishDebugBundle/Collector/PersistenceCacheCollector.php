<?php

/**
 * This file is part of the eZ Publish Kernel package.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace eZ\Bundle\EzPublishDebugBundle\Collector;

use eZ\Publish\Core\Persistence\Cache\PersistenceLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/**
 * Data collector listing SPI cache calls.
 */
class PersistenceCacheCollector extends DataCollector
{
    /**
     * @var PersistenceLogger
     */
    private $logger;

    public function __construct(PersistenceLogger $logger)
    {
        $this->logger = $logger;
    }

    public function collect(Request $request, Response $response, \Exception $exception = null)
    {
        $this->data = [
            'stats' => $this->logger->getStats(),
            'calls_logging_enabled' => $this->logger->isCallsLoggingEnabled(),
            'calls' => $this->logger->getCalls(),
            'cached' => $this->logger->getCached(),
            'handlers' => $this->logger->getLoadedUnCachedHandlers(),
        ];
    }

    public function getName()
    {
        return 'ezpublish.debug.persistence';
    }

    /**
     * Returns call count.
     *
     * @deprecaterd since 7.5, use getStats().
     *
     * @return int
     */
    public function getCount()
    {
        return $this->data['stats']['calls'] + $this->data['stats']['misses'];
    }

    /**
     * Returns stats on Persistance cache usage.
     *
     * @since 7.5
     *
     * @return int[<string>]
     */
    public function getStats()
    {
        return $this->data['stats'];
    }

    /**
     * Returns flag to indicate if logging of calls is enabled or not.
     *
     * Typically not enabled in prod.
     *
     * @return bool
     */
    public function getCallsLoggingEnabled()
    {
        return $this->data['calls_logging_enabled'];
    }

    /**
     * Returns all calls.
     *
     * @return array
     */
    public function getCalls()
    {
        return $this->getCallData(array_merge($this->data['calls'], $this->data['cached']));
    }

    private function getCallData(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $calls = $count = [];
        foreach ($data as $call) {
            $callArguments = $this->simplifyCallArguments($call['arguments']);
            $hash = hash('md5', $call['method'] . $callArguments);
            if (isset($calls[$hash])) {
                $calls[$hash]['traces'][$call['type']][] = implode(', ', $call['trace']);
                ++$calls[$hash]['count'];
                ++$count[$hash];

                continue;
            }

            list($class, $method) = explode('::', $call['method']);
            $namespace = explode('\\', $class);
            $class = array_pop($namespace);
            $calls[$hash] = [
                'namespace' => $namespace,
                'class' => $class,
                'method' => $method,
                'arguments' => $callArguments,
                'traces' => [],
                'count' => 1,
            ];
            $calls[$hash]['traces'][$call['type']][] = implode(', ', $call['trace']);
            $count[$hash] = 1;
        }
        unset($data);

        array_multisort($count, SORT_DESC, $calls);

        return $calls;
    }

    private function simplifyCallArguments(array $arguments): string
    {
        $string = '';
        foreach ($arguments as $key => $value) {
            if (!empty($string)) {
                $string .= ', ';
            }

            if (!is_numeric($key)) {
                $string .= $key . ':';
            }

            if (is_array($value)) {
                $string .= '[' . implode(',', $value) . ']';
            } else {
                $string .= $value;
            }
        }

        return $string;
    }

    /**
     * Returns un cached handlers being loaded.
     *
     * @return array
     */
    public function getHandlers()
    {
        $handlers = [];
        foreach ($this->data['handlers'] as $handler => $count) {
            list($class, $method) = explode('::', $handler);
            unset($class);
            $handlers[$method] = $method . '(' . $count . ')';
        }

        return $handlers;
    }

    /**
     * Returns un cached handlers being loaded.
     *
     * @return array
     */
    public function getHandlersCount()
    {
        return array_sum($this->data['handlers']);
    }
}
