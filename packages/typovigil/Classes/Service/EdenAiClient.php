<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Sends one prompt to Eden AI (a multi-provider LLM gateway with an
 * OpenAI-compatible chat completions API) and returns the assistant's text.
 *
 * Only the one call TypoVigil needs, not a general-purpose Eden AI wrapper.
 * URL, token and model come from the extension configuration, not the
 * database — same reasoning as the hosting platform client: credentials do
 * not belong on a project record, and every analysis uses the same account.
 *
 * ENV variables (TYPOVIGIL_EDENAI_API_URL/TOKEN/MODEL) take precedence over
 * the extension configuration, same pattern as TYPO3_SMTP_* in
 * production.php. Same reasoning too: config/system has no persistent
 * volume in the container deploy and is rebuilt from the image on every
 * deploy, so a value entered only in the backend module is lost on the next
 * deploy. ENV vars survive that; the extension configuration remains as a
 * fallback for ddev/local setups where these are typically unset.
 */
final readonly class EdenAiClient
{
    private const TIMEOUT = 60; // LLM responses take longer than a plain REST call

    public function __construct(
        private RequestFactory $requestFactory,
        private LoggerInterface $logger,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiUrl() !== '' && $this->apiToken() !== '' && $this->model() !== '';
    }

    /**
     * Sends one prompt, returns the assistant's text or null on any
     * failure — a failing AI call must never break the caller's flow, it
     * just means no report was generated this time.
     */
    public function complete(string $prompt): ?string
    {
        $data = $this->request([
            'model' => $this->model(),
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        $content = $data['choices'][0]['message']['content'] ?? null;

        return is_string($content) && $content !== '' ? $content : null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function request(array $body): ?array
    {
        $apiUrl = $this->apiUrl();
        if ($apiUrl === '' || $this->apiToken() === '') {
            $this->logger->warning('TypoVigil: Eden AI not configured, skipping request');

            return null;
        }

        try {
            $response = $this->requestFactory->request($apiUrl, 'POST', [
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken(),
                    'Accept' => 'application/json',
                ],
                'json' => $body,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('TypoVigil: unexpected status from Eden AI', ['status' => $status]);

                return null;
            }

            $raw = (string)$response->getBody();
            if ($raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            $this->logger->warning('TypoVigil: Eden AI request failed', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    private function apiUrl(): string
    {
        return $this->configValue('TYPOVIGIL_EDENAI_API_URL', 'edenAiApiUrl');
    }

    private function apiToken(): string
    {
        return $this->configValue('TYPOVIGIL_EDENAI_API_TOKEN', 'edenAiApiToken');
    }

    private function model(): string
    {
        return $this->configValue('TYPOVIGIL_EDENAI_MODEL', 'edenAiModel');
    }

    private function configValue(string $envVar, string $extConfKey): string
    {
        $env = getenv($envVar);
        if ($env !== false && $env !== '') {
            return $env;
        }

        try {
            return (string)$this->extensionConfiguration->get('typovigil', $extConfKey);
        } catch (\Throwable) {
            return '';
        }
    }
}
