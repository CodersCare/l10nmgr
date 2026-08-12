<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\CatXmlImportManager;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers delL10N(), the one CatXmlImportManager method needing a real database (via DataHandler).
 * parseAndCheckXMLFile()/parseAndCheckXMLString() (real PSR-14 event dispatch + file reads) are not
 * covered here either - deferred alongside the other still-open integration pieces.
 */
class CatXmlImportManagerDatabaseTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    private function createSubjectWithHeaderData(array $headerData): CatXmlImportManager
    {
        $subject = new CatXmlImportManager('', 1, '');
        $headerDataProperty = new \ReflectionProperty($subject, 'headerData');
        $headerDataProperty->setValue($subject, $headerData);
        return $subject;
    }

    /**
     * Connection::select()'s underlying QueryBuilder applies TYPO3's default restrictions
     * (including DeletedRestriction), so a soft-deleted row is invisible to it - unlike the raw
     * DB row a "did the delete actually happen" assertion needs to see. Used only where a row is
     * expected to already be soft-deleted by the time this queries it.
     */
    private function fetchTtContentRowIgnoringDefaultRestrictions(int $uid): array|false
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder->select('deleted')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
    }

    #[Test]
    public function delL10NSoftDeletesTheMatchingTranslationRecord(): void
    {
        $subject = $this->createSubjectWithHeaderData(['t3_sysLang' => 1, 't3_workspaceId' => 0]);

        $subject->delL10N(['tt_content:10']);

        $row = $this->fetchTtContentRowIgnoringDefaultRestrictions(11);
        self::assertSame(1, (int)$row['deleted']);
    }

    #[Test]
    public function delL10NLeavesTheSourceRecordUntouched(): void
    {
        $subject = $this->createSubjectWithHeaderData(['t3_sysLang' => 1, 't3_workspaceId' => 0]);

        $subject->delL10N(['tt_content:10']);

        $row = $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->select(['deleted'], 'tt_content', ['uid' => 10])->fetchAssociative();
        self::assertSame(0, (int)$row['deleted']);
    }

    #[Test]
    public function delL10NReturnsTheCountOfProcessedElementsRegardlessOfWhetherAnyRowMatched(): void
    {
        // cmdCount counts iterations over $delL10NData, not actual deletions - a table:uid
        // combination with no matching translation still counts toward the returned total.
        $subject = $this->createSubjectWithHeaderData(['t3_sysLang' => 1, 't3_workspaceId' => 0]);

        $result = $subject->delL10N(['tt_content:10', 'tt_content:999999']);

        self::assertSame(2, $result);
    }

    #[Test]
    public function delL10NDoesNotDeleteTranslationsForADifferentLanguage(): void
    {
        $subject = $this->createSubjectWithHeaderData(['t3_sysLang' => 2, 't3_workspaceId' => 0]);

        $subject->delL10N(['tt_content:10']);

        $row = $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->select(['deleted'], 'tt_content', ['uid' => 11])->fetchAssociative();
        self::assertSame(0, (int)$row['deleted'], 'uid 11 is a language 1 translation, a language-2 cleanup must not touch it');
    }

    #[Test]
    public function delL10NSkipsATableNameThatDoesNotExistInTca(): void
    {
        // A table name taken straight from the uploaded CATXML file's own data - not TCA-backed,
        // so querying it would throw a DBAL error if the guard were missing.
        $subject = $this->createSubjectWithHeaderData(['t3_sysLang' => 1, 't3_workspaceId' => 0]);

        $result = $subject->delL10N(['not_a_real_table:10']);

        self::assertSame(0, $result, 'the skipped element must not count toward the processed total');
    }

    #[Test]
    public function delL10NSkipsARestrictedTableEvenThoughItExistsInTca(): void
    {
        $subject = $this->createSubjectWithHeaderData(['t3_sysLang' => 1, 't3_workspaceId' => 0]);

        $result = $subject->delL10N(['be_users:1']);

        self::assertSame(0, $result, 'be_users is TCA-backed but explicitly restricted, so it must still be skipped');
        $row = $this->getConnectionPool()->getConnectionForTable('be_users')
            ->select(['deleted'], 'be_users', ['uid' => 1])->fetchAssociative();
        self::assertSame(0, (int)($row['deleted'] ?? 0), 'the restricted table must not be touched at all');
    }
}
