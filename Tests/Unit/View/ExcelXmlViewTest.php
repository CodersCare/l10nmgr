<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\View\ExcelXmlView;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers renderInternalMessage() only - the one part of ExcelXmlView that doesn't need the full
 * L10nAccumulatedInformation/Site pipeline. render() itself is deferred alongside
 * L10nAccumulatedInformation (see backlog).
 */
class ExcelXmlViewTest extends UnitTestCase
{
    private function createSubject(): ExcelXmlView
    {
        return new class() extends ExcelXmlView {
            public function __construct()
            {
            }
        };
    }

    #[Test]
    public function renderInternalMessageRendersEachMessageAsAThreeRowBlock(): void
    {
        $subject = $this->createSubject();
        (new \ReflectionProperty($subject, 'internalMessages'))->setValue($subject, [
            ['message' => 'Some reason', 'key' => 'tt_content:5'],
        ]);
        $method = new \ReflectionMethod($subject, 'renderInternalMessage');

        $result = $method->invoke($subject);

        self::assertStringContainsString('Some reason', $result);
        self::assertStringContainsString('tt_content:5', $result);
        self::assertSame(3, substr_count($result, '<Row>'));
    }

    #[Test]
    public function renderInternalMessageReturnsEmptyStringWhenThereAreNoMessages(): void
    {
        $subject = $this->createSubject();

        $method = new \ReflectionMethod($subject, 'renderInternalMessage');

        self::assertSame('', $method->invoke($subject));
    }
}
