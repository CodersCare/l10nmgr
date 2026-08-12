<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\TranslationData;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class TranslationDataTest extends UnitTestCase
{
    protected TranslationData $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new TranslationData();
    }

    #[Test]
    public function getTranslationDataReturnsPreviouslySetData(): void
    {
        $data = ['tt_content' => ['1' => ['header' => 'Hello']]];
        $this->subject->setTranslationData($data);

        self::assertSame($data, $this->subject->getTranslationData());
    }

    #[Test]
    public function getTranslationDataReturnsEmptyArrayByDefault(): void
    {
        self::assertSame([], $this->subject->getTranslationData());
    }

    #[Test]
    public function getTranslationDataReturnsByReferenceSoCallerMutationsPersist(): void
    {
        $this->subject->setTranslationData(['tt_content' => ['1' => ['header' => 'Original']]]);

        $reference = &$this->subject->getTranslationData();
        $reference['tt_content']['1']['header'] = 'Mutated';

        self::assertSame('Mutated', $this->subject->getTranslationData()['tt_content']['1']['header']);
    }

    #[Test]
    public function getLanguageReturnsPreviouslySetLanguage(): void
    {
        $this->subject->setLanguage(3);

        self::assertSame(3, $this->subject->getLanguage());
    }

    #[Test]
    public function getPreviewLanguageReturnsPreviouslySetPreviewLanguage(): void
    {
        $this->subject->setPreviewLanguage(2);

        self::assertSame(2, $this->subject->getPreviewLanguage());
    }
}
