<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Services\XmlService;
use Localizationteam\L10nmgr\View\CatXmlView;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Html\RteHtmlParser;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers getValueForXml(), renderInternalMessage(), additionalHeaderData() and the base-url/
 * override-params setters - the parts of CatXmlView that don't need the full
 * L10nAccumulatedInformation/Site pipeline. render() itself drives that whole pipeline (via
 * L10nConfiguration::getL10nAccumulatedInformationsObjectForLanguage()) and is deferred alongside
 * L10nAccumulatedInformation (see backlog).
 *
 * Instantiated via a minimal concrete subclass with a no-op constructor, same rationale as
 * AbstractExportViewTest. getValueForXml()'s one branch that would otherwise need a real backend
 * user (reading module data prefs) is skipped automatically under PHPUnit, since
 * Environment::isCli() is true in a CLI test run.
 */
class CatXmlViewTest extends UnitTestCase
{
    private function createSubject(): CatXmlView
    {
        return new class() extends CatXmlView {
            public function __construct()
            {
            }
        };
    }

    /**
     * getValueForXml() calls GeneralUtility::makeInstance(XmlService::class) internally, which
     * cannot autowire XmlService's RteHtmlParser dependency outside a booted DI container. A real
     * XmlService (backed by stubbed EventDispatcher/Logger dependencies it never actually needs for
     * isValidXMLString()'s pure XML-parsing logic) is registered via addInstance() so the real
     * validity-check logic is genuinely exercised rather than mocked away. Must be called exactly
     * once per test that reaches getValueForXml() - addInstance()'s queue must be fully consumed or
     * tearDown()'s integrity check fails the test.
     */
    private function registerXmlServiceInstance(): void
    {
        $xmlService = new XmlService(new RteHtmlParser(self::createStub(EventDispatcherInterface::class)));
        $xmlService->setLogger(self::createStub(LoggerInterface::class));
        GeneralUtility::addInstance(XmlService::class, $xmlService);
    }

    private function invokeGetValueForXml(CatXmlView $subject, array $tData, string $key): ?string
    {
        return (new \ReflectionMethod($subject, 'getValueForXml'))->invoke($subject, $tData, $key);
    }

    #[Test]
    public function getValueForXmlEscapesAmpersandsInPlainFields(): void
    {
        $this->registerXmlServiceInstance();
        $subject = $this->createSubject();
        (new \ReflectionProperty($subject, 'params'))->setValue($subject, ['noxmlcheck' => false]);

        $result = $this->invokeGetValueForXml($subject, ['defaultValue' => 'Fish & Chips'], 'field:1/1:header');

        self::assertSame('Fish &amp; Chips', $result);
    }

    #[Test]
    public function getValueForXmlLeavesAlreadyEscapedEntitiesUntouched(): void
    {
        $this->registerXmlServiceInstance();
        $subject = $this->createSubject();
        (new \ReflectionProperty($subject, 'params'))->setValue($subject, ['noxmlcheck' => false]);

        $result = $this->invokeGetValueForXml($subject, ['defaultValue' => 'Fish &amp; Chips'], 'field:1/1:header');

        self::assertSame('Fish &amp; Chips', $result);
    }

    #[Test]
    public function getValueForXmlSelfClosesBrAndHrTags(): void
    {
        $this->registerXmlServiceInstance();
        $subject = $this->createSubject();
        (new \ReflectionProperty($subject, 'params'))->setValue($subject, ['noxmlcheck' => false]);

        $result = $this->invokeGetValueForXml($subject, ['defaultValue' => 'line one<br>line two<hr>'], 'field:1/1:header');

        self::assertSame('line one<br />line two<hr />', $result);
    }

    #[Test]
    public function getValueForXmlWrapsInvalidXmlInCdataWhenNoXmlCheckIsEnabled(): void
    {
        $this->registerXmlServiceInstance();
        $subject = $this->createSubject();
        (new \ReflectionProperty($subject, 'params'))->setValue($subject, ['noxmlcheck' => true]);

        // An unescaped '<' makes this invalid XML content on its own
        $result = $this->invokeGetValueForXml($subject, ['defaultValue' => 'a<b'], 'field:1/1:header');

        self::assertSame('<![CDATA[a<b]]>', $result);
    }

    #[Test]
    public function getValueForXmlReturnsNullForInvalidXmlWhenNoXmlCheckIsDisabled(): void
    {
        $this->registerXmlServiceInstance();
        $subject = $this->createSubject();
        (new \ReflectionProperty($subject, 'params'))->setValue($subject, ['noxmlcheck' => false]);

        $result = $this->invokeGetValueForXml($subject, ['defaultValue' => 'a<b'], 'field:1/1:header');

        self::assertNull($result);
    }

