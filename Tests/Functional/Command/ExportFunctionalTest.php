<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Command\Export;
use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers exportXML()'s own orchestration logic reachable without a real CatXmlView/ExcelXmlView
 * render() (the "l10ncfg not loaded" early-exit, and the "wrong --format" validation, which both
 * happen before either View class is even instantiated) - driven end-to-end through execute() with
 * a real database-backed L10nConfiguration, unlike the existing Tests/Unit/Command/ExportTest.php
 * which only reaches execute()'s pre-database validation branches. The actual export pipeline
 * (Site resolution, CatXmlView/ExcelXmlView::render() file I/O, NotificationService, FTP upload)
 * needs L10N-071's CatXmlView/ExcelXmlView coverage to exist first and is deferred - see backlog.
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

    private function createCommand(): Export
    {
        return new Export(new EmConfiguration(['enable_ftp' => 0, 'enable_notification' => 0]));
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
}
