<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\TranslationDataFactory;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Characterization test for TranslationDataFactory::getParsedExcelXML() (Classes/Model/TranslationDataFactory.php,
 * around lines 179/186): several array-path reads in this method (e.g.
 * `substr(trim($row['ch']['Cell'][0]['ch']['Data'][0]['values'][0]), 12, -1)`) are null-guarded,
 * matching sibling call sites elsewhere in the codebase that guard the equivalent pattern with `?? ''`.
 * Malformed/irregular Excel-XML cells (e.g. an empty Data cell, which real-world exports from
 * OpenOffice/Excel can produce) leave these array paths undefined, triggering PHP 8.1+ deprecations
 * ("Undefined array key", "Trying to access array offset on null") and a hard TypeError in a future
 * PHP major. Cell 0's content is a deliberately padded reference string (12 filler chars + real
 * content + 1 trailing filler char) so `substr(..., 12, -1)` yields exactly the 3-part
 * "table][uid][key" shape the surrounding `explode('][', ...)` expects — this test targets the
 * empty-Data-cell guard specifically, not the reference-cell parsing, which needs a well-formed
 * cell to avoid tripping an unrelated warning.
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
<Cell><Data ss:Type="String">REFPREFIX123tt_content][1][headerX</Data></Cell>
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
        self::assertSame('', $result['tt_content']['1']['header'] ?? null, 'an empty Data cell should parse to an empty string, not null/undefined');
    }
}
