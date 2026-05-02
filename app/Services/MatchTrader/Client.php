<?php

declare(strict_types=1);

namespace App\Services\MatchTrader;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

class Client
{
    private readonly GuzzleClient $http;

    private readonly string $baseUrl;

    private readonly string $token;

    private readonly int $maxRetries;

    /** Simple request timestamp ring-buffer for client-side rate limiting */
    private array $requestTimestamps = [];

    private readonly int $rateLimit; // requests per minute

    public function __construct()
    {
        $this->baseUrl    = rtrim((string) config('matchtrader.base_url'), '/');
        $this->token      = (string) config('matchtrader.token');
        $this->maxRetries = (int) config('matchtrader.retry_attempts', 3);
        $this->rateLimit  = (int) config('matchtrader.rate_limit_per_minute', 500);

        $this->http = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'timeout'  => 30,
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept'        => 'application/json',
            ],
        ]);
    }

    // ── Public API methods ───────────────────────────────────────────────────

    /**
     * Fetch a page of accounts from /v1/accounts.
     *
     * @return array{data: array, total: int, page: int, size: int}
     */
    public function accounts(int $page = 0, int $size = 500): array
    {
        return $this->get('/v1/accounts', ['page' => $page, 'size' => $size]);
    }

    /**
     * Fetch all accounts across all pages (generator to avoid memory pressure).
     *
     * @return \Generator<array>
     */
    public function allAccounts(int $pageSize = 500): \Generator
    {
        yield from $this->paginate('/v1/accounts', $pageSize);
    }

    /**
     * Fetch deposits, optionally filtered by a since timestamp.
     *
     * @return array{data: array, total: int}
     */
    public function deposits(?string $since = null, int $page = 0, int $size = 500): array
    {
        $params = ['page' => $page, 'size' => $size];
        if ($since !== null) {
            $params['from'] = $since; // MTR API param is 'from', not 'dateFrom' (verified 2026-04-26)
        }

        return $this->get('/v1/deposits', $params);
    }

    /**
     * @return \Generator<array>
     */
    public function allDeposits(?string $since = null, int $pageSize = 500): \Generator
    {
        $params = [];
        if ($since !== null) {
            $params['from'] = $since; // MTR API param is 'from', not 'dateFrom' (verified 2026-04-26)
        }
        yield from $this->paginate('/v1/deposits', $pageSize, $params);
    }

    /**
     * Fetch withdrawals, optionally filtered by a since timestamp.
     *
     * @return array{data: array, total: int}
     */
    public function withdrawals(?string $since = null, int $page = 0, int $size = 500): array
    {
        $params = ['page' => $page, 'size' => $size];
        if ($since !== null) {
            $params['from'] = $since; // MTR API param is 'from', not 'dateFrom' (verified 2026-04-26)
        }

        return $this->get('/v1/withdrawals', $params);
    }

    /**
     * @return \Generator<array>
     */
    public function allWithdrawals(?string $since = null, int $pageSize = 500): \Generator
    {
        $params = [];
        if ($since !== null) {
            $params['from'] = $since; // MTR API param is 'from', not 'dateFrom' (verified 2026-04-26)
        }
        yield from $this->paginate('/v1/withdrawals', $pageSize, $params);
    }

    /**
     * Fetch all offers (product catalog).
     *
     * @return array<array>
     */
    public function offers(): array
    {
        $response = $this->get('/v1/offers', ['size' => 1000]);

        return $response['offers'] ?? $response['content'] ?? $response['data'] ?? $response;
    }

    /**
     * Fetch all branches.
     *
     * @return array<array>
     */
    public function branches(): array
    {
        $response = $this->get('/v1/branches', ['size' => 500]);

        return $response['branches'] ?? $response['content'] ?? $response['data'] ?? $response;
    }

    /**
     * Fetch all prop challenge records.
     *
     * @return array<array>
     */
    public function propChallenges(int $page = 0, int $size = 500): array
    {
        return $this->get('/v1/prop/challenges', ['page' => $page, 'size' => $size]);
    }

    /**
     * @return \Generator<array>
     */
    public function allPropChallenges(int $pageSize = 500): \Generator
    {
        yield from $this->paginate('/v1/prop/challenges', $pageSize);
    }

    /**
     * Fetch all prop challenge account records (paginated).
     *
     * @return \Generator<array>
     */
    public function allPropAccounts(int $pageSize = 500): \Generator
    {
        yield from $this->paginate('/v1/prop/accounts', $pageSize);
    }

    /**
     * Fetch a single CRM account by email address.
     * Returns null on 404 (account not found in MTR).
     * Confirmed working: /v1/accounts/by-email/{email} returns 200 with full account shape.
     */
    public function accountByEmail(string $email): ?array
    {
        try {
            $result = $this->get('/v1/accounts/by-email/' . rawurlencode($email));

            return empty($result) ? null : $result;
        } catch (RequestException $e) {
            if ($e->getResponse()?->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    // ── Pagination ───────────────────────────────────────────────────────────

    /**
     * Paginate through all pages and yield individual records.
     *
     * @return \Generator<array>
     */
    private function paginate(string $endpoint, int $pageSize, array $extraParams = []): \Generator
    {
        $page  = 0;
        $total = null;
        $seen  = 0;

        do {
            $response = $this->get($endpoint, array_merge($extraParams, [
                'page' => $page,
                'size' => $pageSize,
            ]));

            // Handle paginated ({content:[…], totalElements:N}), data-wrapped ({data:[…], total:N}),
            // and flat array responses (e.g. /v1/prop/challenges returns a bare JSON array)
            $records = $response['content'] ?? $response['data'] ?? (array_is_list($response) ? $response : []);

            if ($total === null) {
                $total = $response['totalElements'] ?? $response['total'] ?? count($records);
            }

            foreach ($records as $record) {
                yield $record;
                $seen++;
            }

            $page++;
        } while ($seen < $total && count($records) === $pageSize);
    }

    // ── HTTP layer ───────────────────────────────────────────────────────────

    private function get(string $endpoint, array $params = []): array
    {
        $this->throttle();

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->http->get($endpoint, ['query' => $params]);
                $body     = (string) $response->getBody();
                $decoded  = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                return is_array($decoded) ? $decoded : [];
            } catch (RequestException $e) {
                $statusCode = $e->getResponse()?->getStatusCode() ?? 0;

                if ($statusCode === 429) {
                    $waitSeconds = $attempt <= 1 ? 65 : 120;
                    Log::warning("MTR rate limit hit on {$endpoint}, waiting {$waitSeconds}s (attempt {$attempt})");
                    sleep($waitSeconds);
                } elseif ($statusCode >= 500) {
                    $backoff = min(30, 5 * $attempt);
                    Log::warning("MTR server error {$statusCode} on {$endpoint}, backoff {$backoff}s (attempt {$attempt})");
                    sleep($backoff);
                } else {
                    Log::error("MTR request failed: {$e->getMessage()}", [
                        'endpoint' => $endpoint,
                        'status'   => $statusCode,
                    ]);
                    throw $e;
                }

                if ($attempt >= $this->maxRetries) {
                    Log::error("MTR gave up after {$attempt} attempts on {$endpoint}");
                    throw $e;
                }
            } catch (\JsonException $e) {
                Log::error("MTR returned invalid JSON from {$endpoint}: {$e->getMessage()}");
                throw $e;
            }
        }
    }

    /**
     * Client-side rate limiter — ensures we don't exceed MTR's 500 req/min limit.
     * Sleeps as needed to stay within the window.
     */
    private function throttle(): void
    {
        $now    = microtime(true);
        $window = 60.0;

        // Remove timestamps older than 1 minute
        $this->requestTimestamps = array_filter(
            $this->requestTimestamps,
            fn (float $ts) => ($now - $ts) < $window
        );

        if (count($this->requestTimestamps) >= $this->rateLimit) {
            // Sleep until the oldest request falls out of the window
            $oldest     = min($this->requestTimestamps);
            $sleepUntil = $oldest + $window;
            $sleepMs    = (int) (($sleepUntil - $now) * 1_000_000);

            if ($sleepMs > 0) {
                usleep($sleepMs);
            }
        }

        $this->requestTimestamps[] = microtime(true);
    }
}
