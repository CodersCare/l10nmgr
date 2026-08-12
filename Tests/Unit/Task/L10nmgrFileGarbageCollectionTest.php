<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Task\L10nmgrFileGarbageCollection;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers only cleanUpDirectory(), the actual file-selection logic, via reflection. execute() itself
 * additionally needs a working Context 'date' aspect and JobsPathUtility-resolved real target
 * directories, which belongs in a functional test.
 *
 * Real file ctimes cannot be backdated portably from a unit test (touch() only controls mtime, and
 * touching a file's metadata itself updates ctime to "now"), so these tests hold the file's real
 * ctime fixed and vary the reference $timestamp instead - equivalent coverage of the same
 * `getCTime() < $timestamp` comparison without needing to fake file age.
 */
class L10nmgrFileGarbageCollectionTest extends UnitTestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        // GeneralUtility::getFileAbsFileName(), used internally by cleanUpDirectory(), refuses paths
        // outside the TYPO3 project root and silently returns '' for them - sys_get_temp_dir() does
        // not qualify, so the test directory has to live under the public path instead.
        $this->testDir = Environment::getPublicPath() . '/typo3temp/var/tests/l10nmgr-garbagecollection-' . uniqid() . '/';
        GeneralUtility::mkdir($this->testDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            GeneralUtility::rmdir($this->testDir, true);
        }
        parent::tearDown();
    }

    private function invokeCleanUpDirectory(L10nmgrFileGarbageCollection $subject, string $directory, int $timestamp): bool
    {
        $method = new \ReflectionMethod($subject, 'cleanUpDirectory');
        return $method->invoke($subject, $directory, $timestamp);
    }

    /**
     * AbstractTask::__construct() calls GeneralUtility::makeInstance(Scheduler::class), which needs
     * a booted DI container unavailable in a plain unit test. cleanUpDirectory() never touches the
     * scheduler/execution properties that constructor sets up, so bypassing it entirely is safe -
     * property defaults (age = 30, excludePattern = the default regex) are still applied by PHP
     * regardless of whether the constructor runs.
     */
    private function createTaskWithoutConstructor(): L10nmgrFileGarbageCollection
    {
        return (new \ReflectionClass(L10nmgrFileGarbageCollection::class))->newInstanceWithoutConstructor();
    }

    #[Test]
    public function cleanUpDirectoryThrowsWhenTheDirectoryDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1323272107);

        $this->invokeCleanUpDirectory($this->createTaskWithoutConstructor(), $this->testDir . 'does-not-exist/', time());
    }

    #[Test]
    public function cleanUpDirectoryDeletesFilesOlderThanTheReferenceTimestamp(): void
    {
        file_put_contents($this->testDir . 'old.txt', 'content');

        // The file's real ctime is "now"; using a far-future reference timestamp makes it "old"
        // relative to that reference, without needing to fake the file's actual ctime.
        $result = $this->invokeCleanUpDirectory($this->createTaskWithoutConstructor(), $this->testDir, time() + 1_000_000);

        self::assertTrue($result);
        self::assertFileDoesNotExist($this->testDir . 'old.txt');
    }

    #[Test]
    public function cleanUpDirectoryKeepsFilesNotYetOlderThanTheReferenceTimestamp(): void
    {
        file_put_contents($this->testDir . 'recent.txt', 'content');

        $result = $this->invokeCleanUpDirectory($this->createTaskWithoutConstructor(), $this->testDir, time() - 1_000_000);

        self::assertTrue($result);
        self::assertFileExists($this->testDir . 'recent.txt');
    }

    #[Test]
    public function cleanUpDirectoryKeepsFilesMatchingTheExcludePatternEvenWhenOld(): void
    {
        file_put_contents($this->testDir . 'index.html', 'content');
        file_put_contents($this->testDir . '.htaccess', 'content');

        $subject = $this->createTaskWithoutConstructor();
        $result = $this->invokeCleanUpDirectory($subject, $this->testDir, time() + 1_000_000);

        self::assertTrue($result);
        self::assertFileExists($this->testDir . 'index.html');
        self::assertFileExists($this->testDir . '.htaccess');
    }

    #[Test]
    public function cleanUpDirectoryHonorsACustomExcludePattern(): void
    {
        file_put_contents($this->testDir . 'keep-me.log', 'content');
        file_put_contents($this->testDir . 'delete-me.log', 'content');

        $subject = $this->createTaskWithoutConstructor();
        $subject->excludePattern = 'keep-me';
        $this->invokeCleanUpDirectory($subject, $this->testDir, time() + 1_000_000);

        self::assertFileExists($this->testDir . 'keep-me.log');
        self::assertFileDoesNotExist($this->testDir . 'delete-me.log');
    }
}
