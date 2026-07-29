<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Services\FlexFormService;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers ensureDefaultSheet(), resolveFileDirectives() and flexArray2Xml() - pure/lightweight
 * array-transformation methods with no TCA/database dependency. The bulk of this 846-line class
 * (getDataStructureIdentifier() and its TCA-driven resolution helpers, traverseFlexFormXMLData())
 * needs real TCA type=flex field configurations and record data to exercise meaningfully - deferred
 * as one of the other giant-service refactor targets, alongside TranslationDetailsService/
 * L10nBaseService (see backlog).
 */
class FlexFormServiceTest extends UnitTestCase
{
    private function createSubject(): FlexFormService
    {
        return new FlexFormService($this->createStub(EventDispatcherInterface::class));
    }

    private function invoke(string $method, array $args): mixed
    {
        return (new \ReflectionMethod($this->createSubject(), $method))->invoke($this->createSubject(), ...$args);
    }

    #[Test]
    public function ensureDefaultSheetMovesATopLevelRootUnderSheetsSdef(): void
    {
        $result = $this->invoke('ensureDefaultSheet', [['ROOT' => ['type' => 'array']]]);

        self::assertArrayNotHasKey('ROOT', $result);
        self::assertSame(['type' => 'array'], $result['sheets']['sDEF']['ROOT']);
    }

    #[Test]
    public function ensureDefaultSheetLeavesAnAlreadySheetedStructureUnchanged(): void
    {
        $input = ['sheets' => ['sDEF' => ['ROOT' => ['type' => 'array']]]];

        $result = $this->invoke('ensureDefaultSheet', [$input]);

        self::assertSame($input, $result);
    }

    #[Test]
    public function ensureDefaultSheetThrowsWhenBothRootAndSheetsArePresent(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1440676540);

        $this->invoke('ensureDefaultSheet', [['ROOT' => [], 'sheets' => []]]);
    }

    #[Test]
    public function resolveFileDirectivesLeavesArraySheetsUntouched(): void
    {
        $input = ['sheets' => ['sDEF' => ['ROOT' => ['type' => 'array']]]];

        $result = $this->invoke('resolveFileDirectives', [$input]);

        self::assertSame($input, $result);
    }

    #[Test]
    public function resolveFileDirectivesLeavesANonExistentFileReferenceUnresolved(): void
    {
        $input = ['sheets' => ['sDEF' => 'FILE:EXT:l10nmgr/does/not/exist.xml']];

        $result = $this->invoke('resolveFileDirectives', [$input]);

        self::assertSame('FILE:EXT:l10nmgr/does/not/exist.xml', $result['sheets']['sDEF']);
    }

    #[Test]
    public function flexArray2XmlProducesAWellFormedXmlDocumentWithTheExpectedRootTag(): void
    {
        $subject = $this->createSubject();

        $result = $subject->flexArray2Xml(['data' => ['sDEF' => ['lDEF' => ['field1' => ['vDEF' => 'value']]]]]);

        self::assertStringStartsWith('<?xml version="1.0" encoding="utf-8" standalone="yes" ?>', $result);
        self::assertStringContainsString('<T3FlexForms>', $result);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($result), 'flexArray2Xml() must produce well-formed XML');
    }
}
