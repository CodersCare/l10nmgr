<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\View\AbstractExportView;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Characterization test for AbstractExportView::diffCMP(), written ahead of the
 * L10N-004 fix (TYPO3\CMS\Core\Utility\DiffUtility::makeDiffDisplay() is removed in
 * CMS 14; the replacement diff() does not strip tags on its own, so the fix must
 * restore that behavior manually). Localizationteam\L10nmgr\Services\TranslationDetailsService::diffCMP()
 * contains the exact same one-line implementation and is covered by the same fix,
 * but is not separately tested here since it shares this identical logic.
 *
 * Instantiated via a minimal concrete subclass with a no-op constructor, since
 * AbstractExportView's real constructor needs a booted SiteFinder/LanguageService
 * (site configuration) that a pure unit test shouldn't need to depend on, and
 * AbstractExportView has no genuinely unimplemented abstract methods to stub.
 */
class AbstractExportViewTest extends UnitTestCase
{
    private function createSubject(): AbstractExportView
    {
        return new class() extends AbstractExportView {
            public function __construct()
            {
                // Intentionally skip the parent constructor's SiteFinder/LanguageService
                // dependency — not needed to exercise diffCMP().
            }

            public function render(): string
            {
                return '';
            }
        };
    }

    #[Test]
    public function diffCMPStripsHtmlTagsBeforeDiffing(): void
    {
        $subject = $this->createSubject();

        $result = $subject->diffCMP('<p>old text</p>', '<p>new text</p>');

        self::assertStringNotContainsString(
            '<p>',
            $result,
            'diffCMP() must strip HTML tags from its inputs before diffing, matching the pre-CMS14 DiffUtility::makeDiffDisplay() default ($stripTags = true) behavior'
        );
    }

    #[Test]
    public function diffCMPHighlightsWordLevelDifferences(): void
    {
        $subject = $this->createSubject();

        $result = $subject->diffCMP('the quick fox', 'the slow fox');

        self::assertStringContainsString('quick', $result);
        self::assertStringContainsString('slow', $result);
    }

    #[Test]
    public function diffCMPReturnsEmptyMarkupForIdenticalStrings(): void
    {
        $subject = $this->createSubject();

        $result = $subject->diffCMP('identical text', 'identical text');

        self::assertStringNotContainsString('<ins', $result);
        self::assertStringNotContainsString('<del', $result);
    }

    private function createSite(): Site
    {
        return new Site('test-site', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
            ],
        ]);
    }

    private function createL10nConfiguration(array $overrides = []): L10nConfiguration
    {
        $l10nConfiguration = new L10nConfiguration();
        $l10nConfiguration->l10ncfg = array_merge(['pid' => 1, 'filenameprefix' => ''], $overrides);
        return $l10nConfiguration;
    }

    #[Test]
    public function getFileNameBuildsANameFromTheResolvedSourceAndTargetLanguages(): void
    {
        $GLOBALS['BE_USER'] = self::createStub(BackendUserAuthentication::class);
        $GLOBALS['BE_USER']->method('checkLanguageAccess')->willReturn(true);
        $subject = $this->createSubject();
        (new \ReflectionProperty($subject, 'site'))->setValue($subject, $this->createSite());
        (new \ReflectionProperty($subject, 'l10ncfgObj'))->setValue($subject, $this->createL10nConfiguration());
        (new \ReflectionProperty($subject, 'targetLanguage'))->setValue($subject, 1);
        (new \ReflectionProperty($subject, 'exportType'))->setValue($subject, 0);

        $fileName = $subject->getFileName();

        self::assertStringStartsWith('excel_en', $fileName);
        self::assertStringContainsString('_to_de', $fileName);
        self::assertStringEndsWith('.xml', $fileName);
        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function getFileNamePrefixesWithTheBasenameOfTheConfiguredFileNamePrefixIgnoringAnyPathComponents(): void
    {
        // basename() strips any path components a malformed/legacy filenameprefix config value
        // might contain, preventing path traversal in the export filename - documented directly
        // in the source as a security-relevant choice, worth a dedicated characterization test.
        $GLOBALS['BE_USER'] = self::createStub(BackendUserAuthentication::class);
        $GLOBALS['BE_USER']->method('checkLanguageAccess')->willReturn(true);
        $subject = $this->createSubject();
        (new \ReflectionProperty($subject, 'site'))->setValue($subject, $this->createSite());
        (new \ReflectionProperty($subject, 'l10ncfgObj'))->setValue($subject, $this->createL10nConfiguration(['filenameprefix' => '../../etc/evil']));
        (new \ReflectionProperty($subject, 'targetLanguage'))->setValue($subject, 1);
        (new \ReflectionProperty($subject, 'exportType'))->setValue($subject, 0);

        $fileName = $subject->getFileName();

        self::assertStringStartsWith('evil_excel_en', $fileName);
        self::assertStringNotContainsString('..', $fileName);
        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function getFileNameThrowsWhenTheTargetLanguageIsNotAvailableOnTheSite(): void
    {
        $GLOBALS['BE_USER'] = self::createStub(BackendUserAuthentication::class);
        $GLOBALS['BE_USER']->method('checkLanguageAccess')->willReturn(true);
        $subject = $this->createSubject();
        (new \ReflectionProperty($subject, 'site'))->setValue($subject, $this->createSite());
        (new \ReflectionProperty($subject, 'l10ncfgObj'))->setValue($subject, $this->createL10nConfiguration());
        (new \ReflectionProperty($subject, 'targetLanguage'))->setValue($subject, 99);
        (new \ReflectionProperty($subject, 'exportType'))->setValue($subject, 0);

        $this->expectException(\TYPO3\CMS\Core\Exception::class);

        $subject->getFileName();
        unset($GLOBALS['BE_USER']);
    }
}
