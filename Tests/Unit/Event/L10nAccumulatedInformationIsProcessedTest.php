<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Event\L10nAccumulatedInformationIsProcessed;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class L10nAccumulatedInformationIsProcessedTest extends UnitTestCase
{
    #[Test]
    public function getAccumulatedInformationReturnsTheValuePassedToTheConstructor(): void
    {
        $event = new L10nAccumulatedInformationIsProcessed(['some' => 'info'], ['cfg' => 'value']);

        self::assertSame(['some' => 'info'], $event->getAccumulatedInformation());
    }

    #[Test]
    public function getL10nConfigurationReturnsTheValuePassedToTheConstructor(): void
    {
        $event = new L10nAccumulatedInformationIsProcessed(['some' => 'info'], ['cfg' => 'value']);

        self::assertSame(['cfg' => 'value'], $event->getL10nConfiguration());
    }

    #[Test]
    public function setAccumulatedInformationReplacesThePreviousValue(): void
    {
        $event = new L10nAccumulatedInformationIsProcessed(['some' => 'info'], []);

        $event->setAccumulatedInformation(['replaced' => true]);

        self::assertSame(['replaced' => true], $event->getAccumulatedInformation());
    }

    #[Test]
    public function l10nConfigurationHasNoSetterAndStaysImmutableAfterConstruction(): void
    {
        self::assertFalse(
            method_exists(L10nAccumulatedInformationIsProcessed::class, 'setL10nConfiguration'),
            'unlike accumulatedInformation, l10nConfiguration is intentionally read-only on this event'
        );
    }
}
