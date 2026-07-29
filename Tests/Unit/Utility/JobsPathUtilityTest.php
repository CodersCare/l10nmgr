<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Utility\JobsPathUtility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class JobsPathUtilityTest extends UnitTestCase
{
    /**
     * Relative to Environment::getPublicPath() - JobsPathUtility::resolvePath() always joins
     * the configured baseFileStoragePath onto the public path, so this is the only location a
     * unit test can safely redirect it to without touching real project directories.
     */
    private const string TEST_BASE_PATH = 'typo3temp/var/tests/l10nmgr-jobspathutility/';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['l10nmgr']['baseFileStoragePath'] = self::TEST_BASE_PATH;
    }

    protected function tearDown(): void
    {
        $absoluteTestBasePath = Environment::getPublicPath() . '/' . self::TEST_BASE_PATH;
        if (is_dir($absoluteTestBasePath)) {
            GeneralUtility::rmdir($absoluteTestBasePath, true);
        }
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['l10nmgr']['baseFileStoragePath']);
        parent::tearDown();
    }

    #[Test]
    public function resolvePathJoinsConfiguredBaseFileStoragePathWithSubPath(): void
    {
        $resolved = JobsPathUtility::resolvePath('jobs/out/foo.xml');

        self::assertSame(
            Environment::getPublicPath() . '/' . self::TEST_BASE_PATH . 'jobs/out/foo.xml',
            $resolved
        );
    }

    #[Test]
    public function resolvePathCreatesTheConfiguredBaseDirectoryIfMissing(): void
    {
        $absoluteTestBasePath = rtrim(Environment::getPublicPath() . '/' . self::TEST_BASE_PATH, '/');
        self::assertDirectoryDoesNotExist($absoluteTestBasePath);

        JobsPathUtility::resolvePath('jobs/out/foo.xml');

        self::assertDirectoryExists($absoluteTestBasePath);
    }

    #[Test]
    public function resolvePathDoesNotProduceDoubleSlashesForSlashPaddedInput(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['l10nmgr']['baseFileStoragePath'] = '/' . self::TEST_BASE_PATH;

        $resolved = JobsPathUtility::resolvePath('/jobs/out/foo.xml');

        self::assertSame(
            Environment::getPublicPath() . '/' . self::TEST_BASE_PATH . 'jobs/out/foo.xml',
            $resolved
        );
    }
}
