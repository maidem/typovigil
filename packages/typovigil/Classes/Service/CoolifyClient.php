<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Talks to the hosting platform's REST API.
 *
 * Only the one action TypoVigil needs: triggering a volume backup. The
 * database is dumped by TypoVigil itself (DatabaseBackupService), because
 * this API can only schedule database backups, never run one now — so the
 * schedule-creating and freshness-checking methods that used to live here
 * are gone with the schedules they served.
 *
 * Base URL and token come from TYPOVIGIL_COOLIFY_API_URL/_TOKEN, falling
 * back to the extension configuration for local setups — not the database:
 * credentials do not belong on a project record, and every monitored project
 * talks to the same hosting platform instance.
 */
final readonly class CoolifyClient
{
    private const TIMEOUT = 15;

    public function __construct(
        private RequestFactory $requestFactory,
        private LoggerInterface $logger,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && $this->apiToken() !== '';
    }

    /**
     * Queues an immediate backup of an application's storage (volume).
     *
     * There is no separate status endpoint for this on the hosting platform's
     * API — a 200 response means the backup was queued, not that it has finished.
     * Completion shows up in the hosting platform's own UI only.
     */
    public function triggerStorageBackup(string $applicationUuid, string $storageUuid): bool
    {
        $response = $this->request(
            'POST',
            sprintf('/applications/%s/storages/%s/backups/run', $applicationUuid, $storageUuid)
        );

        return $response !== null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null null on any failure — a failing
     *     platform call must never break the caller's flow, it just reports
     *     "not done".
     */
    private function request(string $method, string $path, array $body = []): ?array
    {
        $baseUrl = $this->baseUrl();
        if ($baseUrl === '' || $this->apiToken() === '') {
            $this->logger->warning('TypoVigil: hosting platform API not configured, skipping request', ['path' => $path]);

            return null;
        }

        $url = rtrim($baseUrl, '/') . $path;

        try {
            $options = [
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken(),
                    'Accept' => 'application/json',
                ],
            ];
            if ($body !== []) {
                $options['json'] = $body;
            }

            $response = $this->requestFactory->request($url, $method, $options);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('TypoVigil: unexpected status from hosting platform', [
                    'url' => $url,
                    'status' => $status,
                ]);

                return null;
            }

            $raw = (string)$response->getBody();
            if ($raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            $this->logger->warning('TypoVigil: hosting platform request failed', [
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function baseUrl(): string
    {
        return $this->configValue('TYPOVIGIL_COOLIFY_API_URL', 'coolifyApiUrl');
    }

    private function apiToken(): string
    {
        return $this->configValue('TYPOVIGIL_COOLIFY_API_TOKEN', 'coolifyApiToken');
    }

    /**
     * ENV first, extension configuration as the fallback — same as
     * EdenAiClient, and for the same reason: config/system has no persistent
     * volume in the container deploy, so credentials entered in the backend
     * module are silently gone on the next deploy. This one had already
     * fallen into that trap; every backup was failing with "not configured".
     */
    private function configValue(string $envVar, string $extConfKey): string
    {
        $env = getenv($envVar);
        if ($env !== false && $env !== '') {
            return trim($env);
        }

        try {
            return (string)$this->extensionConfiguration->get('typovigil', $extConfKey);
        } catch (\Throwable) {
            return '';
        }
    }
}
