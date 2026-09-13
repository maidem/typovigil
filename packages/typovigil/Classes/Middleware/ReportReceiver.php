<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Middleware;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;

/**
 * Receives agent reports at POST /typovigil/report.
 *
 * Runs as a middleware rather than a plugin so a freshly installed hub can
 * accept reports before anyone has created a page tree.
 */
final readonly class ReportReceiver implements MiddlewareInterface
{
    private const PATH = '/typovigil/report';
    private const MAX_BODY_BYTES = 1048576; // 1 MB — a report is a few hundred packages at most
    private const MAX_PACKAGES = 2000;

    public function __construct(private ProjectRepository $projects) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (rtrim($request->getUri()->getPath(), '/') !== self::PATH) {
            return $handler->handle($request);
        }

        if ($request->getMethod() !== 'POST') {
            return new Response(null, 405, ['Allow' => 'POST']);
        }

        $project = $this->authenticate($request);
        if ($project === null) {
            // Deliberately vague: a precise reason would help someone probe for valid tokens.
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $body = (string)$request->getBody();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new JsonResponse(['error' => 'payload too large'], 413);
        }

        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'invalid json'], 400);
        }

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid payload'], 400);
        }

        $packages = $this->extractPackages($payload);
        if ($packages === null) {
            return new JsonResponse(['error' => 'invalid packages'], 400);
        }

        $this->projects->replacePackages((int)$project['uid'], $packages);
        $this->projects->updateProject((int)$project['uid'], [
            'last_report_at' => time(),
            'core_version' => $this->sanitizeVersion((string)($payload['coreVersion'] ?? '')),
        ]);

        return new Response(null, 204);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function authenticate(ServerRequestInterface $request): ?array
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return null;
        }

        return $this->projects->findByToken(trim($matches[1]));
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>|null null when the payload is malformed
     */
    private function extractPackages(array $payload): ?array
    {
        $raw = $payload['packages'] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        if (count($raw) > self::MAX_PACKAGES) {
            return null;
        }

        $now = time();
        $packages = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $composerName = $this->sanitizeName((string)($entry['composerName'] ?? ''));
            $extensionKey = $this->sanitizeKey((string)($entry['extensionKey'] ?? ''));
            if ($composerName === '' && $extensionKey === '') {
                continue;
            }

            $packages[] = [
                'composer_name' => $composerName,
                'extension_key' => $extensionKey,
                'installed_version' => $this->sanitizeVersion((string)($entry['version'] ?? '')),
                'latest_version' => '',
                'severity' => 'ok',
                'advisory_json' => '',
                'checked_at' => 0,
                'is_core' => !empty($entry['isCore']) ? 1 : 0,
                'tstamp' => $now,
            ];
        }

        return $packages;
    }

    private function sanitizeName(string $value): string
    {
        $value = trim($value);

        return preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#i', $value) === 1
            ? strtolower($value)
            : '';
    }

    private function sanitizeKey(string $value): string
    {
        $value = trim($value);

        return preg_match('/^[a-z0-9_]{1,64}$/i', $value) === 1 ? strtolower($value) : '';
    }

    private function sanitizeVersion(string $value): string
    {
        $value = trim($value);

        return preg_match('/^[0-9a-zA-Z.\-+_]{1,64}$/', $value) === 1 ? $value : '';
    }
}
