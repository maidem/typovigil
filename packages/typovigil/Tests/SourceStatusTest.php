<?php

declare(strict_types=1);

/**
 * Checks that VersionCheckService turns recorded reachability into the three
 * states the footer renders: answered, failed, never asked.
 *
 * Standalone like the other checks here — run with `php SourceStatusTest.php`.
 * The cache and the HTTP client are hand-rolled stubs; pulling in a framework
 * for four assertions would cost more than the code under test.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use Maidemde\Typovigil\Service\VersionCheckService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("%-60s %s\n", $label, $ok ? 'OK' : 'FAILED');
}

/** In-memory stand-in for the TYPO3 cache frontend. */
$cache = new class implements FrontendInterface {
    /** @var array<string, mixed> */
    public array $data = [];

    public function getIdentifier(): string { return 'typovigil'; }
    public function getBackend(): never { throw new \BadMethodCallException('unused'); }
    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null): void
    {
        $this->data[$entryIdentifier] = $data;
    }
    public function get($entryIdentifier): mixed { return $this->data[$entryIdentifier] ?? false; }
    public function has($entryIdentifier): bool { return isset($this->data[$entryIdentifier]); }
    public function remove($entryIdentifier): bool { unset($this->data[$entryIdentifier]); return true; }
    public function flush(): void { $this->data = []; }
    public function flushByTags(array $tags): void {}
    public function flushByTag($tag): void {}
    public function collectGarbage(): void {}
    public function isValidEntryIdentifier($identifier): bool { return true; }
    public function isValidTag($tag): bool { return true; }
    public function require(string $entryIdentifier): mixed { return false; }
};

// RequestFactory is readonly, so the subclass must be readonly too and cannot
// carry the mutable state these checks flip between assertions. The state lives
// in this holder instead; the factory only reads it.
$upstream = new class {
    public int $status = 200;
    public string $body = '{}';
};

/** Request factory that answers with whatever $upstream currently says. */
$requests = new readonly class($upstream) extends RequestFactory {
    public function __construct(private object $upstream) {}

    public function request(string $uri, string $method = 'GET', array $options = [], ?string $context = null): ResponseInterface
    {
        return new class($this->upstream->status, $this->upstream->body) implements ResponseInterface {
            public function __construct(private int $status, private string $body) {}
            public function getStatusCode(): int { return $this->status; }
            public function getBody(): StreamInterface
            {
                $body = $this->body;

                return new class($body) implements StreamInterface {
                    public function __construct(private string $body) {}
                    public function __toString(): string { return $this->body; }
                    public function close(): void {}
                    public function detach() { return null; }
                    public function getSize(): ?int { return strlen($this->body); }
                    public function tell(): int { return 0; }
                    public function eof(): bool { return true; }
                    public function isSeekable(): bool { return false; }
                    public function seek($offset, $whence = SEEK_SET): void {}
                    public function rewind(): void {}
                    public function isWritable(): bool { return false; }
                    public function write($string): int { return 0; }
                    public function isReadable(): bool { return true; }
                    public function read($length): string { return $this->body; }
                    public function getContents(): string { return $this->body; }
                    public function getMetadata($key = null) { return null; }
                };
            }
            public function withStatus($code, $reasonPhrase = ''): static { return $this; }
            public function getReasonPhrase(): string { return ''; }
            public function getProtocolVersion(): string { return '1.1'; }
            public function withProtocolVersion($version): static { return $this; }
            public function getHeaders(): array { return []; }
            public function hasHeader($name): bool { return false; }
            public function getHeader($name): array { return []; }
            public function getHeaderLine($name): string { return ''; }
            public function withHeader($name, $value): static { return $this; }
            public function withAddedHeader($name, $value): static { return $this; }
            public function withoutHeader($name): static { return $this; }
            public function withBody(StreamInterface $body): static { return $this; }
        };
    }
};

/** @return array<string, string> label => state */
function statesByLabel(VersionCheckService $service): array
{
    $states = [];
    foreach ($service->sourceStatus() as $source) {
        $states[$source['label']] = $source['state'];
    }

    return $states;
}

$service = new VersionCheckService($cache, $requests, new NullLogger());

// Nothing queried yet: every source is unknown, not broken. A fresh install
// must not look like four dead upstreams.
$states = statesByLabel($service);
check('four sources are reported', count($states) === 4);
check('unqueried source is "unknown"', ($states['Packagist'] ?? '') === 'unknown');
check(
    'no source is "down" before the first run',
    !in_array('down', $states, true)
);

// A successful answer marks that source, and only that source, reachable.
$upstream->status = 200;
$upstream->body = '{"packages":{"foo/bar":[]}}';
$service->latestVersion('foo/bar');
$states = statesByLabel($service);
check('answering source is "ok"', ($states['Packagist'] ?? '') === 'ok');
check('untouched source stays "unknown"', ($states['get.typo3.org'] ?? '') === 'unknown');

// A failing upstream flips it to down without disturbing the others.
$upstream->status = 503;
$service->coreReleases('13.4.2');
$states = statesByLabel($service);
check('failing source is "down"', ($states['get.typo3.org'] ?? '') === 'down');
check('previously ok source stays "ok"', ($states['Packagist'] ?? '') === 'ok');

// Recovery: a source that answers again must stop being shown as broken.
$upstream->status = 200;
$upstream->body = '{"13":{"releases":[]}}';
$service->coreReleases('13.4.2');
$states = statesByLabel($service);
check('recovered source is "ok" again', ($states['get.typo3.org'] ?? '') === 'ok');

// The advisories endpoint is a different host than the package endpoint and
// must be attributed separately, or one Packagist outage would mislabel both.
check(
    'advisories are a separate source',
    array_key_exists('Sicherheitsmeldungen', $states)
        && $states['Sicherheitsmeldungen'] === 'unknown'
);

// The templates put checkedAtLabel straight into a title attribute, so it has
// to be a ready-made string: filled for a queried source, empty for one that
// was never asked (no "zuletzt geprüft 01.01.1970").
$labels = [];
foreach ($service->sourceStatus() as $source) {
    $labels[$source['label']] = $source['checkedAtLabel'];
}
check(
    'queried source carries a formatted timestamp',
    (bool)preg_match('/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/', $labels['get.typo3.org'] ?? '')
);
check('unqueried source has an empty label', ($labels['Sicherheitsmeldungen'] ?? 'x') === '');

echo $failures === 0
    ? "\nSourceStatus: all checks passed\n"
    : "\nSourceStatus: {$failures} check(s) FAILED\n";

exit($failures === 0 ? 0 : 1);
