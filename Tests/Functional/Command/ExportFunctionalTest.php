<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Command\Export;
use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers exportXML()'s own orchestration logic reachable without a real CatXmlView/ExcelXmlView
 * render() (the "l10ncfg not loaded" early-exit, and the "wrong --format" validation, which both
 * happen before either View class is even instantiated) - driven end-to-end through execute() with
 * a real database-backed L10nConfiguration, unlike the existing Tests/Unit/Command/ExportTest.php
 * which only reaches execute()'s pre-database validation branches. The actual export pipeline
 * (Site resolution, CatXmlView/ExcelXmlView::render() file I/O, NotificationService, FTP upload)
 * needs CatXmlView/ExcelXmlView's own render() coverage to exist first and is deferred - see backlog.
 */
class ExportFunctionalTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_cfg.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    protected function tearDown(): void
    {
        $sitesPath = Environment::getConfigPath() . '/sites/';
        if (is_dir($sitesPath)) {
            GeneralUtility::rmdir($sitesPath, true);
        }
        $this->get(CacheManager::class)->getCache('core')->remove('sites-configuration');
        parent::tearDown();
    }

    private function createCommand(): Export
    {
        return new Export(new EmConfiguration(['enable_ftp' => 0, 'enable_notification' => 0]));
    }

    /**
     * Site with rootPageId=1 (matching pages.csv's root page), needed because
     * CatXmlView/ExcelXmlView resolve a Site in their constructor - reached as soon as
     * exportXML() gets past the "not loaded"/format checks, before the check-exports gate.
     */
    private function writeTwoLanguageSite(): void
    {
        $siteConfigPath = Environment::getConfigPath() . '/sites/test-site/';
        GeneralUtility::mkdir_deep($siteConfigPath);
        GeneralUtility::writeFile($siteConfigPath . 'config.yaml', <<<'YAML'
rootPageId: 1
base: 'https://example.com/'
languages:
  0:
    title: English
    enabled: true
    languageId: 0
    base: '/'
    typo3Language: default
    locale: en_US.UTF-8
    iso-639-1: en
    navigationTitle: English
    hreflang: en-US
    direction: ltr
    flag: us
  1:
    title: German
    enabled: true
    languageId: 1
    base: '/de/'
    typo3Language: de
    locale: de_DE.UTF-8
    iso-639-1: de
    navigationTitle: Deutsch
    hreflang: de-DE
    direction: ltr
    flag: de
YAML);
        $this->get(CacheManager::class)->getCache('core')->remove('sites-configuration');
    }

    #[Test]
    public function executeReportsTheConfigurationAsNotLoadedWhenTheGivenIdDoesNotExist(): void
    {
        $tester = new CommandTester($this->createCommand());

        $exitCode = $tester->execute(['--config' => '999999', '--target' => '1']);

        self::assertStringContainsString('Localization Manager object not loaded!', $tester->getDisplay());
        self::assertSame(0, $exitCode, 'exportXML() reports the error as text but execute() still returns 0 for it, unlike a real Exception');
    }

    #[Test]
    public function executeFailsWithAWrongFormatErrorWhenTheFormatOptionIsNeitherCatxmlNorExcel(): void
    {
        // tx_l10nmgr_cfg.csv's fixture row uid=1 is a real, loadable configuration - the format
        // check only runs once $l10nmgrCfgObj->isLoaded() is true.
        $tester = new CommandTester($this->createCommand());

        $exitCode = $tester->execute(['--config' => '1', '--target' => '1', '--format' => 'INVALID']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString("Wrong format. Use 'CATXML' or 'EXCEL'", $tester->getDisplay());
    }

    #[Test]
    public function executeAccumulatesANotLoadedMessagePerConfigWhenMultipleConfigsAreGiven(): void
    {
        // Neither config id is loadable, so both exportXML() calls take the "not loaded" string
        // return path rather than the Exception path - proves execute()'s outer foreach over
        // --config actually calls exportXML() once per id rather than stopping after the first.
        $tester = new CommandTester($this->createCommand());

        $exitCode = $tester->execute(['--config' => '999999,999998', '--target' => '1']);

        self::assertSame(0, $exitCode);
        self::assertSame(2, substr_count($tester->getDisplay(), 'Localization Manager object not loaded!'));
    }

    #[Test]
    public function executeShowsExistingExportsAndDoesNotExportAgainWhenCheckExportsFindsADuplicate(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->writeTwoLanguageSite();
        $this->getConnectionPool()->getConnectionForTable('tx_l10nmgr_exportdata')->insert('tx_l10nmgr_exportdata', [
            'l10ncfg_id' => 1,
            'exportType' => 1,
            'translation_lang' => 1,
            'crdate' => 0,
            'tstamp' => 0,
            'filename' => 'previous-export.xml',
        ]);
        $tester = new CommandTester($this->createCommand());

        $exitCode = $tester->execute(['--config' => '1', '--target' => '1', '--format' => 'CATXML', '--check-exports' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Contents are already exported for translation!', $tester->getDisplay());
        self::assertStringContainsString('previous-export.xml', $tester->getDisplay());
        // proves the real export branch (which prints this even when notifications/FTP are
        // disabled, see createCommand()) never ran, not just that the warning was printed alongside it
        self::assertStringNotContainsString('FTP upload disabled!', $tester->getDisplay());
    }
}
