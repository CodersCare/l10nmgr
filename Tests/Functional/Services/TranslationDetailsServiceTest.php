<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Services\FlexFormService;
use Localizationteam\L10nmgr\Services\TranslationDetailsService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers this 1800+ line class's constructor sys_language/site resolution, the small set of
 * methods reachable without a full page-tree/FlexForm/TCA-type setup (getArrayValueByPath(),
 * patchTceformsWrapper(), diffCMP(), isParentItemHidden()/isParentItemExcluded()'s "no parent
 * table" fast path, canUserEditRecord()'s no-BE_USER/admin fast paths), translationDetails() and
 * its FlexForm-callback cluster, translationInfo()/_detectTranslationModes(), and the indexing/
 * persistence cluster (indexDetailsRecord(), getSingleRecordToTranslate(),
 * getAllowedFieldsForTable(), filterIndex(), compileIndexRecord(), updateIndexTableFromDetailsArray(),
 * bulkUpdateIndexTable()). indexDetailsPage(), updateIndexForRecord(), and flushTranslations() have
 * zero callers anywhere in this workspace and are intentionally left untested.
 * getRecordsToTranslateFromTable() remains future refactor-target work, deferred rather than rushed.
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

        $subject->setSiteLanguagesByPid(1);

        $sysLanguages = (new \ReflectionProperty($subject, 'sys_languages'))->getValue($subject);
        self::assertIsArray($sysLanguages);
    }

    #[Test]
    public function indexDetailsRecordExtractsLanguageUidFromRawSysLanguageArrays(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $adminUser = self::createStub(BackendUserAuthentication::class);
        $adminUser->method('isAdmin')->willReturn(true);
        $adminUser->workspace = 0;
        $GLOBALS['BE_USER'] = $adminUser;

        $subject = $this->createSubject();
        $subject->bypassFilter = true;
        (new \ReflectionProperty($subject, 'sys_languages'))->setValue($subject, [
            ['uid' => 0, 'title' => 'Default'],
            ['uid' => 1, 'title' => 'German'],
        ]);

        $items = $subject->indexDetailsRecord('pages', 2);

        self::assertArrayHasKey(0, $items['fullDetails'] ?? []);
        self::assertArrayHasKey(1, $items['fullDetails'] ?? []);

        unset($GLOBALS['BE_USER']);
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
        $adminUser = self::createStub(BackendUserAuthentication::class);
        $adminUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $adminUser;
        $subject = $this->createSubject();

        self::assertTrue($subject->canUserEditRecord('tt_content', ['uid' => 1]));
        unset($GLOBALS['BE_USER']);
    }

    private function readDetailsOutput(TranslationDetailsService $subject): array
    {
        return (new \ReflectionProperty($subject, 'detailsOutput'))->getValue($subject);
    }

    private function invokeAddField(
        TranslationDetailsService $subject,
        string $key,
        array $TCEformsCfg,
        string $dataValue = 'Default Value',
        string $translationValue = 'Translated Value'
    ): void {
        (new \ReflectionMethod($subject, 'translationDetails_addField'))
            ->invoke($subject, $key, $TCEformsCfg, $dataValue, $translationValue, '', [], ['uid' => 10]);
    }

    #[Test]
    public function translationDetailsAddFieldAddsAPlainFieldToDetailsOutput(): void
    {
        $subject = $this->createSubject();
        $key = 'tt_content:10:header';

        $this->invokeAddField($subject, $key, ['config' => ['type' => 'input']]);

        $field = $this->readDetailsOutput($subject)['fields'][$key] ?? null;
        self::assertSame('Default Value', $field['defaultValue'] ?? null);
        self::assertSame('Translated Value', $field['translationValue'] ?? null);
    }

    #[Test]
    public function translationDetailsAddFieldSkipsFieldsExcludedViaL10nMode(): void
    {
        $subject = $this->createSubject();
        $key = 'tt_content:10:subheader';

        $this->invokeAddField($subject, $key, ['config' => ['type' => 'input'], 'l10n_mode' => 'exclude']);

        $field = $this->readDetailsOutput($subject)['fields'][$key] ?? null;
        self::assertIsString($field);
        self::assertStringContainsString('l10n_mode', $field);
    }

    #[Test]
    public function translationDetailsAddFieldSkipsFieldsWithHideL10nSiblingsDisplayCondition(): void
    {
        $subject = $this->createSubject();
        $key = 'tt_content:10:bodytext';

        $this->invokeAddField($subject, $key, [
            'config' => ['type' => 'input'],
            'displayCond' => 'HIDE_L10N_SIBLINGS:1',
        ]);

        $field = $this->readDetailsOutput($subject)['fields'][$key] ?? null;
        self::assertIsString($field);
        self::assertStringContainsString('HIDE_L10N_SIBLINGS', $field);
    }

    #[Test]
    public function translationDetailsAddFieldSkipsFlexTypeFields(): void
    {
        $subject = $this->createSubject();
        $key = 'tt_content:10:pi_flexform';

        $this->invokeAddField($subject, $key, ['config' => ['type' => 'flex']]);

        $field = $this->readDetailsOutput($subject)['fields'][$key] ?? null;
        self::assertIsString($field);
        self::assertStringContainsString('flex', $field);
    }

    #[Test]
    public function isRTEFieldReturnsTrueWhenEnableRichtextIsSetOnTheFieldConfig(): void
    {
        $subject = $this->createSubject();
        $method = new \ReflectionMethod($subject, '_isRTEField');

        $result = $method->invoke(
            $subject,
            'tt_content:10:bodytext',
            ['config' => ['enableRichtext' => true]],
            ['uid' => 10, 'CType' => 'text']
        );

        self::assertTrue($result);
    }

    #[Test]
    public function isRTEFieldReturnsFalseForAPlainInputField(): void
    {
        $subject = $this->createSubject();
        $method = new \ReflectionMethod($subject, '_isRTEField');

        $result = $method->invoke(
            $subject,
            'tt_content:10:header',
            ['config' => ['type' => 'input']],
            ['uid' => 10, 'CType' => 'header']
        );

        self::assertFalse($result);
    }

    #[Test]
    public function translationDetailsFlexFormCallBackIgnoresPathsNotEndingInVDEF(): void
    {
        $subject = $this->createSubject();
        $flexObj = GeneralUtility::makeInstance(FlexFormService::class);
        $method = new \ReflectionMethod($subject, 'translationDetails_flexFormCallBack');

        $method->invoke(
            $subject,
            [],
            'value',
            ['table' => 'tt_content', 'uid' => 10, 'field' => 'pi_flexform'],
            'data/sDEF/lDEF/xmlTitle/vDEFbase',
            $flexObj
        );

        self::assertSame([], $this->readDetailsOutput($subject)['fields'] ?? []);
    }

    #[Test]
    public function translationDetailsFlexFormCallBackAddsTheTranslatedFieldWhenThePathEndsInVDEF(): void
    {
        $subject = $this->createSubject();
        (new \ReflectionProperty($subject, 'detailsOutput'))->setValue($subject, ['ISOcode' => 'de']);
        $flexObj = GeneralUtility::makeInstance(FlexFormService::class);
        $flexObj->traverseFlexFormXMLData_Data = ['data' => ['sDEF' => ['lDEF' => ['xmlTitle' => [
            'vDEF' => 'Original Title',
            'vde' => 'Titel auf Deutsch',
        ]]]]];
        $method = new \ReflectionMethod($subject, 'translationDetails_flexFormCallBack');

        $method->invoke(
            $subject,
            ['TCEforms' => ['config' => ['type' => 'input']]],
            'Original Title',
            ['table' => 'tt_content', 'uid' => 10, 'field' => 'pi_flexform'],
            'data/sDEF/lDEF/xmlTitle/vDEF',
            $flexObj
        );

        $key = 'tt_content:10:pi_flexform:data/sDEF/lDEF/xmlTitle/vde';
        $field = $this->readDetailsOutput($subject)['fields'][$key] ?? null;
        self::assertSame('Original Title', $field['defaultValue'] ?? null);
        self::assertSame('Titel auf Deutsch', $field['translationValue'] ?? null);
    }

    #[Test]
    public function getFlexFormMetaDataForContentElementReturnsTheParsedDataStructureForAFlexField(): void
    {
        $subject = $this->createSubject();
        $method = new \ReflectionMethod($subject, '_getFlexFormMetaDataForContentElement');

        $result = $method->invoke(
            $subject,
            'tt_content',
            'pi_flexform',
            ['uid' => 10, 'pid' => 1, 'CType' => 'header', 'list_type' => '']
        );

        self::assertIsArray($result);
        self::assertArrayHasKey('ROOT', $result['sheets']['sDEF'] ?? []);
    }

    #[Test]
    public function lookForFlexFormFieldAndAddToInternalTranslationDetailsLogsSeparateStopForTheDefaultDataStructure(): void
    {
        $subject = $this->createSubject();
        $method = new \ReflectionMethod($subject, '_lookForFlexFormFieldAndAddToInternalTranslationDetails');

        $method->invoke($subject, 'tt_content', ['uid' => 10, 'pid' => 1, 'CType' => 'header', 'list_type' => '', 'pi_flexform' => '']);

        $log = $this->readDetailsOutput($subject)['log'] ?? [];
        self::assertNotEmpty(array_filter($log, static fn ($entry) => str_contains($entry, 'Separate: Stop')));
    }

    #[Test]
    public function translationDetailsProducesAFieldsEntryForANewTranslationOfAPlainContentField(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $adminUser = self::createStub(BackendUserAuthentication::class);
        $adminUser->method('isAdmin')->willReturn(true);
        $adminUser->workspace = 0;
        $GLOBALS['BE_USER'] = $adminUser;
        $subject = $this->createSubject();

        $row = BackendUtility::getRecordWSOL('tt_content', 20);
        $result = $subject->translationDetails('tt_content', $row, 1);

        $key = 'tt_content:NEW/1/20:header';
        self::assertSame('Untranslated Element', $result['fields'][$key]['defaultValue'] ?? null);

        unset($GLOBALS['BE_USER']);
    }

    private function invokeDetectTranslationModes(
        TranslationDetailsService $subject,
        array $tInfo,
        string $table,
        array $row
    ): array {
        return (new \ReflectionMethod($subject, '_detectTranslationModes'))->invoke($subject, $tInfo, $table, $row);
    }

    #[Test]
    public function translationInfoReturnsAnErrorStringWhenTheTableIsNotConfiguredInTca(): void
    {
        $subject = $this->createSubject();

        $result = $subject->translationInfo('tx_l10nmgr_nonexistent_table', 1);

        self::assertIsString($result);
        self::assertStringContainsString('No table', $result);
    }

    #[Test]
    public function translationInfoReturnsAnErrorStringWhenUidIsZero(): void
    {
        $subject = $this->createSubject();

        $result = $subject->translationInfo('tt_content', 0);

        self::assertIsString($result);
        self::assertStringContainsString('No table', $result);
    }

    #[Test]
    public function translationInfoReturnsAnErrorStringWhenTheRecordDoesNotExist(): void
    {
        $subject = $this->createSubject();

        $result = $subject->translationInfo('tt_content', 999999);

        self::assertIsString($result);
        self::assertStringContainsString('was not found', $result);
    }

    #[Test]
    public function translationInfoReturnsAnErrorStringWhenTheGivenRowIsAlreadyATranslation(): void
    {
        $subject = $this->createSubject();

        $result = $subject->translationInfo(
            'tt_content',
            11,
            0,
            ['pid' => 1, 'sys_language_uid' => 1, 'l18n_parent' => 10]
        );

        self::assertIsString($result);
        self::assertStringContainsString('seems to be a translation already', $result);
        self::assertStringContainsString('language value', $result);
    }

    #[Test]
    public function translationInfoReturnsAnErrorStringWhenTheGivenRowHasAParentPointerWithoutALanguageValue(): void
    {
        $subject = $this->createSubject();

        $result = $subject->translationInfo(
            'tt_content',
            999,
            0,
            ['pid' => 1, 'sys_language_uid' => 0, 'l18n_parent' => 10]
        );

        self::assertIsString($result);
        self::assertStringContainsString('seems to be a translation already', $result);
        self::assertStringContainsString('relation to record "10"', $result);
    }

    #[Test]
    public function translationInfoFindsAnExistingTranslationAndIncludesCTypeForTtContent(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $adminUser = self::createStub(BackendUserAuthentication::class);
        $adminUser->workspace = 0;
        $GLOBALS['BE_USER'] = $adminUser;
        $subject = $this->createSubject();

        $result = $subject->translationInfo('tt_content', 10);

        self::assertIsArray($result);
        self::assertSame('tt_content', $result['table']);
        self::assertSame(10, $result['uid']);
        self::assertSame(11, $result['translations'][1]['uid'] ?? null);
        self::assertArrayHasKey('CType', $result);
        self::assertSame([], $result['excessive_translations']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function translationInfoFiltersToASpecificLanguageWhenSysLanguageUidIsGiven(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $adminUser = self::createStub(BackendUserAuthentication::class);
        $adminUser->workspace = 0;
        $GLOBALS['BE_USER'] = $adminUser;
        $subject = $this->createSubject();

        $matchingResult = $subject->translationInfo('tt_content', 10, 1);
        $nonMatchingResult = $subject->translationInfo('tt_content', 10, 2);

        self::assertSame(11, $matchingResult['translations'][1]['uid'] ?? null);
        self::assertSame([], $nonMatchingResult['translations']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function translationInfoReportsExcessiveTranslationsWhenMoreThanOneRecordSharesTheSameLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_duplicate_translations.csv');
        $adminUser = self::createStub(BackendUserAuthentication::class);
        $adminUser->workspace = 0;
        $GLOBALS['BE_USER'] = $adminUser;
        $subject = $this->createSubject();

        $result = $subject->translationInfo('tt_content', 30);

        self::assertCount(1, $result['translations']);
        self::assertCount(1, $result['excessive_translations'][1] ?? []);
        $foundUids = [$result['translations'][1]['uid'], $result['excessive_translations'][1][0]['uid']];
        sort($foundUids);
        self::assertSame([31, 32], $foundUids);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function translationInfoMatchesAFreeModeRecordDirectlyWhenItsLanguageEqualsThePreviewLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_freemode_translation.csv');
        $adminUser = self::createStub(BackendUserAuthentication::class);
        $adminUser->workspace = 0;
        $GLOBALS['BE_USER'] = $adminUser;
        $subject = $this->createSubject();

        $result = $subject->translationInfo('tt_content', 40, 0, null, '', 2);

        self::assertIsArray($result);
        self::assertSame(40, $result['translations'][2]['uid'] ?? null);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function detectTranslationModesAlwaysIncludesFlexformInternalTranslationForPagesRegardlessOfTInfo(): void
    {
        $subject = $this->createSubject();

        $result = $this->invokeDetectTranslationModes($subject, [], 'pages', []);

        self::assertSame(['flexformInternalTranslation'], $result);
    }

    #[Test]
    public function detectTranslationModesReturnsUseOverlayWhenTranslationsAlreadyExistForANonAllLanguage(): void
    {
        $subject = $this->createSubject();
        $tInfo = ['translations' => [1 => ['uid' => 11]], 'sys_language_uid' => 0, 'translation_table' => 'tt_content'];

        $result = $this->invokeDetectTranslationModes($subject, $tInfo, 'tt_content', ['CType' => 'header']);

        self::assertSame(['useOverlay'], $result);
    }

    #[Test]
    public function detectTranslationModesTreatsSysLanguageUidOfAllAsNotOverlayableEvenWithExistingTranslations(): void
    {
        $subject = $this->createSubject();
        $tInfo = ['translations' => [1 => ['uid' => 11]], 'sys_language_uid' => -1, 'translation_table' => 'tt_content'];

        $result = $this->invokeDetectTranslationModes($subject, $tInfo, 'tt_content', ['CType' => 'header']);

        self::assertSame([], $result);
    }

    #[Test]
    public function detectTranslationModesLogsNoTranslationForATemplavoilaFceInDefaultLanguageWithoutOverlay(): void
    {
        $subject = $this->createSubject();
        $tInfo = ['translations' => [], 'sys_language_uid' => 0];

        $result = $this->invokeDetectTranslationModes($subject, $tInfo, 'tt_content', ['CType' => 'templavoila_pi1']);

        self::assertSame([], $result);
        $log = $this->readDetailsOutput($subject)['log'] ?? [];
        self::assertNotEmpty(array_filter($log, static fn ($entry) => str_contains($entry, 'noTranslation')));
    }

    #[Test]
    public function detectTranslationModesReturnsUseOverlayForAPlainRecordInDefaultLanguageWithNoExistingTranslations(): void
    {
        $subject = $this->createSubject();
        $tInfo = ['translations' => [], 'sys_language_uid' => 0, 'translation_table' => 'tt_content'];

        $result = $this->invokeDetectTranslationModes($subject, $tInfo, 'tt_content', ['CType' => 'header']);

        self::assertSame(['useOverlay'], $result);
    }

    private function setSysLanguages(TranslationDetailsService $subject, array $languages): void
    {
        (new \ReflectionProperty($subject, 'sys_languages'))->setValue($subject, $languages);
    }

    private function invokeGetSingleRecordToTranslate(
        TranslationDetailsService $subject,
        string $table,
        int $uid,
        int $previewLanguage = 0
    ): array|false {
        return (new \ReflectionMethod($subject, 'getSingleRecordToTranslate'))
            ->invoke($subject, $table, $uid, $previewLanguage);
    }

    private function invokeFilterIndex(TranslationDetailsService $subject, string $table, int $uid, int $pageId): bool
    {
        return (new \ReflectionMethod($subject, 'filterIndex'))->invoke($subject, $table, $uid, $pageId);
    }

    private function invokeCompileIndexRecord(
        TranslationDetailsService $subject,
        array $fullDetails,
        int $sysLang,
        int $pid
    ): array {
        return (new \ReflectionMethod($subject, 'compileIndexRecord'))->invoke($subject, $fullDetails, $sysLang, $pid);
    }

    private function setUpAdminBackendUser(): void
    {
        $adminUser = self::createStub(BackendUserAuthentication::class);
        $adminUser->method('isAdmin')->willReturn(true);
        $adminUser->workspace = 0;
        $GLOBALS['BE_USER'] = $adminUser;
    }

    #[Test]
    public function filterIndexReturnsTrueWithoutWarningsWhenNoIndexFilterHookIsConfigured(): void
    {
        $subject = $this->createSubject();
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        }, E_WARNING);

        $result = $this->invokeFilterIndex($subject, 'tt_content', 10, 1);

        restore_error_handler();
        self::assertTrue($result);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function compileIndexRecordSkipsNonArrayBypassStringFieldsEntirely(): void
    {
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();
        $fullDetails = [
            'translationInfo' => ['table' => 'tt_content', 'uid' => 10, 'sys_language_uid' => 0, 'translations' => [1 => ['uid' => 11]]],
            'fields' => ['tt_content:10:subheader' => 'Bypassing; ->filters[l10n_mode] was set to "exclude"'],
        ];

        $result = $this->invokeCompileIndexRecord($subject, $fullDetails, 1, 1);

        self::assertSame(0, $result['flag_new']);
        self::assertSame(0, $result['flag_unknown']);
        self::assertSame(0, $result['flag_noChange']);
        self::assertSame(0, $result['flag_update']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function compileIndexRecordCountsANewFieldForANewlyCreatedTranslationUid(): void
    {
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();
        $fullDetails = [
            'translationInfo' => ['table' => 'tt_content', 'uid' => 20, 'sys_language_uid' => 0, 'translations' => []],
            'fields' => ['tt_content:NEW/1/20:header' => ['defaultValue' => 'Untranslated Element', 'translationValue' => '']],
        ];

        $result = $this->invokeCompileIndexRecord($subject, $fullDetails, 1, 1);

        self::assertSame(1, $result['flag_new']);
        self::assertSame(0, $result['flag_unknown'] + $result['flag_noChange'] + $result['flag_update']);
        self::assertSame('tt_content', $result['tablename']);
        self::assertSame(20, $result['recuid']);
        self::assertSame(md5('tt_content:20:1:0'), $result['hash']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function compileIndexRecordCountsAnUnknownFieldWhenNoDiffDefaultValueIsSet(): void
    {
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();
        $fullDetails = [
            'translationInfo' => ['table' => 'tt_content', 'uid' => 10, 'sys_language_uid' => 0, 'translations' => [1 => ['uid' => 11]]],
            'fields' => ['tt_content:11:header' => ['defaultValue' => 'Parent Element', 'translationValue' => 'German Translation']],
        ];

        $result = $this->invokeCompileIndexRecord($subject, $fullDetails, 1, 1);

        self::assertSame(1, $result['flag_unknown']);
        self::assertSame(0, $result['flag_new'] + $result['flag_noChange'] + $result['flag_update']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function compileIndexRecordCountsANoChangeFieldWhenDiffAndDefaultValuesAreEqual(): void
    {
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();
        $fullDetails = [
            'translationInfo' => ['table' => 'tt_content', 'uid' => 10, 'sys_language_uid' => 0, 'translations' => [1 => ['uid' => 11]]],
            'fields' => ['tt_content:11:header' => ['diffDefaultValue' => 'Same Value', 'defaultValue' => 'Same Value']],
        ];

        $result = $this->invokeCompileIndexRecord($subject, $fullDetails, 1, 1);

        self::assertSame(1, $result['flag_noChange']);
        self::assertSame(0, $result['flag_new'] + $result['flag_unknown'] + $result['flag_update']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function compileIndexRecordCountsAnUpdateFieldAndBuildsADiffWhenValuesDiffer(): void
    {
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();
        $fullDetails = [
            'translationInfo' => ['table' => 'tt_content', 'uid' => 10, 'sys_language_uid' => 0, 'translations' => [1 => ['uid' => 11]]],
            'fields' => ['tt_content:11:header' => ['diffDefaultValue' => 'Old Value', 'defaultValue' => 'New Value']],
        ];

        $result = $this->invokeCompileIndexRecord($subject, $fullDetails, 1, 1);

        self::assertSame(1, $result['flag_update']);
        $diff = json_decode((string)$result['serializedDiff'], true);
        self::assertStringContainsString('Old', $diff['header:'] ?? '');
        self::assertStringContainsString('New', $diff['header:'] ?? '');

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function getSingleRecordToTranslateFindsARecordInTheDefaultLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();

        $result = $this->invokeGetSingleRecordToTranslate($subject, 'tt_content', 10);

        self::assertIsArray($result);
        self::assertSame('Parent Element', $result['header']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function getSingleRecordToTranslateDoesNotMatchARecordThatIsAlreadyATranslation(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();

        self::assertFalse($this->invokeGetSingleRecordToTranslate($subject, 'tt_content', 11));

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function getSingleRecordToTranslateMatchesAFreeModeRecordWhenItsLanguageEqualsThePreviewLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_freemode_translation.csv');
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();

        $result = $this->invokeGetSingleRecordToTranslate($subject, 'tt_content', 40, 2);

        self::assertIsArray($result);
        self::assertSame('Free Mode Content', $result['header']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function getSingleRecordToTranslateDoesNotMatchAFreeModeRecordForADifferentPreviewLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_freemode_translation.csv');
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();

        self::assertFalse($this->invokeGetSingleRecordToTranslate($subject, 'tt_content', 40, 3));

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function getAllowedFieldsForTableReturnsColumnsPresentInBothTcaAndTheDatabaseSchema(): void
    {
        $subject = $this->createSubject();

        $result = $subject->getAllowedFieldsForTable('tt_content');

        self::assertContains('header', $result);
        self::assertNotContains('this_field_does_not_exist_anywhere', $result);
    }

    #[Test]
    public function getAllowedFieldsForTablePopulatesTheRuntimeCacheAfterFirstCall(): void
    {
        $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime');
        $key = 'l10nmgr-allowed-fields-tt_content';
        $cache->remove($key);
        $subject = $this->createSubject();
        self::assertFalse($cache->has($key));

        $subject->getAllowedFieldsForTable('tt_content');

        self::assertTrue($cache->has($key));
    }

    #[Test]
    public function indexDetailsRecordReturnsEmptyWhenTheUserCannotEditTheRecord(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        unset($GLOBALS['BE_USER']);
        $subject = $this->createSubject();
        $subject->bypassFilter = true;

        self::assertSame([], $subject->indexDetailsRecord('tt_content', 10));
    }

    #[Test]
    public function indexDetailsRecordReturnsEmptyWhenNoMatchingRecordExists(): void
    {
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();
        $subject->bypassFilter = true;

        self::assertSame([], $subject->indexDetailsRecord('tt_content', 999999));

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function indexDetailsRecordBuildsFullDetailsAndIndexRecordForEachConfiguredLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();
        $subject->bypassFilter = true;
        $this->setSysLanguages($subject, [
            ['uid' => 0, 'title' => 'Default'],
            ['uid' => 1, 'title' => 'German'],
        ]);

        $items = $subject->indexDetailsRecord('tt_content', 10);

        self::assertArrayHasKey(0, $items['fullDetails']);
        self::assertArrayHasKey(1, $items['fullDetails']);
        self::assertArrayHasKey(0, $items['indexRecord']);
        self::assertArrayHasKey(1, $items['indexRecord']);
        self::assertSame('tt_content', $items['indexRecord'][1]['tablename']);
        self::assertSame(10, $items['indexRecord'][1]['recuid']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function indexDetailsRecordOnlyBuildsTheRequestedLanguageWhenLanguageIdIsGiven(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();
        $subject->bypassFilter = true;
        $this->setSysLanguages($subject, [
            ['uid' => 0, 'title' => 'Default'],
            ['uid' => 1, 'title' => 'German'],
        ]);

        $items = $subject->indexDetailsRecord('tt_content', 10, 1);

        self::assertArrayNotHasKey(0, $items['fullDetails'] ?? []);
        self::assertArrayHasKey(1, $items['fullDetails']);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function indexDetailsRecordProceedsWithoutBypassFilterWhenNoIndexFilterHookIsConfigured(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();
        $this->setSysLanguages($subject, [['uid' => 0, 'title' => 'Default']]);

        $items = $subject->indexDetailsRecord('tt_content', 10);

        self::assertNotEmpty($items);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function updateIndexTableFromDetailsArrayDoesNothingWhenIndexRecordIsEmpty(): void
    {
        $subject = $this->createSubject();

        $subject->updateIndexTableFromDetailsArray([]);
        $subject->updateIndexTableFromDetailsArray(['indexRecord' => []]);

        $count = $this->getConnectionPool()->getQueryBuilderForTable('tx_l10nmgr_index')
            ->count('*')
            ->from('tx_l10nmgr_index')
            ->executeQuery()
            ->fetchOne();
        self::assertSame(0, $count);
    }

    #[Test]
    public function updateIndexTableFromDetailsArrayReplacesAnExistingRowWithTheSameHash(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_index.csv');
        $subject = $this->createSubject();

        $subject->updateIndexTableFromDetailsArray([
            'indexRecord' => [
                1 => [
                    'hash' => 'hash-new-only',
                    'tablename' => 'tt_content',
                    'recuid' => 5,
                    'recpid' => 1,
                    'sys_language_uid' => 0,
                    'translation_lang' => 1,
                    'translation_recuid' => 0,
                    'workspace' => 0,
                    'serializedDiff' => '[]',
                    'flag_new' => 0,
                    'flag_unknown' => 0,
                    'flag_noChange' => 1,
                    'flag_update' => 0,
                ],
            ],
        ]);

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_l10nmgr_index');
        $row = $queryBuilder->select('*')
            ->from('tx_l10nmgr_index')
            ->where($queryBuilder->expr()->eq('hash', $queryBuilder->createNamedParameter('hash-new-only')))
            ->executeQuery()
            ->fetchAssociative();

        self::assertNotFalse($row);
        self::assertSame(0, (int)$row['flag_new']);
        self::assertSame(1, (int)$row['flag_noChange']);
    }

    private function invokeGetParentTables(TranslationDetailsService $subject, string $table, array $row): array
    {
        return (new \ReflectionMethod($subject, 'getParentTables'))->invoke($subject, $table, $row);
    }

    #[Test]
    public function getRecordsToTranslateFromTableReturnsEmptyWhenThePageDoesNotExist(): void
    {
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();

        self::assertSame([], $subject->getRecordsToTranslateFromTable('tt_content', 999999));

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function getRecordsToTranslateFromTableReturnsEmptyWhenTheUserCannotEditThePage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        unset($GLOBALS['BE_USER']);
        $subject = $this->createSubject();

        self::assertSame([], $subject->getRecordsToTranslateFromTable('tt_content', 1));
    }

    #[Test]
    public function getRecordsToTranslateFromTableReturnsOnlyDefaultLanguageRecordsForThePage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();

        $uids = array_column($subject->getRecordsToTranslateFromTable('tt_content', 1), 'uid');

        self::assertContains(10, $uids);
        self::assertContains(20, $uids);
        self::assertNotContains(11, $uids);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function getRecordsToTranslateFromTableExcludesHiddenRecordsOnlyWhenNoHiddenIsRequested(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_extra.csv');
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();

        $withHidden = array_column($subject->getRecordsToTranslateFromTable('tt_content', 1), 'uid');
        $withoutHidden = array_column($subject->getRecordsToTranslateFromTable('tt_content', 1, 0, false, true), 'uid');

        self::assertContains(72, $withHidden);
        self::assertNotContains(72, $withoutHidden);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function getRecordsToTranslateFromTableOrdersResultsBySortColumnWhenSortexportsIsRequested(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_extra.csv');
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();

        $uids = array_column($subject->getRecordsToTranslateFromTable('tt_content', 1, 0, true), 'uid');

        $positionOf71 = array_search(71, $uids, true);
        $positionOf70 = array_search(70, $uids, true);
        self::assertNotFalse($positionOf71);
        self::assertNotFalse($positionOf70);
        self::assertLessThan($positionOf70, $positionOf71);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function getRecordsToTranslateFromTableIncludesAFreeModeRecordMatchingThePreviewLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_freemode_translation.csv');
        $this->setUpAdminBackendUser();
        $subject = $this->createSubject();

        $uids = array_column($subject->getRecordsToTranslateFromTable('tt_content', 1, 2), 'uid');

        self::assertContains(40, $uids);

        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function getParentTablesResolvesToTtContentWhenTheTableIsConfiguredAsAnInlineTable(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['inlineTablesConfig'] = [
            'tx_test_inline_table' => ['parentField' => 'parent_uid'],
        ];
        $subject = $this->createSubject();

        $result = $this->invokeGetParentTables($subject, 'tx_test_inline_table', []);

        self::assertSame(['tt_content', 'parent_uid'], $result);

        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['inlineTablesConfig']);
    }

    #[Test]
    public function getParentTablesResolvesSysFileReferenceUsingItsOwnTablenamesField(): void
    {
        $subject = $this->createSubject();

        $result = $this->invokeGetParentTables($subject, 'sys_file_reference', ['tablenames' => 'tt_content']);

        self::assertSame(['tt_content', 'uid_foreign'], $result);
    }

    #[Test]
    public function getParentTablesReturnsNullPairForATableWithNoParentRelationConfigured(): void
    {
        $subject = $this->createSubject();

        self::assertSame([null, null], $this->invokeGetParentTables($subject, 'tt_content', []));
    }

    #[Test]
    public function isParentItemHiddenBypassesTheHiddenCheckWhenTheResolvedParentTableIsPages(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_hidden.csv');
        $subject = $this->createSubject();

        self::assertFalse($subject->isParentItemHidden('sys_file_reference', ['tablenames' => 'pages', 'uid_foreign' => 90], 0));
    }

    #[Test]
    public function isParentItemHiddenReturnsTrueWhenTheParentRecordDoesNotExist(): void
    {
        $subject = $this->createSubject();

        self::assertTrue($subject->isParentItemHidden('sys_file_reference', ['tablenames' => 'tt_content', 'uid_foreign' => 999999], 0));
    }

    #[Test]
    public function isParentItemHiddenReturnsTrueWhenTheParentRecordIsHidden(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_extra.csv');
        $subject = $this->createSubject();

        self::assertTrue($subject->isParentItemHidden('sys_file_reference', ['tablenames' => 'tt_content', 'uid_foreign' => 72], 0));
    }

    #[Test]
    public function isParentItemHiddenReturnsFalseWhenTheParentRecordExistsAndIsNotHidden(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $subject = $this->createSubject();

        self::assertFalse($subject->isParentItemHidden('sys_file_reference', ['tablenames' => 'tt_content', 'uid_foreign' => 10], 0));
    }

    #[Test]
    public function isParentItemHiddenTerminatesInsteadOfRecursingForeverOnACyclicParentChain(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_cyclic_parents.csv');
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['inlineTablesConfig'] = [
            'tt_content' => ['parentField' => 'l18n_parent'],
        ];
        $subject = $this->createSubject();
        $row = BackendUtility::getRecord('tt_content', 60);

        self::assertFalse($subject->isParentItemHidden('tt_content', $row, 0));

        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['inlineTablesConfig']);
    }

    #[Test]
    public function isParentItemExcludedReturnsTrueWhenTheParentPageRestrictsTheGivenLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_with_restrictions.csv');
        $subject = $this->createSubject();

        self::assertTrue($subject->isParentItemExcluded('sys_file_reference', ['tablenames' => 'pages', 'uid_foreign' => 1], 1));
    }

    #[Test]
    public function isParentItemExcludedReturnsFalseWhenTheParentPageHasNoRestriction(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_with_restrictions.csv');
        $subject = $this->createSubject();

        self::assertFalse($subject->isParentItemExcluded('sys_file_reference', ['tablenames' => 'pages', 'uid_foreign' => 3], 1));
    }

    #[Test]
    public function isParentItemExcludedReturnsTrueWhenTheParentRecordIsDeleted(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_with_restrictions.csv');
        $subject = $this->createSubject();

        self::assertTrue($subject->isParentItemExcluded('sys_file_reference', ['tablenames' => 'pages', 'uid_foreign' => 4], 1));
    }

    #[Test]
    public function isParentItemExcludedIgnoresTheParentsHiddenStateEntirely(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_hidden.csv');
        $subject = $this->createSubject();

        self::assertFalse($subject->isParentItemExcluded('sys_file_reference', ['tablenames' => 'pages', 'uid_foreign' => 90], 0));
    }

    #[Test]
    public function isParentItemExcludedTerminatesInsteadOfRecursingForeverOnACyclicParentChain(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_cyclic_parents.csv');
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['inlineTablesConfig'] = [
            'tt_content' => ['parentField' => 'l18n_parent'],
        ];
        $subject = $this->createSubject();
        $row = BackendUtility::getRecord('tt_content', 60);

        self::assertFalse($subject->isParentItemExcluded('tt_content', $row, 0));

        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['inlineTablesConfig']);
    }
}
