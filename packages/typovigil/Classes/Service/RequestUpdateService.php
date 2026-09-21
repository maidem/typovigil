<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Service;

use Maidemde\Typovigil\Domain\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;

/**
 * Requests an update for a project by starting the update workflow in its
 * repository.
 *
 * Deliberately does not touch the live installation. An update applied there
 * directly would drift from the repository — and for a container deploy it
 * would be overwritten by the next one anyway. The pull request keeps git the
 * single truth, which is also what makes a local ddev copy catch up with a
 * plain `git pull`.
 *
 * Three conditions, checked in the order that gives the most useful answer
 * first: a project without a repository can never use this, a pending report
 * is something the operator can act on right now, and the backup link is the
 * one that needs a look at another system.
 */
final readonly class RequestUpdateService
{
    public function __construct(
        private ProjectRepository $projects,
        private GitHubClient $gitHub,
        private LoggerInterface $logger,
    ) {}

    /**
     * Whether the button should be offered at all. A project with no
     * repository cannot receive a pull request, so the button is not
     * "blocked" for it — it does not apply.
     *
     * @param array<string, mixed> $project
     */
    public static function appliesTo(array $project): bool
    {
        return trim((string)($project['github_repo'] ?? '')) !== '';
    }

    /**
     * Why the update cannot be requested right now, or null when it can.
     *
     * Returns a reason rather than a bare false so the button can say what is
     * missing instead of being mysteriously dead — as a label key plus its
     * argument, because both callers render in the user's language and the
     * service has no business picking one.
     *
     * @param array<string, mixed> $project
     * @param list<array<string, mixed>> $packages
     * @return array{key: string, argument: int}|null
     */
    public static function blockedBecause(array $project, array $packages): ?array
    {
        $pending = self::pendingReportCount($packages);
        if ($pending > 0) {
            return ['key' => 'blocked.pendingReports', 'argument' => $pending];
        }

        // Backup capability, not a backup run: this only opens a pull
        // request, and the installation is not touched until someone merges
        // and deploys. Backing up now would mean a backup that is already
        // days old by the time it matters.
        //
        // ponytail: capability check only — the backup itself belongs in the
        // deploy, via a hub endpoint the deploy workflow calls before it
        // triggers the platform. Until that exists, a project can be updated
        // with a backup that is merely possible, not taken.
        if (!self::canBeBackedUp($project)) {
            return ['key' => 'blocked.noBackup', 'argument' => 0];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $project
     */
    private static function canBeBackedUp(array $project): bool
    {
        return trim((string)($project['coolify_application_uuid'] ?? '')) !== ''
            && trim((string)($project['coolify_database_uuid'] ?? '')) !== '';
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    public static function pendingReportCount(array $packages): int
    {
        $pending = 0;
        foreach ($packages as $package) {
            if ((string)($package['ai_report_status'] ?? '') === 'pending') {
                $pending++;
            }
        }

        return $pending;
    }

    /**
     * The packages with a known newer version.
     *
     * Used to decide whether an update is worth requesting at all, and to
     * describe it — NOT as the argument list for `composer update`. A trial
     * run against a real TYPO3 project showed why: the TYPO3 packages pin
     * each other to the exact same version (typo3/cms-install requires
     * typo3/cms-core 14.3.5, not ^14.3), so naming a subset makes composer
     * refuse the whole resolution, even with --with-all-dependencies. The
     * workflow therefore runs a plain `composer update` and lets the
     * constraints in composer.json decide how far anything may move.
     *
     * @param list<array<string, mixed>> $packages
     * @return list<string>
     */
    public static function updatablePackages(array $packages): array
    {
        $names = [];
        foreach ($packages as $package) {
            $severity = (string)($package['severity'] ?? 'ok');
            $composerName = (string)($package['composer_name'] ?? '');
            if ($severity !== 'ok' && $composerName !== '') {
                $names[] = $composerName;
            }
        }

        return $names;
    }

    /**
     * Requests the update.
     *
     * The outcome travels as a label key and its arguments rather than a
     * finished sentence, same reasoning as blockedBecause(): the backend
     * module and the customer portal both render it, each in the language of
     * whoever is looking.
     *
     * @return array{requested: bool, key: string, arguments: list<string|int>}
     */
    public function run(int $projectUid): array
    {
        $project = $this->projects->findByUid($projectUid);
        if ($project === null) {
            return ['requested' => false, 'key' => 'result.projectNotFound', 'arguments' => []];
        }

        if (!self::appliesTo($project)) {
            return ['requested' => false, 'key' => 'result.noRepository', 'arguments' => []];
        }

        $packages = $this->projects->findPackagesByProject($projectUid);

        $blocked = self::blockedBecause($project, $packages);
        if ($blocked !== null) {
            return [
                'requested' => false,
                'key' => $blocked['key'],
                'arguments' => [$blocked['argument']],
            ];
        }

        $updatable = self::updatablePackages($packages);
        if ($updatable === []) {
            return ['requested' => false, 'key' => 'result.alreadyCurrent', 'arguments' => []];
        }

        $repo = trim((string)$project['github_repo']);
        if (!$this->gitHub->dispatchUpdateWorkflow($repo, $updatable)) {
            // The client has already logged the reason; repeating it here
            // would mean showing an API error to someone who cannot act on it.
            return ['requested' => false, 'key' => 'result.dispatchFailed', 'arguments' => []];
        }

        $this->projects->updateProject($projectUid, ['update_requested_at' => time()]);

        $this->logger->info('TypoVigil: update requested', [
            'project' => $projectUid,
            'repo' => $repo,
            'packages' => count($updatable),
        ]);

        return [
            'requested' => true,
            'key' => 'result.requested',
            'arguments' => [count($updatable), $repo],
        ];
    }
}