    #[Test]
    public function getValueForXmlStripsBadUtf8BytesWhenUtf8ModeIsEnabled(): void
    {
        $this->registerXmlServiceInstance();
        $subject = $this->createSubject();
        (new \ReflectionProperty($subject, 'params'))->setValue($subject, ['noxmlcheck' => false, 'utf8' => true]);

        $result = $this->invokeGetValueForXml($subject, ['defaultValue' => "ok\x80bad"], 'field:1/1:header');

        self::assertSame('okbad', $result);
    }

    #[Test]
    public function getValueForXmlUsesTheForcedSourceLanguagePreviewValueWhenForcedSourceLanguageIsSet(): void
    {
        $this->registerXmlServiceInstance();
        $subject = $this->createSubject();
        (new \ReflectionProperty($subject, 'params'))->setValue($subject, ['noxmlcheck' => false]);
        (new \ReflectionProperty($subject, 'forcedSourceLanguage'))->setValue($subject, 2);

        $result = $this->invokeGetValueForXml($subject, [
            'defaultValue' => 'default value',
            'previewLanguageValues' => [2 => 'forced source value'],
        ], 'field:1/1:header');

        self::assertSame('forced source value', $result);
    }

    #[Test]
    public function renderInternalMessageRendersEachMessageAsASkippedItemBlock(): void
    {
        $subject = $this->createSubject();
        (new \ReflectionProperty($subject, 'internalMessages'))->setValue($subject, [
            ['message' => 'Some reason', 'key' => 'tt_content:5'],
        ]);
        $method = new \ReflectionMethod($subject, 'renderInternalMessage');

        $result = $method->invoke($subject);

        self::assertStringContainsString('<t3_description>Some reason</t3_description>', $result);
        self::assertStringContainsString('<t3_key>tt_content:5</t3_key>', $result);
    }

    #[Test]
    public function renderInternalMessageReturnsEmptyStringWhenThereAreNoMessages(): void
    {
        $subject = $this->createSubject();

        $method = new \ReflectionMethod($subject, 'renderInternalMessage');

        self::assertSame('', $method->invoke($subject));
    }

    #[Test]
    public function additionalHeaderDataRendersKeysAndValuesForObjectShapedMetadataJson(): void
    {
        // json_decode() now passes $assoc=true, so object-shaped JSON - {"customer":"Acme"}, the
        // natural encoding for named key/value metadata - decodes to a PHP array and renders as
        // documented, instead of decoding to a stdClass and silently rendering nothing.
        $subject = $this->createSubject();
        $l10nConfiguration = new L10nConfiguration();
        $l10nConfiguration->l10ncfg = ['metadata' => json_encode(['customer' => 'Acme', 'project' => 'Website'])];
        (new \ReflectionProperty($subject, 'l10ncfgObj'))->setValue($subject, $l10nConfiguration);
        $method = new \ReflectionMethod($subject, 'additionalHeaderData');

        $result = $method->invoke($subject);

        self::assertStringContainsString('<customer>Acme</customer>', $result);
        self::assertStringContainsString('<project>Website</project>', $result);
    }

    #[Test]
    public function additionalHeaderDataOnlyActuallyRendersForArrayShapedMetadataJson(): void
    {
        // The only JSON shape that survives the missing $assoc=true (see the no-op test above):
        // a JSON array decodes to a PHP array by default even without that flag, so is_array()
        // passes - but then the array's numeric indexes become the tag names, not any real key.
        $subject = $this->createSubject();
        $l10nConfiguration = new L10nConfiguration();
        $l10nConfiguration->l10ncfg = ['metadata' => json_encode(['just-a-value'])];
        (new \ReflectionProperty($subject, 'l10ncfgObj'))->setValue($subject, $l10nConfiguration);
        $method = new \ReflectionMethod($subject, 'additionalHeaderData');

        $result = $method->invoke($subject);

        self::assertStringContainsString('<0>just-a-value</0>', $result);
    }

    #[Test]
    public function additionalHeaderDataReturnsEmptyStringWhenNoMetadataIsConfigured(): void
    {
        $subject = $this->createSubject();
        $l10nConfiguration = new L10nConfiguration();
        $l10nConfiguration->l10ncfg = ['metadata' => ''];
        (new \ReflectionProperty($subject, 'l10ncfgObj'))->setValue($subject, $l10nConfiguration);
        $method = new \ReflectionMethod($subject, 'additionalHeaderData');

        self::assertSame('', $method->invoke($subject));
    }

    #[Test]
    public function setBaseUrlAndSetOverrideParamsStoreTheGivenValues(): void
    {
        $subject = $this->createSubject();

        $subject->setBaseUrl('https://example.com/');
        $subject->setOverrideParams(['utf8' => true]);

        self::assertSame('https://example.com/', (new \ReflectionProperty($subject, 'baseUrl'))->getValue($subject));
        self::assertSame(['utf8' => true], (new \ReflectionProperty($subject, 'overrideParams'))->getValue($subject));
    }
}
