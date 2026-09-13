<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Maidemde\Typovigil\Domain\Severity;

/**
 * Decides how urgent a package update is.
 *
 * The only non-trivial logic in the extension, hence the self-check in
 * Tests/SeverityResolverTest.php.
 */
final class SeverityResolver
{
    /**
     * @param string $installed Version currently in use, e.g. "14.3.1"
     * @param string $latest Newest version available, e.g. "14.3.7"
     * @param list<array{affectedVersions?: string}> $advisories Packagist advisories for this package
     */
    public function resolve(string $installed, string $latest, array $advisories = []): Severity
    {
        $installed = $this->normalize($installed);

        foreach ($advisories as $advisory) {
            $constraint = $advisory['affectedVersions'] ?? '';
            if ($constraint !== '' && $this->matchesConstraint($installed, $constraint)) {
                return Severity::Critical;
            }
        }

        $latest = $this->normalize($latest);
        if ($latest !== '' && $installed !== '' && version_compare($installed, $latest, '<')) {
            return Severity::Outdated;
        }

        return Severity::Ok;
    }

    /**
     * Core is special: get.typo3.org marks releases as type "security", so any
     * newer security release means the installed one carries a known hole.
     *
     * @param list<array{version: string, type?: string}> $releases All releases of the installed major
     */
    public function resolveCore(string $installed, array $releases): Severity
    {
        $installed = $this->normalize($installed);
        if ($installed === '') {
            return Severity::Ok;
        }

        $newest = $installed;
        foreach ($releases as $release) {
            $version = $this->normalize($release['version'] ?? '');
            if ($version === '' || version_compare($version, $installed, '<=')) {
                continue;
            }
            if (($release['type'] ?? '') === 'security') {
                return Severity::Critical;
            }
            if (version_compare($version, $newest, '>')) {
                $newest = $version;
            }
        }

        return $newest !== $installed ? Severity::Outdated : Severity::Ok;
    }

    /**
     * Strips what Packagist and ext_emconf add around the plain number:
     * "v14.3.7" -> "14.3.7", "1.2.3-beta1" -> "1.2.3".
     */
    public function normalize(string $version): string
    {
        $version = ltrim(trim($version), 'vV');
        // Cut off stability suffixes: version_compare treats "1.0.0-beta" as older
        // than "1.0.0", which would report a false "outdated" for stable installs.
        $version = preg_replace('/[-+].*$/', '', $version) ?? $version;

        return preg_match('/^\d+(\.\d+)*$/', $version) === 1 ? $version : '';
    }

    /**
     * Evaluates a Packagist advisory constraint such as ">=13.0.0,<13.4.2|>=14.0.0,<14.3.5".
     *
     * ponytail: handles the subset Packagist actually emits (>=, >, <=, <, ==, comma = AND,
     * pipe = OR). Swap in composer/semver if a constraint ever shows up that this misses.
     */
    private function matchesConstraint(string $version, string $constraint): bool
    {
        if ($version === '') {
            return false;
        }

        foreach (explode('|', $constraint) as $orGroup) {
            $orGroup = trim($orGroup);
            if ($orGroup === '') {
                continue;
            }

            $allMatch = true;
            foreach (explode(',', $orGroup) as $part) {
                if (!$this->matchesSinglePart($version, trim($part))) {
                    $allMatch = false;
                    break;
                }
            }

            if ($allMatch) {
                return true;
            }
        }

        return false;
    }

    private function matchesSinglePart(string $version, string $part): bool
    {
        if ($part === '' || preg_match('/^(>=|<=|!=|==|=|>|<)?\s*v?(.+)$/', $part, $m) !== 1) {
            return false;
        }

        $operator = $m[1] ?: '==';
        $bound = $this->normalize($m[2]);
        if ($bound === '') {
            return false;
        }

        return version_compare($version, $bound, $operator === '=' ? '==' : $operator);
    }
}
