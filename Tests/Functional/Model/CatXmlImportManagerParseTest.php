<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\CatXmlImportManager;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers parseAndCheckXMLFile()/parseAndCheckXMLString() themselves - the full parse-and-validate
 * flow, including real file I/O (GeneralUtility::getUrl()) and the real, container-resolved PSR-14
 * EventDispatcher dispatching XmlImportFileIsParsed. The pure array-parsing/header-validation logic
 * these two methods delegate to (_setHeaderData(), _isIncorrectXMLFile()/_isIncorrectXMLString())
 * is already unit-tested via reflection in CatXmlImportManagerTest.php - this file only adds the
 * integration slice reflection can't reach.
 */
class CatXmlImportManagerParseTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    private function fixturePath(string $filename): string
    {
        return __DIR__ . '/../Fixtures/CatXml/' . $filename;
    }

    #[Test]
    public function parseAndCheckXMLFileReturnsTrueForAWellFormedFileMatchingTheExpectedHeader(): void
    {
        // be_users.csv's admin user (uid=1) runs in workspace 0 by default.
        $subject = new CatXmlImportManager($this->fixturePath('valid-sysLang1-workspace0.xml'), 1, '');

        $result = $subject->parseAndCheckXMLFile();

        self::assertTrue($result);
        self::assertSame('', $subject->getErrorMessages());
        self::assertSame(
            ['t3_sysLang' => '1', 't3_workspaceId' => '0', 't3_formatVersion' => '2.0'],
            $subject->headerData
        );
    }

    #[Test]
    public function parseAndCheckXMLFileParsesTheDocumentIntoTraversableXmlNodes(): void
    {
        $subject = new CatXmlImportManager($this->fixturePath('valid-sysLang1-workspace0.xml'), 1, '');

        $subject->parseAndCheckXMLFile();

        self::assertArrayHasKey('head', $subject->getXMLNodes()['TYPO3L10N'][0]['ch']);
    }

    #[Test]
    public function parseAndCheckXMLFileReturnsFalseWhenTheRequestedLanguageDoesNotMatchTheHeader(): void
    {
        // Same well-formed file as above, but constructed for language 2 instead of the file's
        // t3_sysLang=1 - exercises _isIncorrectXMLFile() through the full public parse pipeline.
        $subject = new CatXmlImportManager($this->fixturePath('valid-sysLang1-workspace0.xml'), 2, '');

        $result = $subject->parseAndCheckXMLFile();

        self::assertFalse($result);
        self::assertNotSame('', $subject->getErrorMessages());
    }

    #[Test]
    public function parseAndCheckXMLFileReturnsFalseAndRecordsAnErrorWhenTheHeadSectionIsMissing(): void
    {
        $subject = new CatXmlImportManager($this->fixturePath('missing-head.xml'), 1, '');

        $result = $subject->parseAndCheckXMLFile();

        self::assertFalse($result);
        self::assertNotSame('', $subject->getErrorMessages());
    }

    #[Test]
    public function parseAndCheckXMLFileReturnsFalseWhenTheFileIsNotWellFormedXml(): void
    {
        $subject = new CatXmlImportManager($this->fixturePath('malformed.xml'), 1, '');

        $result = $subject->parseAndCheckXMLFile();

        self::assertFalse($result);
        self::assertNotSame('', $subject->getErrorMessages());
    }

    #[Test]
    public function parseAndCheckXMLFileReturnsFalseWhenTheFileDoesNotExist(): void
    {
        $subject = new CatXmlImportManager($this->fixturePath('does-not-exist.xml'), 1, '');

        $result = $subject->parseAndCheckXMLFile();

        self::assertFalse($result);
    }

    #[Test]
    public function parseAndCheckXMLStringReturnsTrueForAWellFormedStringMatchingTheExpectedHeader(): void
    {
        $xmlString = file_get_contents($this->fixturePath('valid-sysLang1-workspace0.xml'));
        $subject = new CatXmlImportManager('', 1, $xmlString);

        $result = $subject->parseAndCheckXMLString();

        self::assertTrue($result);
        self::assertSame('', $subject->getErrorMessages());
    }

    #[Test]
    public function parseAndCheckXMLStringDoesNotRequireTheLanguageToMatchExactlyUnlikeTheFileVariant(): void
    {
        // _isIncorrectXMLString() only checks that t3_sysLang is *present*, not that it equals
        // $this->sysLang - unlike _isIncorrectXMLFile()'s stricter check (see the sibling test
        // above and the unit-level characterization in CatXmlImportManagerTest.php).
        $xmlString = file_get_contents($this->fixturePath('valid-sysLang1-workspace0.xml'));
        $subject = new CatXmlImportManager('', 2, $xmlString);

        $result = $subject->parseAndCheckXMLString();

        self::assertTrue($result);
    }

    #[Test]
    public function parseAndCheckXMLStringReturnsFalseWhenTheStringIsNotWellFormedXml(): void
    {
        $subject = new CatXmlImportManager('', 1, '<TYPO3L10N><head>');

        $result = $subject->parseAndCheckXMLString();

        self::assertFalse($result);
        self::assertNotSame('', $subject->getErrorMessages());
    }

    #[Test]
    public function parseAndCheckXMLStringReturnsFalseAndRecordsAnErrorWhenTheHeadSectionIsMissing(): void
    {
        $subject = new CatXmlImportManager('', 1, '<TYPO3L10N></TYPO3L10N>');

        $result = $subject->parseAndCheckXMLString();

        self::assertFalse($result);
        self::assertNotSame('', $subject->getErrorMessages());
    }

    #[Test]
    public function parseAndCheckXMLStringReturnsFalseWhenTheWorkspaceDoesNotMatchTheCurrentBackendUser(): void
    {
        $xmlString = <<<'XML'
<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<TYPO3L10N>
	<head>
		<t3_sysLang translate="no">1</t3_sysLang>
		<t3_workspaceId translate="no">5</t3_workspaceId>
		<t3_formatVersion translate="no">2.0</t3_formatVersion>
	</head>
</TYPO3L10N>
XML;
        // The admin user from be_users.csv runs in workspace 0, the fixture claims workspace 5.
        $subject = new CatXmlImportManager('', 1, $xmlString);

        $result = $subject->parseAndCheckXMLString();

        self::assertFalse($result);
        self::assertNotSame('', $subject->getErrorMessages());
    }
}
