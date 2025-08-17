<?php

declare(strict_types=1);

/**
 * Copyright (c) 2024 Kai Sassnowski
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/roach-php/roach
 */

namespace RoachPHP\Downloader\Middleware;

use Psr\Log\LoggerInterface;
use RoachPHP\Http\Response;
use RoachPHP\Scheduling\RequestSchedulerInterface;
use RoachPHP\Support\Configurable;

final class RetryMiddleware implements ResponseMiddlewareInterface
{
    use Configurable;

    public function __construct(
        private readonly RequestSchedulerInterface $scheduler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handleResponse(Response $response): Response
    {
        $request = $response->getRequest();
      
        /** @var int $retryCount */
        $retryCount = $request->getMeta('retry_count', 0);

        /** @var list<int> $retryOnStatus */
        $retryOnStatus = $this->option('retryOnStatus');
      
        /** @var int $maxRetries */
        $maxRetries = $this->option('maxRetries');

        if (\in_array($response->getStatus(), $retryOnStatus, true) && $retryCount < $maxRetries) {
            /** @var int $initialDelay */
            $initialDelay = $this->option('initialDelay');
          
            /** @var float $delayMultiplier */
            $delayMultiplier = $this->option('delayMultiplier');

            $delay = (int) ($initialDelay * ($delayMultiplier ** $retryCount));

            $this->logger->info(
                'Retrying request',
                [
                    'uri' => $request->getUri(),
                    'status' => $response->getStatus(),
                    'retry_count' => $retryCount + 1,
                    'delay_ms' => $delay,
                ],
            );

            $retryRequest = $request
                ->withMeta('retry_count', $retryCount + 1)
                ->addOption('delay', $delay);

            $this->scheduler->schedule($retryRequest);

            return $response->drop('Request being retried');
        }

        return $response;
    }

    private static function defaultOptions(): array
    {
        return [
            'retryOnStatus' => [500, 502, 503, 504],
            'maxRetries' => 3,
            'initialDelay' => 1000,
            'delayMultiplier' => 2.0,
        ];
    }
}
