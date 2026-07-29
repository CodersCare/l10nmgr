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
    public function calcStatForPagesMatchesByRecpidAloneWithNoTablenameFilter(): void
    {
        // Characterization of a real, currently-live quirk (found while writing this test, not
        // introduced by it - not fixed here, out of scope for a coverage pass): unlike the
        // non-pages branch (which filters by tablename AND recuid), the "$p[0] === 'pages'" branch
        // only filters by recpid - it never restricts to tablename='pages'. So any tx_l10nmgr_index
        // row for ANY table sharing that recpid is included in a "pages" stat lookup. Here, recpid=1
        // is shared by 4 non-pages fixture rows (new/update/unknown/noChange all on tt_content), and
        // asking for calcStat(['pages', 1], [1]) picks all of them up rather than being pages-only.
        $subject = new Tcemain();

        $result = $subject->calcStat(['pages', 1], [1]);

        self::assertStringContainsString('flags_update.png', $result, 'the tt_content rows sharing recpid=1 leak into a "pages" lookup');
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
