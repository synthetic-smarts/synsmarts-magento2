<?php

declare(strict_types=1);

namespace Synsmarts\AwsCredentialsIsolation\Driver;

use Aws\Credentials\CredentialProvider;
use Magento\AwsS3\Driver\CachedCredentialsProvider;

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
 */
class PodLocalCredentialsProvider extends CachedCredentialsProvider
{
    /**
     * Process-scoped memoization. Magento's object manager treats this provider
     * as a shared service; the static also avoids rebuilding the SDK chain if a
     * caller explicitly asks the object manager for it more than once.
     *
     * @var callable|null
     */
    private static $provider;

    /**
     * Deliberately do not construct the parent's shared Magento cache adapter.
     */
    public function __construct()
    {
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
                CredentialProvider::defaultProvider(),
                new PodLocalCredentialsCache(),
                $cacheKey
            );
        }

        return self::$provider;
    }
}
