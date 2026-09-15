<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Plain Doctrine access. No Extbase persistence: the tables are written by an
 * API middleware that runs before any Extbase context exists.
 */
final readonly class ProjectRepository
{
    private const TABLE_PROJECT = 'tx_typovigil_project';
    private const TABLE_PACKAGE = 'tx_typovigil_package';
    private const TABLE_MM = 'tx_typovigil_project_feuser_mm';
    private const TABLE_FE_USERS = 'fe_users';

    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $qb = $this->queryBuilder(self::TABLE_PROJECT);

        return $qb->select('*')
            ->from(self::TABLE_PROJECT)
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid): ?array
    {
        $qb = $this->queryBuilder(self::TABLE_PROJECT);

        $row = $qb->select('*')
            ->from(self::TABLE_PROJECT)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Projects a given frontend user is allowed to see.
     *
     * The access filter lives here so no caller can forget it — a missing filter
     * would let any logged-in customer read every project.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findForFrontendUser(int $feUserId, bool $seesAllProjects = false): array
    {
        if ($feUserId <= 0) {
            return [];
        }

        // The agency role still requires a logged-in user — the check above is
        // what keeps this from turning into public access.
        if ($seesAllProjects) {
            return $this->findAll();
        }

        $qb = $this->queryBuilder(self::TABLE_PROJECT);

        return $qb->select('p.*')
            ->from(self::TABLE_PROJECT, 'p')
            ->innerJoin(
                'p',
                self::TABLE_MM,
                'mm',
                $qb->expr()->eq('mm.uid_local', $qb->quoteIdentifier('p.uid'))
            )
            ->where($qb->expr()->eq('mm.uid_foreign', $qb->createNamedParameter($feUserId, ParameterType::INTEGER)))
            ->orderBy('p.title')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function isVisibleToFrontendUser(int $projectUid, int $feUserId, bool $seesAllProjects = false): bool
    {
        if ($projectUid <= 0 || $feUserId <= 0) {
            return false;
        }

        // Same rule as findForFrontendUser: the role widens which projects are
        // visible, it never lets an anonymous request through.
        if ($seesAllProjects) {
            return $this->findByUid($projectUid) !== null;
        }

        $qb = $this->queryBuilder(self::TABLE_PROJECT);

        $count = $qb->count('p.uid')
            ->from(self::TABLE_PROJECT, 'p')
            ->innerJoin(
                'p',
                self::TABLE_MM,
                'mm',
                $qb->expr()->eq('mm.uid_local', $qb->quoteIdentifier('p.uid'))
            )
            ->where(
                $qb->expr()->eq('p.uid', $qb->createNamedParameter($projectUid, ParameterType::INTEGER)),
                $qb->expr()->eq('mm.uid_foreign', $qb->createNamedParameter($feUserId, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$count > 0;
    }

    /**
     * Looks up a project by its bearer token.
     *
     * Every candidate is compared with hash_equals so the runtime does not leak
     * which prefix was right.
     *
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $hash = hash('sha256', $token);
        $qb = $this->queryBuilder(self::TABLE_PROJECT);

        $rows = $qb->select('*')
            ->from(self::TABLE_PROJECT)
            ->where($qb->expr()->neq('token_hash', $qb->createNamedParameter('')))
            ->executeQuery()
            ->fetchAllAssociative();

        $match = null;
        foreach ($rows as $row) {
            if (hash_equals((string)$row['token_hash'], $hash)) {
                $match = $row;
            }
        }

        return $match;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findPackagesByProject(int $projectUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE_PACKAGE);

        return $qb->select('*')
            ->from(self::TABLE_PACKAGE)
            ->where($qb->expr()->eq('project', $qb->createNamedParameter($projectUid, ParameterType::INTEGER)))
            ->orderBy('is_core', 'DESC')
            ->addOrderBy('composer_name')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllPackages(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE_PACKAGE);

        return $qb->select('*')
            ->from(self::TABLE_PACKAGE)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Replaces a project's package list wholesale, so uninstalled extensions
     * disappear instead of lingering as phantom entries.
     *
     * @param list<array<string, mixed>> $packages
     */
    public function replacePackages(int $projectUid, array $packages): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_PACKAGE);
        $connection->delete(self::TABLE_PACKAGE, ['project' => $projectUid], [Connection::PARAM_INT]);

        foreach ($packages as $package) {
            $connection->insert(self::TABLE_PACKAGE, array_merge($package, ['project' => $projectUid]));
        }
    }

    /**
     * All customer logins, each with the titles of the projects assigned to
     * them — for the module overview, so nobody has to open every fe_users
     * record just to see who can see what.
     *
     * @return list<array{uid: int, username: string, name: string, projectTitles: list<string>}>
     */
    public function findAllCustomersWithProjects(): array
    {
        $qb = $this->queryBuilder(self::TABLE_FE_USERS);

        $users = $qb->select('uid', 'username', 'name')
            ->from(self::TABLE_FE_USERS)
            ->orderBy('username')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($users === []) {
            return [];
        }

        $mmQb = $this->queryBuilder(self::TABLE_MM);
        $assignments = $mmQb->select('mm.uid_foreign', 'p.title')
            ->from(self::TABLE_MM, 'mm')
            ->innerJoin('mm', self::TABLE_PROJECT, 'p', $mmQb->expr()->eq('p.uid', $mmQb->quoteIdentifier('mm.uid_local')))
            ->executeQuery()
            ->fetchAllAssociative();

        $projectTitlesByUser = [];
        foreach ($assignments as $assignment) {
            $projectTitlesByUser[(int)$assignment['uid_foreign']][] = (string)$assignment['title'];
        }

        return array_map(
            static fn(array $user): array => [
                'uid' => (int)$user['uid'],
                'username' => (string)$user['username'],
                'name' => (string)$user['name'],
                'projectTitles' => $projectTitlesByUser[(int)$user['uid']] ?? [],
            ],
            $users
        );
    }

    public function updateProject(int $uid, array $values): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::TABLE_PROJECT)
            // No $types: that argument types $values positionally, not the
            // identifier — PARAM_INT there bound the first value as an integer
            // and silently wrote 0 over strings like token_hash.
            ->update(self::TABLE_PROJECT, $values, ['uid' => $uid]);
    }

    /**
     * Addressed by (project, composer_name) rather than a surrogate key: the
     * table has no TCA, so TYPO3's schema migration does not give it a uid, and
     * that pair is the real identity of a row anyway.
     */
    public function updatePackage(int $project, string $composerName, string $extensionKey, array $values): void
    {
        $identifier = ['project' => $project];
        if ($composerName !== '') {
            $identifier['composer_name'] = $composerName;
        } else {
            $identifier['extension_key'] = $extensionKey;
        }

        $this->connectionPool
            ->getConnectionForTable(self::TABLE_PACKAGE)
            ->update(self::TABLE_PACKAGE, $values, $identifier);
    }

    private function queryBuilder(string $table): \TYPO3\CMS\Core\Database\Query\QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $qb;
    }
}
