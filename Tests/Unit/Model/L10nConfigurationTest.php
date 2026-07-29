<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\L10nConfiguration;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers the getters/isLoaded()/getData() logic that operate purely on the public $l10ncfg array.
 * load(), getL10nAccumulatedInformationsObjectForLanguage() and updateFlexFormDiff() need a real
 * database/backend context and belong in a functional test instead.
 */
class L10nConfigurationTest extends UnitTestCase
{
    protected L10nConfiguration $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new L10nConfiguration();
    }

    #[Test]
    public function isLoadedReturnsFalseForAFreshInstance(): void
    {
        self::assertFalse($this->subject->isLoaded());
    }

    #[Test]
    public function isLoadedReturnsTrueOnceL10ncfgHasData(): void
    {
        $this->subject->l10ncfg = ['uid' => 1];

        self::assertTrue($this->subject->isLoaded());
    }

    #[Test]
    public function gettersReturnDataFromTheL10ncfgArray(): void
    {
        $this->subject->l10ncfg = [
            'uid' => 42,
            'pid' => 7,
            'targetLanguages' => '1,2,3',
            'forcedSourceLanguage' => 1,
            'onlyForcedSourceLanguage' => 1,
            'overrideexistingtranslations' => 1,
            'tablelist' => 'tt_content,pages',
            'title' => 'My config',
            'cruser_id' => 3,
            'filenameprefix' => 'export_',
            'metadata' => 'some metadata',
            'depth' => 2,
            'exclude' => '1,2',
            'include' => '3,4',
        ];

        self::assertSame(42, $this->subject->getUid());
        self::assertSame(7, $this->subject->getPid());
        self::assertSame('1,2,3', $this->subject->getTargetLanguages());
        self::assertSame(1, $this->subject->getForcedSourceLanguage());
        self::assertTrue($this->subject->getOnlyForcedSourceLanguage());
        self::assertTrue($this->subject->overrideExistingTranslations());
        self::assertSame('tt_content,pages', $this->subject->getTableList());
        self::assertSame('My config', $this->subject->getTitle());
        self::assertSame(3, $this->subject->getCrUserId());
        self::assertSame('export_', $this->subject->getFileNamePrefix());
        self::assertSame('some metadata', $this->subject->getMetaData());
        self::assertSame(2, $this->subject->getDepth());
        self::assertSame('1,2', $this->subject->getExclude());
        self::assertSame('3,4', $this->subject->getInclude());
    }

    #[Test]
    public function gettersReturnDefaultsForAnEmptyL10ncfg(): void
    {
        self::assertSame(0, $this->subject->getUid());
        self::assertSame('', $this->subject->getTitle());
        self::assertFalse($this->subject->overrideExistingTranslations());
    }

    #[Test]
    public function getPidReturnsRawPidWhenDepthIsNotMinusOne(): void
    {
        $this->subject->l10ncfg = ['pid' => 7, 'depth' => 0];
        $this->subject->setSourcePid(99);

        self::assertSame(7, $this->subject->getPid());
    }

    #[Test]
    public function getPidReturnsSourcePidOverrideWhenDepthIsMinusOneAndSourcePidIsSet(): void
    {
        $this->subject->l10ncfg = ['pid' => 7, 'depth' => -1];
        $this->subject->setSourcePid(99);

        self::assertSame(99, $this->subject->getPid());
    }

    #[Test]
    public function getPidReturnsRawPidWhenDepthIsMinusOneButSourcePidWasNeverSet(): void
    {
        $this->subject->l10ncfg = ['pid' => 7, 'depth' => -1];

        self::assertSame(7, $this->subject->getPid());
    }

    #[Test]
    public function setSourcePidHasNoEffectWhenDepthIsNotMinusOne(): void
    {
        $this->subject->l10ncfg = ['pid' => 7, 'depth' => 5];
        $this->subject->setSourcePid(99);

        self::assertSame(7, $this->subject->getPid());
    }
}
