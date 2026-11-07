<?php

declare(strict_types=1);

namespace Synsmarts\AwsCredentialsIsolation\Driver;

use Aws\CacheInterface;
use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialsInterface;

/**
 * Minimal credential cache backed by the pod-local /tmp emptyDir.
 *
 * Files are private to the non-root application UID. JSON reconstruction
 * avoids unserializing executable PHP objects. Credentials inside the SDK's
 * 60-second refresh window are treated as expired.
 */
class PodLocalCredentialsCache implements CacheInterface
{
    private const REFRESH_WINDOW_SECONDS = 60;

    /** @var string */
    private $directory;

    public function __construct(
        string $directory = '/tmp/synsmarts-aws-cache/credentials'
    )
    {
        $this->directory = $directory;
    }

    public function get($key)
    {
        $path = $this->path((string) $key);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return null;
            }
            $payload = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        $data = is_string($payload) ? json_decode($payload, true) : null;
        if (!is_array($data)
            || !isset($data['key'], $data['secret'])
            || !is_int($data['cache_expires_at'])
            || $data['cache_expires_at'] <= time() + self::REFRESH_WINDOW_SECONDS
        ) {
            return null;
        }

        return new Credentials(
            $data['key'],
            $data['secret'],
            $data['token'] ?? null,
            $data['expires'] ?? null,
            $data['account_id'] ?? null,
            $data['source'] ?? null
        );
    }

    public function set($key, $value, $ttl = 0)
    {
        if (!$value instanceof CredentialsInterface) {
            return;
        }

        $expiration = $value->getExpiration();
        $cacheExpiration = $ttl > 0 ? time() + (int) $ttl : $expiration;
        if ($cacheExpiration === null) {
            return;
        }

        try {
            $payload = json_encode(
                [
                    'key' => $value->getAccessKeyId(),
                    'secret' => $value->getSecretKey(),
                    'token' => $value->getSecurityToken(),
                    'expires' => $expiration,
                    'cache_expires_at' => $cacheExpiration,
                    'account_id' => method_exists($value, 'getAccountId')
                        ? $value->getAccountId()
                        : null,
                    'source' => method_exists($value, 'getSource')
                        ? $value->getSource()
                        : null,
                ],
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            return;
        }

        if (!$this->ensureDirectory()) {
            return;
        }
        $path = $this->path((string) $key);
        try {
            $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        } catch (\Exception $exception) {
            return;
        }

        // Callers run under Magento's error handler, which throws on any
        // reported error, so every filesystem call is silenced and checked.
        // The payload goes to a private temporary file renamed over the
        // cache file, so a failed write leaves the cached copy intact.
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            return;
        }
        $replaced = false;
        try {
            $written = @chmod($temporary, 0600)
                && @fwrite($handle, $payload) === strlen($payload)
                && @fflush($handle);
            @fclose($handle);
            $handle = null;
            $replaced = $written && @rename($temporary, $path);
        } finally {
            if ($handle !== null) {
                @fclose($handle);
            }
            if (!$replaced) {
                @unlink($temporary);
            }
        }
    }

    public function remove($key)
    {
        $path = $this->path((string) $key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function ensureDirectory(): bool
    {
        if (!is_dir($this->directory)
            && !@mkdir($this->directory, 0700, true)
            && !is_dir($this->directory)
        ) {
            return false;
        }

        return @chmod($this->directory, 0700);
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }
}
