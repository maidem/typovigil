<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;
use Maidemde\Typovigil\Domain\Severity;

/**
 * Turns raw rows into what both the backend module and the frontend plugin show.
 *
 * Shared on purpose: two independent read paths over the same data drift apart,
 * and then the two views disagree about whether a site is safe.
 */
final readonly class StatusReportService
{
    /**
     * A project that has not reported for this long is treated as unreachable.
     * A silent agent must not look like a healthy site.
     */
    private const STALE_AFTER_SECONDS = 172800; // 48 hours

    public function __construct(private ProjectRepository $projects) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function summariesForBackend(): array
    {
        return array_map(
            fn(array $project): array => $this->summarize($project),
            $this->projects->findAll()
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summariesForFrontendUser(int $feUserId, bool $seesAllProjects = false): array
    {
        return array_map(
            fn(array $project): array => $this->summarize($project),
            $this->projects->findForFrontendUser($feUserId, $seesAllProjects)
        );
    }

    /**
     * Full package list — backend only. The frontend deliberately gets counts
     * instead: a complete inventory of vulnerable versions is an attack plan if
     * a customer account is ever compromised.
     *
     * @return array<string, mixed>|null
     */
    public function detailForBackend(int $projectUid): ?array
    {
        $project = $this->projects->findByUid($projectUid);
        if ($project === null) {
            return null;
        }

        $summary = $this->summarize($project);
        $summary['packages'] = $this->sortBySeverity($this->projects->findPackagesByProject($projectUid));

        return $summary;
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    private function summarize(array $project): array
    {
        $uid = (int)$project['uid'];
        $packages = $this->projects->findPackagesByProject($uid);

        $counts = [
            Severity::Critical->value => 0,
            Severity::Outdated->value => 0,
            Severity::Ok->value => 0,
        ];

        foreach ($packages as $package) {
            $severity = (string)($package['severity'] ?? Severity::Ok->value);
            if (isset($counts[$severity])) {
                $counts[$severity]++;
            }
        }

        $lastReport = (int)($project['last_report_at'] ?? 0);
        $isStale = $lastReport === 0 || (time() - $lastReport) > self::STALE_AFTER_SECONDS;

        return [
            'uid' => $uid,
            'title' => (string)($project['title'] ?? ''),
            'coreVersion' => (string)($project['core_version'] ?? ''),
            'lastReportAt' => $lastReport,
            'isStale' => $isStale,
            'hasReported' => $lastReport > 0,
            'counts' => $counts,
            'packageCount' => count($packages),
            'updatesAvailable' => $counts[Severity::Outdated->value] + $counts[Severity::Critical->value],
            'worstSeverity' => $this->worstSeverity($counts),
        ];
    }

    /**
     * @param array<string, int> $counts
     */
    private function worstSeverity(array $counts): string
    {
        if (($counts[Severity::Critical->value] ?? 0) > 0) {
            return Severity::Critical->value;
        }
        if (($counts[Severity::Outdated->value] ?? 0) > 0) {
            return Severity::Outdated->value;
        }

        return Severity::Ok->value;
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @return list<array<string, mixed>>
     */
    private function sortBySeverity(array $packages): array
    {
        usort($packages, static function (array $a, array $b): int {
            $rankA = Severity::tryFrom((string)($a['severity'] ?? ''))?->rank() ?? 0;
            $rankB = Severity::tryFrom((string)($b['severity'] ?? ''))?->rank() ?? 0;

            if ($rankA !== $rankB) {
                return $rankB <=> $rankA;
            }

            // Core first within the same severity: it matters more than any extension.
            $coreA = (int)($a['is_core'] ?? 0);
            $coreB = (int)($b['is_core'] ?? 0);
            if ($coreA !== $coreB) {
                return $coreB <=> $coreA;
            }

            return strcmp((string)($a['composer_name'] ?? ''), (string)($b['composer_name'] ?? ''));
        });

        return $packages;
    }
}
