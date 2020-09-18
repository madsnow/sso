<?php

declare(strict_types=1);

namespace Jasny\SSO\Server;

use Jasny\Immutable;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Single sign-on server.
 * The SSO server is responsible of managing users sessions which are available for brokers.
 */
class Server
{
    use Immutable\With;

    /**
     * Callback to get the secret for a broker.
     * @var \Closure
     */
    protected $getBrokerInfo;

    /**
     * Storage for broker session links.
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Service to interact with sessions.
     * @var SessionInterface
     */
    protected $session;

    /**
     * Class constructor.
     *
     * @param callable(string):?array $getBrokerInfo
     * @param CacheInterface          $cache
     */
    public function __construct(callable $getBrokerInfo, CacheInterface $cache)
    {
        $this->getBrokerInfo = \Closure::fromCallable($getBrokerInfo);
        $this->cache = $cache;

        $this->logger = new NullLogger();
        $this->session = new GlobalSession();
    }

    /**
     * Get a copy of the service with logging.
     *
     * @return static
     */
    public function withLogger(LoggerInterface $logger): self
    {
        return $this->withProperty('logger', $logger);
    }

    /**
     * Get a copy of the service with a custom session service.
     *
     * @return static
     */
    public function withSession(SessionInterface $session): self
    {
        return $this->withProperty('session', $session);
    }


    /**
     * Start the session for broker requests to the SSO server.
     *
     * @throws BrokerException
     * @throws ServerException
     */
    public function startBrokerSession(?ServerRequestInterface $request = null): void
    {
        if ($this->session->isActive()) {
            throw new ServerException("Session is already started", 400);
        }

        $bearer = $this->getBearerToken($request);

        if ($bearer === null) {
            throw new BrokerException("Broker didn't use bearer authentication", 401);
        }

        [$brokerId, $token] = $this->parseBearer($bearer);

        try {
            $sessionId = $this->cache->get('SSO-' . $brokerId . '-' . $token);
        } catch (\Exception $exception) {
            $this->logger->error(
                "Failed to get session id: " . $exception->getMessage(),
                ['broker' => $brokerId, 'token' => $token]
            );
            throw new ServerException("Failed to get session id", 500, $exception);
        }

        if (!$sessionId) {
            $this->logger->warning(
                "Bearer token isn't attached to a client session",
                ['broker' => $brokerId, 'token' => $token]
            );
            throw new BrokerException("Bearer token isn't attached to a client session", 403);
        }

        $this->session->start($sessionId);

        $this->logger->debug(
            "Broker request with session",
            ['broker' => $brokerId, 'token' => $token, 'session' => $sessionId]
        );
    }

    /**
     * Get bearer token from Authorization header.
     */
    protected function getBearerToken(?ServerRequestInterface $request = null): ?string
    {
        $authorization = $request === null
            ? ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
            : $request->getHeaderLine('Authorization');

        return strpos($authorization, 'Bearer') === 0
            ? substr($authorization, 7)
            : null;
    }

    /**
     * Get the broker id and token from the bearer token used by the broker.
     *
     * @return string[]
     * @throws BrokerException
     */
    protected function parseBearer(string $bearer): array
    {
        $matches = null;

        if (!(bool)preg_match('/^SSO-(\w*+)-(\w*+)-([a-z0-9]*+)$/', $bearer, $matches)) {
            $this->logger->warning("Invalid bearer token", ['bearer' => $bearer]);
            throw new BrokerException("Invalid bearer token");
        }

        [, $brokerId, $token, $checksum] = $matches;
        $this->validateChecksum($checksum, 'bearer', $brokerId, $token);

        return [$brokerId, $token];
    }

    /**
     * Generate cache key for linking the broker token to the client session.
     */
    protected function getCacheKey(string $brokerId, string $token): string
    {
        return "SSO-{$brokerId}-{$token}";
    }

    /**
     * Get the broker secret using the configured callback.
     *
     * @param string $brokerId
     * @return string|null
     */
    protected function getBrokerSecret(string $brokerId): ?string
    {
        return ($this->getBrokerInfo)($brokerId)['secret'] ?? null;
    }

