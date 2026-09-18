<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Tiny `RateLimiterFactoryInterface` stub whose `consume()` always
 * returns a rejected `RateLimit`. Useful in controller-level
 * integration tests that need to assert the "rate-limit hit"
 * branch without driving 11 form submissions through Symfony's
 * kernel reset machinery.
 */
final class ClosedLimiterFactory
{
    public static function create(): RateLimiterFactoryInterface
    {
        $rejected = new RateLimit(0, new \DateTimeImmutable('+1 hour'), false, 1);
        $limiter = new class($rejected) implements LimiterInterface {
            public function __construct(private readonly RateLimit $rejected)
            {
            }

            public function reserve(int $tokens = 1, ?float $maxTime = null): \Symfony\Component\RateLimiter\Reservation
            {
                return new \Symfony\Component\RateLimiter\Reservation(0.0, $this->rejected);
            }

            public function consume(int $tokens = 1): RateLimit
            {
                return $this->rejected;
            }

            public function reset(): void
            {
            }
        };

        return new class($limiter) implements RateLimiterFactoryInterface {
            public function __construct(private readonly LimiterInterface $limiter)
            {
            }

            public function create(?string $key = null): LimiterInterface
            {
                return $this->limiter;
            }
        };
    }
}
