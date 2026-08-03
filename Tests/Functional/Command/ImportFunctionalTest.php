<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Command\Import;
use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers getXMLFileHead(), gatherAllFiles(), sendMailNotification()'s early-exit guards, and the
 * error/exception wiring of importCATXML()/importXMLFile() - real file I/O, real ZIP extraction,
 * and real sys_workspace/tx_l10nmgr_cfg database reads. The pure-logic pieces (getWsIdFromCATXML(),
 * checkFileType(), initializeCallParameters(), configure()) are already unit-tested in
 * Tests/Unit/Command/ImportTest.php. The full successful-persistence path through
 * L10nBaseService::saveTranslation() (DataHandler-backed record writes) remains deferred - it needs
 * a byte-exact CATXML fixture matching TranslationDetailsService's computed field keys, which is
 * disproportionate to this batch; getFilesFromFtp() needs a real FTP server and is not exercised
 * here either.
 */
class ImportFunctionalTest extends FunctionalTestCase
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

    private function createCommand(array $configuration = ['enable_ftp' => 0]): Import
    {
        return new Import(new EmConfiguration($configuration));
    }

    private function fixturePath(string $filename): string
    {
        return __DIR__ . '/../Fixtures/CatXml/' . $filename;
    }

    private function invoke(Import $subject, string $method, array $args = []): mixed
    {
        return (new \ReflectionMethod($subject, $method))->invokeArgs($subject, $args);
    }

    #[Test]
    public function getXMLFileHeadReturnsTheHeaderNodesForAWellFormedFile(): void
    {
        $subject = $this->createCommand();

        $result = $this->invoke($subject, 'getXMLFileHead', [$this->fixturePath('valid-sysLang1-workspace0.xml')]);

        self::assertArrayHasKey('t3_sysLang', $result);
    }

    #[Test]
    public function getXMLFileHeadThrowsWhenTheFileCannotBeRead(): void
    {
        $subject = $this->createCommand();

        $this->expectException(Exception::class);

        $this->invoke($subject, 'getXMLFileHead', [$this->fixturePath('does-not-exist.xml')]);
    }

    #[Test]
    public function getXMLFileHeadThrowsWithAMeaningfulMessageWhenTheHeadSectionIsMissing(): void
    {
        $subject = $this->createCommand();

        try {
            $this->invoke($subject, 'getXMLFileHead', [$this->fixturePath('missing-head.xml')]);
            self::fail('Expected an Exception to be thrown');
        } catch (Exception $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    #[Test]
    public function getXMLFileHeadThrowsWhenTheFileIsNotWellFormedXml(): void
    {
        $subject = $this->createCommand();

        $this->expectException(Exception::class);

        $this->invoke($subject, 'getXMLFileHead', [$this->fixturePath('malformed.xml')]);
    }

    #[Test]
    public function gatherAllFilesReturnsEmptyArrayWhenNoFileGivenAndNoFtpServerConfigured(): void
    {
        $subject = $this->createCommand();

        self::assertSame([], $this->invoke($subject, 'gatherAllFiles', ['']));
    }

    #[Test]
    public function gatherAllFilesReturnsTheGivenXmlFileAsIs(): void
    {
        $subject = $this->createCommand();
        $path = $this->fixturePath('valid-sysLang1-workspace0.xml');

        self::assertSame([$path], $this->invoke($subject, 'gatherAllFiles', [$path]));
    }

    #[Test]
    public function gatherAllFilesExtractsTheXmlFileContainedInAZipArchive(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'l10nmgr-test-') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile($this->fixturePath('valid-sysLang1-workspace0.xml'), 'valid-sysLang1-workspace0.xml');
        $zip->close();
        $subject = $this->createCommand();

        $result = $this->invoke($subject, 'gatherAllFiles', [$zipPath]);

        self::assertCount(1, $result);
        self::assertStringEndsWith('valid-sysLang1-workspace0.xml', $result[0]);
        unlink($zipPath);
    }

    #[Test]
    public function sendMailNotificationDoesNothingWhenNotificationsAreDisabled(): void
    {
        $subject = $this->createCommand(['enable_ftp' => 0, 'enable_notification' => 0]);
        $filesImportedProperty = new \ReflectionProperty($subject, 'filesImported');
        $filesImportedProperty->setValue($subject, ['/tmp/some-file.xml' => ['workspace' => 0, 'language' => 1, 'configuration' => 1]]);

        $this->invoke($subject, 'sendMailNotification');

        self::addToAssertionCount(1);
    }

    #[Test]
    public function sendMailNotificationDoesNothingWhenNoFilesWereImported(): void
    {
        $subject = $this->createCommand(['enable_ftp' => 0, 'enable_notification' => 1, 'email_recipient_import' => 'translator@example.com']);

        $this->invoke($subject, 'sendMailNotification');

        self::addToAssertionCount(1);
    }

    #[Test]
    public function importCATXMLThrowsWithANonEmptyMessageWhenTheXmlStringCannotBeParsed(): void
    {
        $subject = $this->createCommand();

        try {
            $this->invoke($subject, 'importCATXML', [['string' => '<TYPO3L10N><head>', 'importAsDefaultLanguage' => false, 'sourcePid' => 0, 'preview' => false, 'server' => '']]);
            self::fail('Expected an Exception to be thrown');
        } catch (Exception $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    #[Test]
    public function importCATXMLThrowsWhenTheL10nConfigurationIsNotLoaded(): void
    {
        $xmlString = file_get_contents($this->fixturePath('valid-with-missing-l10ncfg.xml'));
        $subject = $this->createCommand();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('l10ncfg not loaded! Exiting...');

        $this->invoke($subject, 'importCATXML', [['string' => $xmlString, 'importAsDefaultLanguage' => false, 'sourcePid' => 0, 'preview' => false, 'server' => '']]);
    }

    #[Test]
    public function importXMLFileThrowsWhenNoFilesAreFoundToImport(): void
    {
        $subject = $this->createCommand();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No files to import');

        $this->invoke($subject, 'importXMLFile', [['file' => '', 'importAsDefaultLanguage' => false, 'sourcePid' => 0, 'preview' => false, 'server' => '']]);
    }

    #[Test]
    public function importXMLFileReportsABadlyFormattedFileWhenTheL10nConfigurationIsNotLoaded(): void
    {
        $subject = $this->createCommand();

        try {
            $this->invoke($subject, 'importXMLFile', [[
                'file' => $this->fixturePath('valid-with-missing-l10ncfg.xml'),
                'importAsDefaultLanguage' => false,
                'sourcePid' => 0,
                'preview' => false,
                'server' => '',
            ]]);
            self::fail('Expected an Exception to be thrown');
        } catch (Exception $e) {
            self::assertStringContainsString('l10ncfg not loaded', $e->getMessage());
        }
    }
}
