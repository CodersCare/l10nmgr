<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\CatXmlImportManager;
use Localizationteam\L10nmgr\Model\TranslationDataFactory;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Characterization test for GitHub issue l10nmgr_EA#2 (L10N-110): text preceding an inline HTML
 * tag (e.g. <br />) inside a plain (non-RTE) field's CATXML <data> node is lost on import.
 *
 * Root cause: XmlService::xml2tree()'s XMLvalue reconstruction (Classes/Services/XmlService.php,
 * around line 98) slices $vals starting at $startPoint + 1, i.e. right after the <data> open-tag
 * event - but PHP's xml_parse_into_struct() attaches text preceding the first child tag directly
 * onto that open-tag event's own 'value' key, not as a separate array entry. That leading text is
 * therefore never included in the slice passed to xmlRecompileFromStructValArray(), and is
 * silently dropped from the reconstructed XMLvalue - which is exactly the string
 * TranslationDataFactory::getParsedCATXMLFromXMLNodes() stores as the imported field value
 * (Classes/Model/TranslationDataFactory.php line 111).
 */
class TranslationDataFactoryCatXmlTest extends FunctionalTestCase
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

    #[Test]
    public function textPrecedingAnInlineTagInAPlainFieldSurvivesImport(): void
    {
        $xmlString = file_get_contents(__DIR__ . '/../Fixtures/CatXml/mixed-content-headline.xml');
        $importManager = new CatXmlImportManager('', 1, $xmlString);
        self::assertTrue($importManager->parseAndCheckXMLString(), $importManager->getErrorMessages());

        $factory = new TranslationDataFactory();
        $factory->setLogger(new NullLogger());
        $translationData = $factory->getTranslationDataFromCATXMLNodes($importManager->getXMLNodes());

        self::assertSame(
            'Test HTML-Tag <br /> in Headline',
            $translationData->getTranslationData()['tt_content']['14599']['subheader'] ?? null
        );
    }
}
