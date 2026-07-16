<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\TranslationDataFactory;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Characterization test for TranslationDataFactory::getParsedExcelXML() (Classes/Model/TranslationDataFactory.php,
 * around lines 179/186), covering the L10N-016 fix: several array-path reads in this method (e.g.
 * `substr(trim($row['ch']['Cell'][0]['ch']['Data'][0]['values'][0]), 12, -1)`) have no null-guard, unlike
 * sibling call sites elsewhere in the codebase that already guard the equivalent pattern with `?? ''`.
 * Malformed/irregular Excel-XML cells (e.g. an empty Data cell, which real-world exports from
 * OpenOffice/Excel can produce) leave these array paths undefined, triggering PHP 8.1+ deprecations
 * today ("Undefined array key", "Trying to access array offset on null") and a hard TypeError in a
 * future PHP major. This fixture happens to trip the guard at line 186 rather than 179 specifically —
 * same method, same class of bug, same fix scope.
 *
 * Called via reflection since getParsedExcelXML() is protected, and the public
 * getTranslationDataFromExcelXMLFile() wrapper calls die() on a parse failure, which
 * would abort the test process rather than let it assert on the outcome.
 */
class TranslationDataFactoryTest extends UnitTestCase
{
    #[Test]
    public function getParsedExcelXmlHandlesRowWithEmptyDataCellWithoutFatalError(): void
    {
        $factory = new TranslationDataFactory();

        $malformedExcelXml = <<<'XML'
<?xml version="1.0"?>
<Workbook>
<Worksheet>
<Table>
<Row>
<Cell><Data ss:Type="String">[tt_content][1][header]</Data></Cell>
<Cell></Cell>
<Cell></Cell>
<Cell></Cell>
<Cell><Data ss:Type="String"></Data></Cell>
</Row>
</Table>
</Worksheet>
</Workbook>
XML;

        $method = new \ReflectionMethod($factory, 'getParsedExcelXML');
        $result = $method->invoke($factory, $malformedExcelXml);

        self::assertIsArray($result);
    }
}
