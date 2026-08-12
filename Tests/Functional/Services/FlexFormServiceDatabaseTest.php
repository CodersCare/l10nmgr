<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Services\FlexFormService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers getDefaultStructureForIdentifier()'s "record" type branch, the one part of the
 * parseDataStructureByIdentifier() cluster that needs a real database fetch - see
 * Tests/Unit/Services/FlexFormServiceTest.php for the rest of that cluster.
 */
class FlexFormServiceDatabaseTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['scheduler'];

    protected array $testExtensionsToLoad = ['localizationteam/l10nmgr'];

    private function createSubject(): FlexFormService
    {
        return GeneralUtility::makeInstance(FlexFormService::class);
    }

    #[Test]
    public function getDefaultStructureForIdentifierFetchesTheFieldValueOfTheReferencedRecordForARecordTypeIdentifier(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_translations.csv');
        $subject = $this->createSubject();
        $identifier = ['type' => 'record', 'tableName' => 'tt_content', 'uid' => 10, 'fieldName' => 'header'];

        $result = (new \ReflectionMethod($subject, 'getDefaultStructureForIdentifier'))->invoke($subject, $identifier);

        self::assertSame('Parent Element', $result);
    }
}
