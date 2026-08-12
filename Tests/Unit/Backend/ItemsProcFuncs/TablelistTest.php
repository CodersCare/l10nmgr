<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Backend\ItemsProcFuncs\Tablelist;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Hooks\TcaItemsProcessorFunctions;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class TablelistTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    #[Test]
    public function populateAvailableTablesKeepsOnlyTablesWithALanguageField(): void
    {
        $GLOBALS['TCA']['tt_content']['ctrl']['languageField'] = 'sys_language_uid';
        $GLOBALS['TCA']['be_users']['ctrl'] = [];

        $tcaItemsProcessor = self::createStub(TcaItemsProcessorFunctions::class);
        $tcaItemsProcessor->method('populateAvailableTables')->willReturnCallback(
            static function (array &$params): void {
                $params['items'] = [
                    ['label' => 'Content', 'value' => 'tt_content'],
                    ['label' => 'Backend users', 'value' => 'be_users'],
                ];
            }
        );

        $subject = new Tablelist($tcaItemsProcessor);
        $params = ['items' => []];
        $subject->populateAvailableTables($params);

        self::assertSame([['label' => 'Content', 'value' => 'tt_content']], $params['items']);
    }

    #[Test]
    public function populateAvailableTablesDropsItemsWithAnEmptyValue(): void
    {
        $tcaItemsProcessor = self::createStub(TcaItemsProcessorFunctions::class);
        $tcaItemsProcessor->method('populateAvailableTables')->willReturnCallback(
            static function (array &$params): void {
                $params['items'] = [
                    ['label' => 'Empty', 'value' => ''],
                ];
            }
        );

        $subject = new Tablelist($tcaItemsProcessor);
        $params = ['items' => []];
        $subject->populateAvailableTables($params);

        self::assertSame([], $params['items']);
    }

    #[Test]
    public function populateAvailableTablesReturnsNoItemsWhenNothingHasALanguageField(): void
    {
        $GLOBALS['TCA']['be_users']['ctrl'] = [];

        $tcaItemsProcessor = self::createStub(TcaItemsProcessorFunctions::class);
        $tcaItemsProcessor->method('populateAvailableTables')->willReturnCallback(
            static function (array &$params): void {
                $params['items'] = [
                    ['label' => 'Backend users', 'value' => 'be_users'],
                ];
            }
        );

        $subject = new Tablelist($tcaItemsProcessor);
        $params = ['items' => []];
        $subject->populateAvailableTables($params);

        self::assertSame([], $params['items']);
    }
}
