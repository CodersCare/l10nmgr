<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Model\TranslationData;
use Localizationteam\L10nmgr\Services\L10nBaseService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $this->setUpBackendUser(1);
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
}
