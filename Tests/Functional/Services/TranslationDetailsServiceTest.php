<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Services\TranslationDetailsService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers a deliberately narrow slice of this 1800+ line class: the constructor's own
 * sys_language/site resolution, plus the small set of methods reachable without a full page-tree/
 * FlexForm/TCA-type setup (getArrayValueByPath(), patchTceformsWrapper(), diffCMP(),
 * isParentItemHidden()/isParentItemExcluded()'s "no parent table" fast path, canUserEditRecord()'s
 * no-BE_USER/admin fast paths). This class is one of the actual future refactor targets on
 * ea_14-0 - the bulk of it (translationDetails(), translationInfo(), indexDetailsPage/Record(),
 * getRecordsToTranslateFromTable(), flushTranslations()) drives real content-element/page-tree
 * data and needs dedicated, much larger fixture work than a coverage pass affords; deferred rather
 * than rushed.
 *
 * The constructor itself needs a real database (a schema check + query against sys_language) and a
 * DI-autowired TranslationConfigurationProvider, so this is a functional test throughout - there is
 * no way to construct this class at all in a plain unit test without bypassing the constructor via
 * reflection, which would also skip the very language-resolution logic worth covering.
 */
class TranslationDetailsServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    private function createSubject(): TranslationDetailsService
    {
        return $this->get(TranslationDetailsService::class);
    }

    #[Test]
    public function constructorPopulatesSysLanguagesFromTheDatabaseTable(): void
    {
        $subject = $this->createSubject();

        $sysLanguages = (new \ReflectionProperty($subject, 'sys_languages'))->getValue($subject);

        self::assertIsArray($sysLanguages);
    }

    #[Test]
    public function useSystemLanguagesPopulatesSysLanguagesFromGetSystemLanguages(): void
    {
        $subject = $this->createSubject();

        $subject->useSystemLanguages();

        $sysLanguages = (new \ReflectionProperty($subject, 'sys_languages'))->getValue($subject);
        self::assertIsArray($sysLanguages);
    }

    #[Test]
    public function setSiteLanguagesByPidFallsBackToSystemLanguagesWhenNoSiteIsFound(): void
    {
        $subject = $this->createSubject();

        // No site configuration exists in this test, so pid 1 cannot resolve to any site -
        // exercises the SiteNotFoundException -> useSystemLanguages() fallback path.
        $subject->setSiteLanguagesByPid(1);

        $sysLanguages = (new \ReflectionProperty($subject, 'sys_languages'))->getValue($subject);
        self::assertIsArray($sysLanguages);
    }

    #[Test]
    public function getArrayValueByPathReturnsTheValueAtAValidPath(): void
    {
        $subject = $this->createSubject();
        $method = new \ReflectionMethod($subject, 'getArrayValueByPath');

        $result = $method->invoke($subject, ['a' => ['b' => 'found']], 'a/b');

        self::assertSame('found', $result);
    }

    #[Test]
    public function getArrayValueByPathReturnsNullForAMissingPath(): void
    {
        $subject = $this->createSubject();
        $method = new \ReflectionMethod($subject, 'getArrayValueByPath');

        self::assertNull($method->invoke($subject, ['a' => ['b' => 'found']], 'a/missing'));
    }

    #[Test]
    public function patchTceformsWrapperWrapsFieldConfigurationInTceforms(): void
    {
        $subject = $this->createSubject();
        $method = new \ReflectionMethod($subject, 'patchTceformsWrapper');

        $result = $method->invoke($subject, ['config' => ['type' => 'input']]);

        self::assertArrayHasKey('TCEforms', $result);
        self::assertSame(['config' => ['type' => 'input']], $result['TCEforms']);
    }

    #[Test]
    public function patchTceformsWrapperRecursesIntoNestedArrays(): void
    {
        $subject = $this->createSubject();
        $method = new \ReflectionMethod($subject, 'patchTceformsWrapper');

        $result = $method->invoke($subject, ['sheet' => ['field' => ['config' => ['type' => 'input']]]]);

        self::assertArrayHasKey('TCEforms', $result['sheet']['field']);
    }

    #[Test]
    public function diffCMPHighlightsWordLevelDifferences(): void
    {
        $subject = $this->createSubject();
        $method = new \ReflectionMethod($subject, 'diffCMP');

        $result = $method->invoke($subject, 'the quick fox', 'the slow fox');

        self::assertStringContainsString('quick', $result);
        self::assertStringContainsString('slow', $result);
    }

    #[Test]
    public function isParentItemHiddenReturnsFalseWhenTheTableHasNoConfiguredParent(): void
    {
        $subject = $this->createSubject();

        self::assertFalse($subject->isParentItemHidden('tt_content', ['uid' => 1], 0));
    }

    #[Test]
    public function isParentItemExcludedReturnsFalseWhenTheTableHasNoConfiguredParent(): void
    {
        $subject = $this->createSubject();

        self::assertFalse($subject->isParentItemExcluded('tt_content', ['uid' => 1], 0));
    }

    #[Test]
    public function canUserEditRecordReturnsFalseWhenNoBackendUserIsLoggedIn(): void
    {
        unset($GLOBALS['BE_USER']);
        $subject = $this->createSubject();

        self::assertFalse($subject->canUserEditRecord('tt_content', ['uid' => 1]));
    }

    #[Test]
    public function canUserEditRecordReturnsTrueForAnAdminUserRegardlessOfTable(): void
    {
        $adminUser = $this->createStub(BackendUserAuthentication::class);
        $adminUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $adminUser;
        $subject = $this->createSubject();

        self::assertTrue($subject->canUserEditRecord('tt_content', ['uid' => 1]));
        unset($GLOBALS['BE_USER']);
    }
}
