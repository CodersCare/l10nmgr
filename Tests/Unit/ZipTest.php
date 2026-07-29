<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Zip;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class ZipTest extends UnitTestCase
{
    /**
     * @var string[] Temp directories created by extractFile() during a test, removed in tearDown().
     */
    private array $tempDirsToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirsToClean as $tempDir) {
            if (is_dir($tempDir)) {
                GeneralUtility::rmdir($tempDir, true);
            }
        }
        $this->tempDirsToClean = [];
        parent::tearDown();
    }

    #[Test]
    public function fileProducesAnArchiveThatCanBeReadBackWithTheOriginalContent(): void
    {
        $subject = new Zip();
        $subject->addFile('Hello World', 'greeting.txt');

        $archivePath = $this->writeArchiveToTempFile($subject->file());

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archivePath));
        self::assertSame('Hello World', $zip->getFromName('greeting.txt'));
        $zip->close();

        unlink($archivePath);
    }

    #[Test]
    public function fileProducesAnArchiveContainingMultipleFilesWithTheirOwnContent(): void
    {
        $subject = new Zip();
        $subject->addFile('Content A', 'a.txt');
        $subject->addFile('Content B', 'sub/b.txt');

        $archivePath = $this->writeArchiveToTempFile($subject->file());

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archivePath));
        self::assertSame(2, $zip->numFiles);
        self::assertSame('Content A', $zip->getFromName('a.txt'));
        self::assertSame('Content B', $zip->getFromName('sub/b.txt'));
        $zip->close();

        unlink($archivePath);
    }

    #[Test]
    public function addFileNormalizesBackslashesInTheEntryNameToForwardSlashes(): void
    {
        $subject = new Zip();
        $subject->addFile('Content', 'sub\\dir\\file.txt');

        $archivePath = $this->writeArchiveToTempFile($subject->file());

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archivePath));
        self::assertSame('Content', $zip->getFromName('sub/dir/file.txt'));
        $zip->close();

        unlink($archivePath);
    }

    #[Test]
    public function unix2DosTimeClampsAnyDateBefore1980ToThe1980EpochStart(): void
    {
        $subject = new Zip();
        $method = new \ReflectionMethod($subject, 'unix2DosTime');

        // 100000 seconds after the Unix epoch (1970) is well before the DOS/ZIP format's 1980 floor
        $result = $method->invoke($subject, 100000);

        self::assertSame((0 << 25) | (1 << 21) | (1 << 16), $result);
    }

    #[Test]
    public function unix2DosTimeEncodesAKnownDateAccordingToTheDocumentedBitLayout(): void
    {
        $subject = new Zip();
        $method = new \ReflectionMethod($subject, 'unix2DosTime');

        $timestamp = mktime(12, 30, 0, 1, 15, 2024);
        $timearray = getdate($timestamp);

        $expected = (($timearray['year'] - 1980) << 25)
            | ($timearray['mon'] << 21)
            | ($timearray['mday'] << 16)
            | ($timearray['hours'] << 11)
            | ($timearray['minutes'] << 5)
            | ($timearray['seconds'] >> 1);

        self::assertSame($expected, $method->invoke($subject, $timestamp));
    }

    #[Test]
    public function extractFileReturnsAnErrorStringWhenTheSourceFileDoesNotExist(): void
    {
        $subject = new Zip();

        $result = $subject->extractFile('/no/such/file.zip');

        self::assertSame('No file: /no/such/file.zip', $result);
    }

    #[Test]
    public function extractFileReturnsAnErrorStringWhenTheSourceFileIsNotAValidArchive(): void
    {
        $garbageFile = tempnam(sys_get_temp_dir(), 'l10nmgr-ziptest-garbage-');
        file_put_contents($garbageFile, 'this is not a zip archive');

        $subject = new Zip();
        $result = $subject->extractFile($garbageFile);

        unlink($garbageFile);

        self::assertSame('Could not open archive: ' . $garbageFile, $result);
    }

    #[Test]
    public function extractFileExtractsAllArchiveContentsIntoANewTempDirUnderTypo3Temp(): void
    {
        $subject = new Zip();
        $subject->addFile('Hello World', 'greeting.txt');
        $archivePath = $this->writeArchiveToTempFile($subject->file());

        $result = $subject->extractFile($archivePath);
        unlink($archivePath);

        self::assertIsArray($result);
        $this->tempDirsToClean[] = $result['tempDir'];

        self::assertStringStartsWith(Environment::getPublicPath() . '/typo3temp/', $result['tempDir']);
        self::assertFileExists($result['tempDir'] . 'greeting.txt');
        self::assertSame('Hello World', file_get_contents($result['tempDir'] . 'greeting.txt'));
    }

    #[Test]
    public function removeDirDeletesTheExtractedTempDirectoryAndItsContents(): void
    {
        $subject = new Zip();
        $subject->addFile('Hello World', 'greeting.txt');
        $archivePath = $this->writeArchiveToTempFile($subject->file());
        $extracted = $subject->extractFile($archivePath);
        unlink($archivePath);
        self::assertIsArray($extracted);

        $subject->removeDir($extracted['tempDir']);

        self::assertDirectoryDoesNotExist(rtrim($extracted['tempDir'], '/'));
    }

    private function writeArchiveToTempFile(string $archiveContent): string
    {
        $path = tempnam(sys_get_temp_dir(), 'l10nmgr-ziptest-');
        file_put_contents($path, $archiveContent);
        return $path;
    }
}
