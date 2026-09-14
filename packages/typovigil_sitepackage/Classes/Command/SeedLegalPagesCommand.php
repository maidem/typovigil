<?php

declare(strict_types=1);

namespace Maidemde\TypovigilSitepackage\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * One-off: creates the Impressum and Datenschutz pages with their text, run
 * manually once rather than via extension:setup — a page tree change is not
 * something every container start should redo or be able to undo.
 *
 * Idempotent by slug, so a second run does nothing instead of duplicating.
 */
final class SeedLegalPagesCommand extends Command
{
    private const ROOT_PID = 1;

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Creates the Impressum and Datenschutzerklärung pages if they do not exist yet');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->seedPage($output, 'Impressum', '/impressum', $this->imprintText());
        $this->seedPage($output, 'Datenschutzerklärung', '/datenschutz', $this->privacyText());

        return Command::SUCCESS;
    }

    private function seedPage(OutputInterface $output, string $title, string $slug, string $bodytext): void
    {
        $pages = $this->connectionPool->getConnectionForTable('pages');

        $existing = $pages->select(['uid'], 'pages', ['slug' => $slug])->fetchOne();
        if ($existing !== false) {
            $output->writeln(sprintf('"%s" already exists (uid %d) — skipped.', $title, $existing));

            return;
        }

        $now = time();
        $pages->insert('pages', [
            'pid' => self::ROOT_PID,
            'tstamp' => $now,
            'crdate' => $now,
            'sorting' => (int)$pages->count('uid', 'pages', ['pid' => self::ROOT_PID]) * 256 + 512,
            'doktype' => 1,
            'title' => $title,
            'slug' => $slug,
        ]);
        $pageUid = (int)$pages->lastInsertId();

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', [
            'pid' => $pageUid,
            'tstamp' => $now,
            'crdate' => $now,
            'sorting' => 256,
            'CType' => 'text',
            'header' => $title,
            'bodytext' => $bodytext,
        ]);

        $output->writeln(sprintf('Created "%s" as page uid %d.', $title, $pageUid));
    }

    private function imprintText(): string
    {
        return <<<'TEXT'
<p>Maik Demuth<br>
Gebrüder-Grimm-Str. 5<br>
65520 Bad Camberg</p>

<p>E-Mail: hi@maidem.de<br>
Telefon: +49 15164898515</p>

<p>Verantwortlich für den Inhalt gemäß § 18 Abs. 2 Medienstaatsvertrag (MStV): Maik Demuth (Anschrift wie oben).</p>
TEXT;
    }

    private function privacyText(): string
    {
        return <<<'TEXT'
<h2>Verantwortlicher</h2>
<p>Maik Demuth<br>
Gebrüder-Grimm-Str. 5<br>
65520 Bad Camberg<br>
E-Mail: hi@maidem.de<br>
Telefon: +49 15164898515</p>

<h2>Hosting</h2>
<p>Diese Website wird bei der Hetzner Online GmbH, Industriestr. 25, 91710 Gunzenhausen, betrieben. Beim Aufruf der Seite verarbeitet der Hoster automatisch Server-Logfiles (IP-Adresse, Datum und Uhrzeit des Zugriffs, aufgerufene Seite). Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse am sicheren und stabilen Betrieb der Website).</p>

<h2>Kundenkonto und Anmeldung</h2>
<p>Zur Nutzung des Kundenbereichs ist ein Login erforderlich. Dabei werden Benutzername und Passwort (als Hash) gespeichert. Die Anmeldedaten werden ausschließlich zur Authentifizierung verwendet. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO (Erfüllung des Überwachungsvertrags).</p>

<h2>Überwachungsdaten der TYPO3-Installationen</h2>
<p>Für jede überwachte TYPO3-Installation werden technische Daten verarbeitet: installierte Composer-Pakete, deren Versionen sowie der daraus abgeleitete Sicherheits- und Aktualitätsstatus. Diese Daten werden ausschließlich zur Bereitstellung des Überwachungsdienstes verarbeitet und sind einem Kundenkonto zugeordnet. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO.</p>
<p>Zur Ermittlung des Sicherheitsstatus werden Paketnamen und Versionsnummern an die öffentlichen Dienste get.typo3.org und Packagist übermittelt. Es werden dabei keine personenbezogenen Daten übertragen.</p>

<h2>Rechte der betroffenen Personen</h2>
<p>Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit sowie Widerspruch gegen die Verarbeitung Ihrer personenbezogenen Daten gemäß Art. 15–21 DSGVO. Wenden Sie sich hierzu an die oben genannte Kontaktadresse.</p>

<p><em>Stand: September 2026</em></p>
TEXT;
    }
}
