<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Model\TranslationData;
use Localizationteam\L10nmgr\Services\L10nBaseService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class L10nBaseServiceDatabaseTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    private function createSubject(): L10nBaseService
    {
        return GeneralUtility::makeInstance(L10nBaseService::class, new EmConfiguration(['enable_ftp' => 0]));
    }

    private function invokeRemap(L10nBaseService $subject, L10nConfiguration $l10ncfgObj, TranslationData $translationData): void
    {
        (new \ReflectionMethod($subject, 'remapInputDataForExistingTranslations'))
            ->invoke($subject, $l10ncfgObj, $translationData);
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeSubmitAsDefaultLanguage(L10nBaseService $subject, array $accum, array $inputArray): array
    {
        return (new \ReflectionMethod($subject, '_submitContentAsDefaultLanguageAndGetFlexFormDiff'))
            ->invoke($subject, $accum, $inputArray);
    }

    private function fetchTtContentField(int $uid, string $field): mixed
    {
        return $this->fetchField('tt_content', $uid, $field);
    }

    private function fetchField(string $table, int $uid, string $field): mixed
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        return $queryBuilder->select($field)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    private function countTtContentOnPage(int $pid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        return (int)$queryBuilder->count('uid')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeSubmitAsTranslatedLanguage(L10nBaseService $subject, array $accum, array $inputArray): array
    {
        return (new \ReflectionMethod($subject, '_submitContentAsTranslatedLanguageAndGetFlexFormDiff'))
            ->invoke($subject, $accum, $inputArray);
    }

    private function invokeGetRawRecord(L10nBaseService $subject, string $table, int $uid): array
    {
        return (new \ReflectionMethod($subject, 'getRawRecord'))->invoke($subject, $table, $uid);
    }

    private function invokeRecursivelyCheckForRelationParents(
        L10nBaseService $subject,
        array $element,
        int $Tlang,
        string $parentField,
        string $childrenField
    ): void {
        (new \ReflectionMethod($subject, 'recursivelyCheckForRelationParents'))
            ->invoke($subject, $element, $Tlang, $parentField, $childrenField);
    }

    private function readTceMainCmd(L10nBaseService $subject): array
    {
        return (new \ReflectionProperty($subject, 'TCEmain_cmd'))->getValue($subject);
    }

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
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

    #[Test]
    public function rewritesNewFieldKeyToTheRealUidOfAnAlreadyExistingTranslation(): void
    {
        $subject = $this->createSubject();
        $l10ncfgObj = self::createStub(L10nConfiguration::class);
        $l10ncfgObj->method('overrideExistingTranslations')->willReturn(true);
        $translationData = new TranslationData();
        $translationData->setTranslationData([
            'tt_content' => [10 => ['tt_content:NEW/1/10:header' => 'German Translation']],
        ]);

        $this->invokeRemap($subject, $l10ncfgObj, $translationData);

        self::assertSame(
            ['tt_content' => [10 => ['tt_content:11:header' => 'German Translation']]],
            $translationData->getTranslationData()
        );
    }

    #[Test]
    public function leavesNewFieldKeyUnchangedWhenNoTranslationExistsYet(): void
    {
        $subject = $this->createSubject();
        $l10ncfgObj = self::createStub(L10nConfiguration::class);
        $l10ncfgObj->method('overrideExistingTranslations')->willReturn(true);
        $translationData = new TranslationData();
        $inputData = ['tt_content' => [10 => ['tt_content:NEW/2/10:header' => 'French Translation']]];
        $translationData->setTranslationData($inputData);

        $this->invokeRemap($subject, $l10ncfgObj, $translationData);

        self::assertSame($inputData, $translationData->getTranslationData());
    }

    #[Test]
    public function submitContentAsDefaultLanguageWritesAPlainFieldValueToTheRecord(): void
    {
        $subject = $this->createSubject();
        $accum = [1 => ['items' => ['tt_content' => [10 => [
            'fields' => ['tt_content:10/0:header' => ['defaultValue' => 'Parent Element']],
        ]]]]];
        $inputArray = ['tt_content' => [10 => ['tt_content:10/0:header' => 'Updated Header']]];

        $result = $this->invokeSubmitAsDefaultLanguage($subject, $accum, $inputArray);

        self::assertSame([], $result, 'no FlexForm field was involved, so the diff array must stay empty');
        self::assertSame('Updated Header', $this->fetchTtContentField(10, 'header'));
    }

    #[Test]
    public function submitContentAsDefaultLanguageSkipsFieldsBelongingToRestrictedTables(): void
    {
        $subject = $this->createSubject();
        $accum = [1 => ['items' => ['be_users' => [1 => [
            'fields' => ['be_users:1/0:username' => ['defaultValue' => 'admin']],
        ]]]]];
        $inputArray = ['be_users' => [1 => ['be_users:1/0:username' => 'HACKED']]];

        $result = $this->invokeSubmitAsDefaultLanguage($subject, $accum, $inputArray);

        self::assertSame([], $result);
        self::assertSame(
            'admin',
            $this->fetchField('be_users', 1, 'username'),
            'be_users is in RESTRICTED_TABLES, so the field must never reach DataHandler'
        );
    }

    #[Test]
    public function submitContentAsDefaultLanguageDoesNotCreateNewRecordsFromOnlyEmptyNonLabelFields(): void
    {
        $subject = $this->createSubject();
        $countBefore = $this->countTtContentOnPage(1);
        $accum = [1 => ['items' => ['tt_content' => ['NEW1' => [
            'fields' => ['tt_content:NEW/0:bodytext' => ['defaultValue' => '']],
        ]]]]];
        $inputArray = ['tt_content' => ['NEW1' => ['tt_content:NEW/0:bodytext' => '']]];

        $result = $this->invokeSubmitAsDefaultLanguage($subject, $accum, $inputArray);

        self::assertSame([], $result);
        self::assertSame(
            $countBefore,
            $this->countTtContentOnPage(1),
            'an empty value for a NEW, non-label field must not create a new content element'
        );
    }

    #[Test]
    public function submitContentAsDefaultLanguagePopulatesTheFlexFormDiffArrayForFlexFormFields(): void
    {
        $subject = $this->createSubject();
        $key = 'tt_content:10/0:pi_flexform:data/sDEF/lDEF/xmlTitle/vDEF';
        $accum = [1 => ['items' => ['tt_content' => [10 => [
            'fields' => [$key => ['defaultValue' => 'Old Title']],
        ]]]]];
        $inputArray = ['tt_content' => [10 => [$key => 'New Title']]];

        $result = $this->invokeSubmitAsDefaultLanguage($subject, $accum, $inputArray);

        self::assertSame(['translated' => 'New Title', 'default' => 'Old Title'], $result[$key] ?? null);
        self::assertStringContainsString('New Title', (string)$this->fetchTtContentField(10, 'pi_flexform'));
    }

    #[Test]
    public function submitContentAsTranslatedLanguageLocalizesTheSourceRecordAndWritesTheTranslatedFieldValue(): void
    {
        $this->writeTwoLanguageSite();
        $subject = $this->createSubject();
        $accum = [1 => ['items' => ['tt_content' => [20 => [
            'fields' => ['tt_content:NEW/1/20:header' => ['defaultValue' => 'Untranslated Element']],
        ]]]]];
        $inputArray = ['tt_content' => [20 => ['tt_content:NEW/1/20:header' => 'German Header']]];

        $result = $this->invokeSubmitAsTranslatedLanguage($subject, $accum, $inputArray);

        self::assertSame([], $result, 'no FlexForm field was involved, so the diff array must stay empty');
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $translated = $queryBuilder->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('l18n_parent', $queryBuilder->createNamedParameter(20, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();
        self::assertNotFalse($translated, 'DataHandler must have created a new localized record for uid=20');
        self::assertSame('German Header', $translated['header']);
    }

    #[Test]
    public function submitContentAsTranslatedLanguageClearsSlugFieldsForNewTranslations(): void
    {
        $this->writeTwoLanguageSite();
        $subject = $this->createSubject();
        $accum = [1 => ['items' => ['pages' => [2 => [
            'fields' => ['pages:NEW/1/2:slug' => ['defaultValue' => '/subpage']],
        ]]]]];
        $inputArray = ['pages' => [2 => ['pages:NEW/1/2:slug' => '/unterseite']]];

        $result = $this->invokeSubmitAsTranslatedLanguage($subject, $accum, $inputArray);

        self::assertSame([], $result);
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $translated = $queryBuilder->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter(2, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();
        self::assertNotFalse($translated, 'DataHandler must have created a new localized page for uid=2');
        self::assertNotSame(
            '/unterseite',
            $translated['slug'],
            'the incoming slug value must be dropped, not written verbatim, so TYPO3 can auto-generate it'
        );
    }

    #[Test]
    public function getRawRecordReturnsTheRawDatabaseRowForAnExistingUid(): void
    {
        $subject = $this->createSubject();

        $row = $this->invokeGetRawRecord($subject, 'tt_content', 10);

        self::assertSame('Parent Element', $row['header']);
    }

    #[Test]
    public function getRawRecordReturnsAnEmptyArrayForANonExistentUid(): void
    {
        $subject = $this->createSubject();

        self::assertSame([], $this->invokeGetRawRecord($subject, 'tt_content', 999999));
    }

    #[Test]
    public function recursivelyCheckForRelationParentsQueuesAnInlineLocalizeSynchronizeCommandWhenTheParentIsAlreadyTranslated(): void
    {
        // uid=11 is the existing German (sys_language_uid=1) translation of uid=10 in
        // tt_content_translations.csv - the method must find it via l18n_parent and attach the
        // element (uid=99, a synthetic element that need not exist in the DB) to it.
        $subject = $this->createSubject();
        $element = ['uid' => 99, 'container_field' => 10];

        $this->invokeRecursivelyCheckForRelationParents($subject, $element, 1, 'container_field', 'children_field');

        self::assertSame(
            ['field' => 'children_field', 'language' => 1, 'action' => 'localize', 'ids' => [99]],
            $this->readTceMainCmd($subject)['tt_content'][11]['inlineLocalizeSynchronize'] ?? null
        );
    }

    #[Test]
    public function recursivelyCheckForRelationParentsWalksUpToTheParentRecordAndLocalizesItDirectlyWhenNoTranslationExistsYet(): void
    {
        // Language 2 (French) has no translation of uid=10 in the fixture, so the lookup for an
        // already-translated parent fails; the method must walk up to uid=10's own raw record via
        // getRawRecord() and, finding no further parent pointer there, queue a direct "localize" on it.
        $subject = $this->createSubject();
        $element = ['uid' => 99, 'container_field' => 10];

        $this->invokeRecursivelyCheckForRelationParents($subject, $element, 2, 'container_field', 'children_field');

        self::assertSame(2, $this->readTceMainCmd($subject)['tt_content'][10]['localize'] ?? null);
    }
}
