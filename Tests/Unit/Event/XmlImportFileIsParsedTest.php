<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Event\XmlImportFileIsParsed;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class XmlImportFileIsParsedTest extends UnitTestCase
{
    #[Test]
    public function getXmlNodesReturnsTheValuePassedToTheConstructor(): void
    {
        $event = new XmlImportFileIsParsed(['node1', 'node2'], []);

        self::assertSame(['node1', 'node2'], $event->getXmlNodes());
    }

    #[Test]
    public function getErrorMessagesReturnsTheValuePassedToTheConstructor(): void
    {
        $event = new XmlImportFileIsParsed([], ['initial error']);

        self::assertSame(['initial error'], $event->getErrorMessages());
    }

    #[Test]
    public function addErrorMessageAppendsToTheExistingList(): void
    {
        $event = new XmlImportFileIsParsed([], ['first']);

        $event->addErrorMessage('second');

        self::assertSame(['first', 'second'], $event->getErrorMessages());
    }

    #[Test]
    public function setXmlNodesReplacesThePreviousValue(): void
    {
        $event = new XmlImportFileIsParsed(['old'], []);

        $event->setXmlNodes(['new']);

        self::assertSame(['new'], $event->getXmlNodes());
    }
}