    /**
     * Generate checksum for a broker.
     */
    protected function generateChecksum(string $command, string $brokerId, string $token): string
    {
        try {
            $secret = $this->getBrokerSecret($brokerId);
        } catch (\Exception $exception) {
            $this->logger->warning(
                "Failed to get broker secret: " . $exception->getMessage(),
                ['broker' => $brokerId, 'token' => $token]
            );
            throw new ServerException("Failed to get broker secret", 500, $exception);
        }

        if ($secret === null) {
            $this->logger->warning("Unknown broker id", ['broker' => $brokerId, 'token' => $token]);
            throw new BrokerException("Unknown broker id", 400);
        }

        return hash_hmac('sha256', $command . ':' . $token, $secret);
    }

    /**
     * Assert that the checksum matches the expected checksum.
     *
     * @throws BrokerException
     */
    protected function validateChecksum(string $checksum, string $command, string $brokerId, string $token): void
    {
        $expected = $this->generateChecksum($command, $brokerId, $token);

        if ($checksum !== $expected) {
            $this->logger->warning(
                "Invalid $command checksum",
                ['expected' => $expected, 'received' => $checksum, 'broker' => $brokerId, 'token' => $token]
            );
            throw new BrokerException("Invalid checksum", 400);
        }
    }

    /**
     * Assert that the URL has a domain that is allowed for the broker.
     *
     * @throws BrokerException
     */
    public function validateDomain(string $type, string $url, string $brokerId, ?string $token = null): void
    {
        $domains = ($this->getBrokerInfo)($brokerId)['domains'] ?? null;
        $host = parse_url($url, PHP_URL_HOST);

        if (!in_array($host, $domains, true)) {
            $this->logger->warning(
                "Domain of $type is not allowed for broker",
                [$type => $url, 'domain' => $host, 'broker' => $brokerId, 'token' => $token]
            );
            throw new BrokerException("Domain of $type is not allowed", 400);
        }
    }

    /**
     * Attach a client session to a broker session.
     *
     * @throws BrokerException
     * @throws ServerException
     */
    public function attach(?ServerRequestInterface $request = null): void
    {
        ['broker' => $brokerId, 'token' => $token] = $this->processAttachRequest($request);

        if (!$this->session->isActive()) {
            $this->session->start();
        }

        $key = $this->getCacheKey($brokerId, $token);
        $cached = $this->cache->set($key, $this->session->getId());

        $info = ['broker' => $brokerId, 'token' => $token, 'session' => $this->session->getId()];

        if (!$cached) {
            $this->logger->error("Failed to attach attach bearer token to session id due to cache issue", $info);
            throw new ServerException("Failed to attach bearer token to session id");
        }

        $this->logger->info("Attached broker token to session", $info);
    }

    /**
     * Validate attach request and return broker id and token.
     *
     * @param ServerRequestInterface|null $request
     * @return array{broker:string,token:string}
     * @throws BrokerException
     */
    protected function processAttachRequest(?ServerRequestInterface $request): array
    {
        $brokerId = $this->getQueryParam($request, 'broker', true);
        $token = $this->getQueryParam($request, 'token', true);

        $checksum = $this->getQueryParam($request, 'checksum', true);
        $this->validateChecksum($checksum, 'attach', $brokerId, $token);

        $origin = $this->getHeader($request, 'Origin');
        if ($origin !== '') {
            $this->validateDomain('origin', $origin, $brokerId, $token);
        }

        $referer = $this->getHeader($request, 'Referer');
        if ($referer !== '') {
            $this->validateDomain('referer', $referer, $brokerId, $token);
        }

        $returnUrl = $this->getQueryParam($request, 'return_url', false);
        if ($returnUrl !== null) {
            $this->validateDomain('return_url', $returnUrl, $brokerId);
        }

        return ['broker' => $brokerId, 'token' => $token];
    }

    /**
     * Get query parameter from PSR-7 request or $_GET.
     *
     * @param ServerRequestInterface $request
     * @param string                 $key
     * @param bool                   $required
     * @return mixed
     */
    protected function getQueryParam(?ServerRequestInterface $request, string $key, bool $required = false)
    {
        $params = $request === null ? $_GET : $request->getQueryParams();

        if ($required && !isset($params[$key])) {
            throw new BrokerException("Missing '$key' query parameter", 400);
        }

        return $params[$key] ?? null;
    }

    /**
     * Get HTTP Header from PSR-7 request or $_SERVER.
     *
     * @param ServerRequestInterface $request
     * @param string                 $key
     * @return string
     */
    protected function getHeader(?ServerRequestInterface $request, string $key): string
    {
        return $request === null
            ? ($_SERVER['HTTP_' . str_replace('-', '_', strtoupper($key))] ?? '')
            : $request->getHeaderLine($key);
    }
}