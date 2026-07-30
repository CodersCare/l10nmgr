<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Hooks\Tcemain;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers siteRelPath() (needs the extension registered as "loaded", which
 * ExtensionManagementUtility::extPath() only sees in a functional bootstrap) and calcStat() (needs
 * a real tx_l10nmgr_index table plus the backend user's workspace). processDatamap_afterDatabaseOperations()
 * additionally needs DataHandler/BackendUtility record-mutation flows and is deferred to a future,
 * more integration-heavy batch.
 */
class TcemainTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_l10nmgr_index.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['BE_USER']->workspace = 0;
    }

    #[Test]
    public function siteRelPathReturnsAPathRelativeToThePublicWebRoot(): void
    {
        $subject = new Tcemain();
        $method = new \ReflectionMethod($subject, 'siteRelPath');

        $result = $method->invoke($subject, 'l10nmgr');

        self::assertStringContainsString('l10nmgr', $result);
        self::assertStringStartsNotWith('/', $result);
    }

    #[Test]
    public function calcStatReturnsEmptyStringWhenNoIndexRecordsMatch(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 999999], [1]);

        self::assertSame('', $result);
    }

    #[Test]
    public function calcStatReportsAllNewWhenOnlyTheNewFlagIsSet(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 5], [1]);

        self::assertStringContainsString('flags_new.png', $result);
    }

    #[Test]
    public function calcStatReportsUpdateWhenTheUpdateFlagIsSet(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 6], [1]);

        self::assertStringContainsString('flags_update.png', $result);
    }

    #[Test]
    public function calcStatReportsUnknownWhenTheUnknownFlagIsSet(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 7], [1]);

        self::assertStringContainsString('flags_unknown.png', $result);
    }

    #[Test]
    public function calcStatReportsOkWhenOnlyTheNoChangeFlagIsSet(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 8], [1]);

        self::assertStringContainsString('flags_ok.png', $result);
    }

    #[Test]
    public function calcStatIgnoresRecordsFromADifferentWorkspace(): void
    {
        $subject = new Tcemain();

        // hash-other-workspace matches table/recuid/lang but has workspace=3, current BE_USER is 0
        $result = $subject->calcStat(['tt_content', 5], [1]);

        self::assertStringNotContainsString('other-workspace', $result);
        self::assertStringContainsString('flags_new.png', $result, 'only the workspace-0 new-flag record should count');
    }

    #[Test]
    public function calcStatUsesRecpidLookupForThePagesTable(): void
    {
        // recpid=2 is deliberately unique to the pages-table fixture row (hash-pages) in
        // tx_l10nmgr_index.csv - the other rows all share recpid=1, which the "pages" branch would
        // also match, see the finding below.
        $subject = new Tcemain();

        $result = $subject->calcStat(['pages', 2], [1]);

        self::assertStringContainsString('flags_ok.png', $result);
    }

    #[Test]
    public function calcStatForPagesDoesNotLeakRowsFromOtherTablesSharingTheSameRecpid(): void
    {
        // recpid=1 is shared by 4 non-pages fixture rows (new/update/unknown/noChange all on
        // tt_content). Asking for calcStat(['pages', 1], [1]) must not pick any of them up - the
        // "pages" branch now filters by tablename='pages' AND recpid, matching the non-pages
        // branch's tablename+recuid filtering pattern.
        $subject = new Tcemain();

        $result = $subject->calcStat(['pages', 1], [1]);

        self::assertSame('', $result, 'no pages-table row has recpid=1, so nothing should match');
    }

    #[Test]
    public function calcStatWithNoLinkReturnsTheBareImageTagWithoutAnAnchor(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 5], [1], true);

        self::assertStringNotContainsString('<a href', $result);
        self::assertStringContainsString('<img', $result);
    }

    #[Test]
    public function calcStatWithoutNoLinkWrapsTheImageInAnAnchor(): void
    {
        $subject = new Tcemain();

        $result = $subject->calcStat(['tt_content', 5], [1], false);

        self::assertStringContainsString('<a href', $result);
    }
}
