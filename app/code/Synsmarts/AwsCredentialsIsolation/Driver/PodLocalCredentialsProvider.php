<?php

declare(strict_types=1);

namespace Synsmarts\AwsCredentialsIsolation\Driver;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\CredentialsInterface;
use Aws\Sts\StsClient;
use GuzzleHttp\Promise\Create;
use Magento\AwsS3\Driver\CachedCredentialsProvider;
use Psr\Log\LoggerInterface;

/**
 * Keeps temporary AWS credentials inside the current pod.
 *
 * Magento's parent provider stores credentials under one fixed key in the
 * shared Magento cache. That is unsafe when workloads with different IRSA
 * identities share the cache backend (php-fpm and cron share the app role;
 * the managed shell runs mail-scoped): whichever workload writes the key
 * first determines every workload's AWS identity.
 *
 * A pod-local file cache shares one STS session across PHP-FPM workers and
 * requests without crossing the pod identity boundary. The cache refreshes
 * credentials before expiry and is unavailable to shell and cron pods.
 *
 * A cache miss fetches inside whichever request needs S3, so the STS call is
 * bounded: the SDK's own web-identity STS client has no connect or request
 * timeout, and one stalled connect held a storefront request for 15 s waiting
 * out TCP retransmits (synsmarts#3876).
 */
class PodLocalCredentialsProvider extends CachedCredentialsProvider
{
    private const STS_CONNECT_TIMEOUT_SECONDS = 1;
    private const STS_REQUEST_TIMEOUT_SECONDS = 2;

    /**
     * Process-scoped memoization. Magento's object manager treats this provider
     * as a shared service; the static also avoids rebuilding the SDK chain if a
     * caller explicitly asks the object manager for it more than once.
     *
     * @var callable|null
     */
    private static $provider;

    /** @var LoggerInterface */
    private $logger;

    /**
     * Deliberately do not construct the parent's shared Magento cache adapter.
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return callable
     */
    public function get()
    {
        if (self::$provider === null) {
            $identity = getenv('AWS_ROLE_ARN')
                ?: getenv('AWS_WEB_IDENTITY_TOKEN_FILE')
                ?: 'default-provider-chain';
            $cacheKey = 'aws_credentials_' . hash('sha256', $identity);

            self::$provider = CredentialProvider::cache(
                self::timed(
                    CredentialProvider::defaultProvider([
                        'stsClient' => new StsClient(self::stsClientArgs()),
                    ]),
                    $this->logger
                ),
                new PodLocalCredentialsCache(),
                $cacheKey
            );
        }

        return self::$provider;
    }

    public static function stsClientArgs(): array
    {
        return [
            'credentials' => false,
            'region' => getenv('AWS_REGION') ?: CredentialProvider::FALLBACK_REGION,
            'version' => 'latest',
            'http' => [
                'connect_timeout' => self::STS_CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::STS_REQUEST_TIMEOUT_SECONDS,
            ],
        ];
    }

    /**
     * The SDK memoizes the chain in-process and replays the same credentials
     * object until it expires, so only a new object is a fetch worth logging.
     */
    private static function timed(callable $provider, LoggerInterface $logger): callable
    {
        $lastLogged = null;

        return static function () use ($provider, $logger, &$lastLogged) {
            $started = hrtime(true);
            $elapsedMs = static function () use ($started): int {
                return (int) round((hrtime(true) - $started) / 1e6);
            };

            return $provider()->then(
                static function (CredentialsInterface $credentials) use ($logger, $elapsedMs, &$lastLogged) {
                    if ($credentials === $lastLogged) {
                        return $credentials;
                    }
                    $lastLogged = $credentials;
                    $logger->info('synsmarts aws credentials fetched', [
                        'duration_ms' => $elapsedMs(),
                        'source' => method_exists($credentials, 'getSource')
                            ? $credentials->getSource()
                            : null,
                    ]);
                    return $credentials;
                },
                static function ($reason) use ($logger, $elapsedMs) {
                    $logger->warning('synsmarts aws credentials fetch failed', [
                        'duration_ms' => $elapsedMs(),
                        'exception' => is_object($reason) ? get_class($reason) : gettype($reason),
                    ]);
                    return Create::rejectionFor($reason);
                }
            );
        };
    }
}
