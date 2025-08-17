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

namespace RoachPHP\Tests\Downloader\Middleware;

use PHPUnit\Framework\TestCase;
use RoachPHP\Downloader\Middleware\RetryMiddleware;
use RoachPHP\Scheduling\ArrayRequestScheduler;
use RoachPHP\Scheduling\Timing\ClockInterface;
use RoachPHP\Scheduling\Timing\FakeClock;
use RoachPHP\Testing\Concerns\InteractsWithRequestsAndResponses;
use RoachPHP\Testing\FakeLogger;

final class RetryMiddlewareTest extends TestCase
{
    use InteractsWithRequestsAndResponses;

    private RetryMiddleware $middleware;

    private ArrayRequestScheduler $scheduler;

    private FakeLogger $logger;

    protected function setUp(): void
    {
        $this->scheduler = new ArrayRequestScheduler($this->createMock(ClockInterface::class));
        $this->logger = new FakeLogger();
        $this->middleware = new RetryMiddleware($this->scheduler, $this->logger);
    }

    public function testDoesNotRetrySuccessfulResponse(): void
    {
        $response = $this->makeResponse(status: 200);
        $this->middleware->configure([]);

        $result = $this->middleware->handleResponse($response);

        self::assertSame($response, $result);
        self::assertFalse($result->wasDropped());
        self::assertCount(0, $this->scheduler->forceNextRequests(10));
    }

    public function testDoesNotRetryNonRetryableErrorResponse(): void
    {
        $response = $this->makeResponse(status: 404);
        $this->middleware->configure(['retryOnStatus' => [500]]);

        $result = $this->middleware->handleResponse($response);

        self::assertSame($response, $result);
        self::assertFalse($result->wasDropped());
        self::assertCount(0, $this->scheduler->forceNextRequests(10));
    }

    public function testRetriesARetryableResponse(): void
    {
        $request = $this->makeRequest('https://example.com');
        $response = $this->makeResponse(request: $request, status: 503);
        $this->middleware->configure([
            'retryOnStatus' => [503],
            'maxRetries' => 2,
            'initialDelay' => 500,
        ]);

        $result = $this->middleware->handleResponse($response);

        self::assertTrue($result->wasDropped());

        $retriedRequests = $this->scheduler->forceNextRequests(10);
        self::assertCount(1, $retriedRequests);

        $retriedRequest = $retriedRequests[0];
        self::assertSame(1, $retriedRequest->getMeta('retry_count'));
        self::assertSame('https://example.com', $retriedRequest->getUri());
        self::assertSame(500, $retriedRequest->getOptions()['delay']);
    }

    public function testStopsRetryingAfterMaxRetries(): void
    {
        $request = $this->makeRequest()->withMeta('retry_count', 3);
        $response = $this->makeResponse(request: $request, status: 500);
        $this->middleware->configure(['maxRetries' => 3]);

        $result = $this->middleware->handleResponse($response);

        self::assertSame($response, $result);
        self::assertFalse($result->wasDropped());
        self::assertCount(0, $this->scheduler->forceNextRequests(10));
    }

    public function testCalculatesExponentialBackoffCorrectly(): void
    {
        $request = $this->makeRequest()->withMeta('retry_count', 2);
        $response = $this->makeResponse(request: $request, status: 500);
        $this->middleware->configure([
            'initialDelay' => 1000, // 1s
            'delayMultiplier' => 2.0,
        ]);

        $this->middleware->handleResponse($response);

        // initialDelay * (delayMultiplier ^ retry_count)
        // 1000 * (2.0 ^ 2) = 1000 * 4 = 4000ms
        $retriedRequest = $this->scheduler->forceNextRequests(10)[0];
        self::assertSame(4000, $retriedRequest->getOptions()['delay']);
    }
}
