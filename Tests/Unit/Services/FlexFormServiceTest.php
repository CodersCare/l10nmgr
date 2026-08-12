<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Services\FlexFormService;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\Event\AfterFlexFormDataStructureIdentifierInitializedEvent;
use TYPO3\CMS\Core\Configuration\Event\BeforeFlexFormDataStructureIdentifierInitializedEvent;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidIdentifierException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidTcaException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers ensureDefaultSheet(), resolveFileDirectives(), flexArray2Xml(), the
 * getDataStructureIdentifier() resolution cluster (getDefaultIdentifier(),
 * getDataStructureIdentifierFromRecord(), getDataStructureIdentifierFromTcaArray()), the
 * parseDataStructureByIdentifier() resolution cluster (convertDataStructureToArray(),
 * getDefaultStructureForIdentifier()) and the traverseFlexFormXMLData()/
 * traverseFlexFormXMLData_recurse() tree-walking dispatcher - all pure TCA/array-driven logic
 * reachable without a database. executeCallBackMethod() has no dedicated test of its own - it is a
 * one-line delegation exercised by every traverseFlexFormXMLData_recurse() test that reaches a
 * callback. Deliberately not covered here: getDataStructureIdentifierFromRecord()'s
 * rootline-traversal branch (the "$pointerValue falsy and ds_pointerField_searchParent configured"
 * loop), which needs real page-tree records and BackendUtility::workspaceOL() - a TemplaVoila-era
 * mechanism with no fixture data anywhere else in this suite; getDefaultStructureForIdentifier()'s
 * "record" type happy path, which needs a real database fetch - see
 * Tests/Functional/Services/FlexFormServiceDatabaseTest.php for that one; and
 * convertDataStructureToArray()'s "FILE:" directive successfully resolving to a real extension
 * file, since EXT: path resolution needs a booted package manager unavailable in a plain unit test
 * - only the "resolution fails" guard is covered here, the resolution mechanism itself belongs to
 * TYPO3 core.
 */
class FlexFormServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    protected function setUp(): void
    {
        parent::setUp();
        // GeneralUtility::xml2array() reads/writes a "runtime" cache internally; a plain unit test
        // has no booted cache subsystem, so one is registered here to satisfy that dependency.
        $cacheManager = new CacheManager();
        $cacheManager->setCacheConfigurations([
            'runtime' => ['frontend' => VariableFrontend::class, 'backend' => TransientMemoryBackend::class, 'options' => []],
        ]);
        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManager);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    private function createSubject(?EventDispatcherInterface $eventDispatcher = null): FlexFormService
    {
        return new FlexFormService($eventDispatcher ?? self::createStub(EventDispatcherInterface::class));
    }

    private function invoke(string $method, array $args, ?EventDispatcherInterface $eventDispatcher = null): mixed
    {
        $subject = $this->createSubject($eventDispatcher);

        return (new \ReflectionMethod($subject, $method))->invoke($subject, ...$args);
    }

    /**
     * Mimics a production install with no registered event listeners: dispatch() just hands
     * the event straight back unmodified, as the real PSR-14 dispatcher does when nothing listens.
     */
    private function passthroughEventDispatcher(): EventDispatcherInterface
    {
        return new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
    }

    /**
     * A callback target matching traverseFlexFormXMLData()'s $callBackObj contract: records every
     * invocation's arguments instead of actually processing them, so tests can assert on exactly
     * what the traversal decided to call back with.
     */
    private function recordingCallbackObject(): object
    {
        return new class {
            public array $calls = [];

            public function record(mixed ...$args): bool
            {
                $this->calls[] = $args;
                return true;
            }
        };
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

    #[Test]
    public function getDataStructureIdentifierResolvesTheDefaultIdentifierWhenNoEventListenerSetsOne(): void
    {
        $subject = $this->createSubject($this->passthroughEventDispatcher());
        $fieldTca = ['config' => ['ds' => ['default' => '<xml/>']]];

        $result = $subject->getDataStructureIdentifier($fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1]);

        self::assertSame(
            ['type' => 'tca', 'tableName' => 'tt_content', 'fieldName' => 'pi_flexform', 'dataStructureKey' => 'default'],
            json_decode($result, true)
        );
    }

    #[Test]
    public function getDataStructureIdentifierUsesTheIdentifierSetByABeforeEventListenerAndSkipsDefaultResolution(): void
    {
        // No "ds" and no "ds_pointerField" configured: getDefaultIdentifier() would throw if it
        // were reached, so a successful, non-thrown result proves the Before listener's identifier
        // short-circuited default resolution entirely.
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof BeforeFlexFormDataStructureIdentifierInitializedEvent) {
                    $event->setIdentifier(['type' => 'custom']);
                }
                return $event;
            }
        };
        $subject = $this->createSubject($dispatcher);

        $result = $subject->getDataStructureIdentifier(['config' => []], 'tt_content', 'pi_flexform', ['uid' => 1]);

        self::assertSame(['type' => 'custom'], json_decode($result, true));
    }

    #[Test]
    public function getDataStructureIdentifierAllowsAnAfterEventListenerToOverrideTheResolvedIdentifier(): void
    {
        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof AfterFlexFormDataStructureIdentifierInitializedEvent) {
                    $event->setIdentifier(['type' => 'overridden']);
                }
                return $event;
            }
        };
        $subject = $this->createSubject($dispatcher);
        $fieldTca = ['config' => ['ds' => ['default' => '<xml/>']]];

        $result = $subject->getDataStructureIdentifier($fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1]);

        self::assertSame(['type' => 'overridden'], json_decode($result, true));
    }

    #[Test]
    public function getDefaultIdentifierThrowsWhenNeitherDsArrayNorPointerFieldIsConfigured(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1463826960);

        $this->invoke('getDefaultIdentifier', [['config' => []], 'tt_content', 'pi_flexform', ['uid' => 1]]);
    }

    #[Test]
    public function getDefaultIdentifierDelegatesToRecordResolutionWhenDsIsNotAnArrayButPointerFieldIsSet(): void
    {
        $fieldTca = ['config' => ['ds_pointerField' => 'ds_pointer', 'ds_tableField' => 'tx_foo:bar']];

        $result = $this->invoke('getDefaultIdentifier', [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'ds_pointer' => 5]]);

        self::assertSame(['type' => 'record', 'tableName' => 'tx_foo', 'uid' => 5, 'fieldName' => 'bar'], $result);
    }

    #[Test]
    public function getDefaultIdentifierDelegatesToTcaArrayResolutionWhenDsIsAnArray(): void
    {
        $fieldTca = ['config' => ['ds' => ['default' => '<xml/>']]];

        $result = $this->invoke('getDefaultIdentifier', [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1]]);

        self::assertSame(
            ['type' => 'tca', 'tableName' => 'tt_content', 'fieldName' => 'pi_flexform', 'dataStructureKey' => 'default'],
            $result
        );
    }

    #[Test]
    public function getDefaultIdentifierDelegatesToTcaArrayResolutionWhenDsIsAPlainString(): void
    {
        $fieldTca = ['config' => ['ds' => '<xml/>']];

        $result = $this->invoke('getDefaultIdentifier', [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1]]);

        self::assertSame(
            ['type' => 'tca', 'tableName' => 'tt_content', 'fieldName' => 'pi_flexform', 'dataStructureKey' => 'default'],
            $result
        );
    }

    #[Test]
    public function getDataStructureIdentifierFromRecordThrowsWhenThePointerFieldDoesNotExistInTheRow(): void
    {
        $this->expectException(InvalidTcaException::class);
        $this->expectExceptionCode(1464115059);

        $fieldTca = ['config' => ['ds_pointerField' => 'missing_field']];
        $this->invoke('getDataStructureIdentifierFromRecord', [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1]]);
    }

    #[Test]
    public function getDataStructureIdentifierFromRecordThrowsWhenThePointerValueIsFalsyAndNoParentFieldIsConfigured(): void
    {
        $this->expectException(InvalidTcaException::class);
        $this->expectExceptionCode(1464114011);

        $fieldTca = ['config' => ['ds_pointerField' => 'ds_pointer']];
        $this->invoke('getDataStructureIdentifierFromRecord', [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'ds_pointer' => 0]]);
    }

    #[Test]
    public function getDataStructureIdentifierFromRecordResolvesToARecordFieldIdentifierWhenThePointerValueIsNotNumeric(): void
    {
        $fieldTca = ['config' => ['ds_pointerField' => 'ds_pointer']];

        $result = $this->invoke(
            'getDataStructureIdentifierFromRecord',
            [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 7, 'ds_pointer' => 'nonnumeric']]
        );

        self::assertSame(['type' => 'record', 'tableName' => 'tt_content', 'uid' => 7, 'fieldName' => 'ds_pointer'], $result);
    }

    #[Test]
    public function getDataStructureIdentifierFromRecordThrowsWhenPointerValueIsNumericButDsTableFieldIsNotConfigured(): void
    {
        $this->expectException(InvalidTcaException::class);
        $this->expectExceptionCode(1464115639);

        $fieldTca = ['config' => ['ds_pointerField' => 'ds_pointer']];
        $this->invoke('getDataStructureIdentifierFromRecord', [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'ds_pointer' => 5]]);
    }

    #[Test]
    public function getDataStructureIdentifierFromRecordThrowsWhenDsTableFieldIsNotInTableColonFieldForm(): void
    {
        $this->expectException(InvalidTcaException::class);
        $this->expectExceptionCode(1464116002);

        $fieldTca = ['config' => ['ds_pointerField' => 'ds_pointer', 'ds_tableField' => 'invalid_no_colon']];
        $this->invoke('getDataStructureIdentifierFromRecord', [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'ds_pointer' => 5]]);
    }

    #[Test]
    public function getDataStructureIdentifierFromTcaArrayThrowsWhenNoPointerFieldIsConfiguredAndNoDefaultDsKeyExists(): void
    {
        $this->expectException(InvalidTcaException::class);
        $this->expectExceptionCode(1463652560);

        $fieldTca = ['config' => ['ds' => ['someOtherKey' => '<xml/>']]];
        $this->invoke('getDataStructureIdentifierFromTcaArray', [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1]]);
    }

    #[Test]
    public function getDataStructureIdentifierFromTcaArrayThrowsWhenPointerFieldConfigurationHasMoreThanTwoFields(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1463577497);

        $fieldTca = ['config' => ['ds_pointerField' => 'a,b,c']];
        $this->invoke('getDataStructureIdentifierFromTcaArray', [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'a' => 1, 'b' => 1, 'c' => 1]]);
    }

    #[Test]
    public function getDataStructureIdentifierFromTcaArrayThrowsWhenTheSinglePointerFieldNameDoesNotExistInTheRow(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1463578899);

        $fieldTca = ['config' => ['ds_pointerField' => 'missing_field']];
        $this->invoke('getDataStructureIdentifierFromTcaArray', [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1]]);
    }

    #[Test]
    public function getDataStructureIdentifierFromTcaArrayThrowsWhenTheSecondOfTwoPointerFieldNamesDoesNotExistInTheRow(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1463578900);

        $fieldTca = ['config' => ['ds_pointerField' => 'present_field,missing_field']];
        $this->invoke('getDataStructureIdentifierFromTcaArray', [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'present_field' => 'a']]);
    }

    #[Test]
    public function getDataStructureIdentifierFromTcaArraySinglePointerUsesTheRowValueAsDsKeyWhenItMatches(): void
    {
        $fieldTca = ['config' => ['ds_pointerField' => 'CType', 'ds' => ['textmedia' => '<xml/>', 'default' => '<xml/>']]];

        $result = $this->invoke(
            'getDataStructureIdentifierFromTcaArray',
            [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'CType' => 'textmedia']]
        );

        self::assertSame('textmedia', $result['dataStructureKey']);
    }

    #[Test]
    public function getDataStructureIdentifierFromTcaArraySinglePointerFallsBackToDefaultWhenTheRowValueHasNoMatchingDsKey(): void
    {
        $fieldTca = ['config' => ['ds_pointerField' => 'CType', 'ds' => ['textmedia' => '<xml/>', 'default' => '<xml/>']]];

        $result = $this->invoke(
            'getDataStructureIdentifierFromTcaArray',
            [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'CType' => 'unregistered_ctype']]
        );

        self::assertSame('default', $result['dataStructureKey']);
    }

    #[Test]
    public function getDataStructureIdentifierFromTcaArraySinglePointerThrowsWhenTheRowValueHasNoMatchAndNoDefaultExists(): void
    {
        $this->expectException(InvalidTcaException::class);
        $this->expectExceptionCode(1463653197);

        $fieldTca = ['config' => ['ds_pointerField' => 'CType', 'ds' => ['textmedia' => '<xml/>']]];
        $this->invoke(
            'getDataStructureIdentifierFromTcaArray',
            [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'CType' => 'unregistered_ctype']]
        );
    }

    #[Test]
    public function getDataStructureIdentifierFromTcaArrayCombinedPointerPrefersTheExactValuePairOverAnyWildcardOrFallback(): void
    {
        $fieldTca = ['config' => ['ds_pointerField' => 'list_type,CType', 'ds' => [
            'plugin_a,list' => '<xml/>',
            'plugin_a,*' => '<xml/>',
            '*,list' => '<xml/>',
            'plugin_a' => '<xml/>',
            'default' => '<xml/>',
        ]]];

        $result = $this->invoke(
            'getDataStructureIdentifierFromTcaArray',
            [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'list_type' => 'plugin_a', 'CType' => 'list']]
        );

        self::assertSame('plugin_a,list', $result['dataStructureKey']);
    }

    #[Test]
    public function getDataStructureIdentifierFromTcaArrayCombinedPointerFallsBackToFirstValueWildcardWhenTheExactPairIsMissing(): void
    {
        $fieldTca = ['config' => ['ds_pointerField' => 'list_type,CType', 'ds' => [
            'plugin_a,*' => '<xml/>',
            '*,list' => '<xml/>',
            'plugin_a' => '<xml/>',
            'default' => '<xml/>',
        ]]];

        $result = $this->invoke(
            'getDataStructureIdentifierFromTcaArray',
            [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'list_type' => 'plugin_a', 'CType' => 'list']]
        );

        self::assertSame('plugin_a,*', $result['dataStructureKey']);
    }

    #[Test]
    public function getDataStructureIdentifierFromTcaArrayCombinedPointerFallsBackToSecondValueWildcardWhenTheFirstValueWildcardIsMissing(): void
    {
        $fieldTca = ['config' => ['ds_pointerField' => 'list_type,CType', 'ds' => [
            '*,list' => '<xml/>',
            'plugin_a' => '<xml/>',
            'default' => '<xml/>',
        ]]];

        $result = $this->invoke(
            'getDataStructureIdentifierFromTcaArray',
            [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'list_type' => 'plugin_a', 'CType' => 'list']]
        );

        self::assertSame('*,list', $result['dataStructureKey']);
    }

    #[Test]
    public function getDataStructureIdentifierFromTcaArrayCombinedPointerFallsBackToTheBareFirstValueWhenBothWildcardsAreMissing(): void
    {
        $fieldTca = ['config' => ['ds_pointerField' => 'list_type,CType', 'ds' => [
            'plugin_a' => '<xml/>',
            'default' => '<xml/>',
        ]]];

        $result = $this->invoke(
            'getDataStructureIdentifierFromTcaArray',
            [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'list_type' => 'plugin_a', 'CType' => 'list']]
        );

        self::assertSame('plugin_a', $result['dataStructureKey']);
    }

    #[Test]
    public function getDataStructureIdentifierFromTcaArrayCombinedPointerFallsBackToDefaultWhenNothingElseMatches(): void
    {
        $fieldTca = ['config' => ['ds_pointerField' => 'list_type,CType', 'ds' => ['default' => '<xml/>']]];

        $result = $this->invoke(
            'getDataStructureIdentifierFromTcaArray',
            [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'list_type' => 'plugin_a', 'CType' => 'list']]
        );

        self::assertSame('default', $result['dataStructureKey']);
    }

    #[Test]
    public function getDataStructureIdentifierFromTcaArrayCombinedPointerThrowsWhenNothingMatchesAndNoDefaultExists(): void
    {
        $this->expectException(InvalidTcaException::class);
        $this->expectExceptionCode(1463678524);

        $fieldTca = ['config' => ['ds_pointerField' => 'list_type,CType', 'ds' => ['other' => '<xml/>']]];
        $this->invoke(
            'getDataStructureIdentifierFromTcaArray',
            [$fieldTca, 'tt_content', 'pi_flexform', ['uid' => 1, 'list_type' => 'plugin_a', 'CType' => 'list']]
        );
    }

    #[Test]
    public function parseDataStructureByIdentifierThrowsWhenTheIdentifierStringIsEmpty(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionCode(1478100828);

        $this->createSubject()->parseDataStructureByIdentifier('');
    }

    #[Test]
    public function parseDataStructureByIdentifierThrowsWhenTheIdentifierStringIsNotValidJson(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1478345642);

        $this->createSubject()->parseDataStructureByIdentifier('not-json');
    }

    #[Test]
    public function parseDataStructureByIdentifierThrowsWhenTheIdentifierStringDecodesToAnEmptyArray(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1478345642);

        $this->createSubject()->parseDataStructureByIdentifier('[]');
    }

    #[Test]
    public function parseDataStructureByIdentifierResolvesATcaTypeIdentifierIntoANormalizedSheetsStructure(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds']['default']
            = '<T3FlexForms><ROOT><type>array</type><el><field1><TCEforms><config><type>input</type></config></TCEforms></field1></el></ROOT></T3FlexForms>';
        $subject = $this->createSubject($this->passthroughEventDispatcher());
        $identifier = json_encode(['type' => 'tca', 'tableName' => 'tt_content', 'fieldName' => 'pi_flexform', 'dataStructureKey' => 'default']);

        $result = $subject->parseDataStructureByIdentifier($identifier);

        self::assertSame(
            ['sheets' => ['sDEF' => ['ROOT' => [
                'type' => 'array',
                'el' => ['field1' => ['TCEforms' => ['config' => ['type' => 'input']]]],
            ]]]],
            $result
        );
    }

    #[Test]
    public function parseDataStructureByIdentifierResolvesATcaTypeIdentifierWhenDsIsAPlainString(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds']
            = '<T3FlexForms><ROOT><type>array</type><el><field1><TCEforms><config><type>input</type></config></TCEforms></field1></el></ROOT></T3FlexForms>';
        $subject = $this->createSubject($this->passthroughEventDispatcher());
        $identifier = json_encode(['type' => 'tca', 'tableName' => 'tt_content', 'fieldName' => 'pi_flexform', 'dataStructureKey' => 'default']);

        $result = $subject->parseDataStructureByIdentifier($identifier);

        self::assertSame(
            ['sheets' => ['sDEF' => ['ROOT' => [
                'type' => 'array',
                'el' => ['field1' => ['TCEforms' => ['config' => ['type' => 'input']]]],
            ]]]],
            $result
        );
    }

    #[Test]
    public function convertDataStructureToArrayReturnsAnArrayArgumentUnchanged(): void
    {
        $dataStructure = ['sheets' => ['sDEF' => ['ROOT' => ['type' => 'array']]]];

        $result = $this->invoke('convertDataStructureToArray', [$dataStructure]);

        self::assertSame($dataStructure, $result);
    }

    #[Test]
    public function convertDataStructureToArrayThrowsWhenAFileDirectiveDoesNotResolveToAnExistingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1478105826);

        $this->invoke('convertDataStructureToArray', ['FILE:EXT:l10nmgr/does/not/exist.xml']);
    }

    #[Test]
    public function convertDataStructureToArrayParsesAPlainXmlStringDirectly(): void
    {
        $result = $this->invoke('convertDataStructureToArray', ['<T3FlexForms><ROOT><type>array</type></ROOT></T3FlexForms>']);

        self::assertSame(['ROOT' => ['type' => 'array']], $result);
    }

    #[Test]
    public function convertDataStructureToArrayThrowsWhenTheStringDoesNotParseToAValidStructure(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionCode(1478106090);

        $this->invoke('convertDataStructureToArray', ['this is not xml at all']);
    }

    #[Test]
    public function getDefaultStructureForIdentifierThrowsWhenARecordTypeIdentifierIsMissingTableNameUidOrFieldName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1478113873);

        $this->invoke('getDefaultStructureForIdentifier', [['type' => 'record', 'tableName' => 'tt_content', 'fieldName' => 'header']]);
    }

    #[Test]
    public function getDefaultStructureForIdentifierThrowsWhenATcaTypeIdentifierIsMissingTableNameFieldNameOrDataStructureKey(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1478113471);

        $this->invoke('getDefaultStructureForIdentifier', [['type' => 'tca', 'tableName' => 'tt_content', 'fieldName' => 'pi_flexform']]);
    }

    #[Test]
    public function getDefaultStructureForIdentifierThrowsWhenATcaTypeIdentifierDoesNotResolveToAStringDsValueInTca(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionCode(1478105491);

        $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds'] = [];
        $identifier = ['type' => 'tca', 'tableName' => 'tt_content', 'fieldName' => 'pi_flexform', 'dataStructureKey' => 'default'];
        $this->invoke('getDefaultStructureForIdentifier', [$identifier]);
    }

    #[Test]
    public function getDefaultStructureForIdentifierThrowsWhenTheIdentifierTypeIsNeitherRecordNorTca(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionCode(1478104554);

        $this->invoke('getDefaultStructureForIdentifier', [['type' => 'bogus']]);
    }

    #[Test]
    public function traverseFlexFormXMLDataReturnsAnErrorStringWhenTheTcaTableIsNotConfiguredAtAll(): void
    {
        // Explicitly assigning null (rather than leaving the key absent) keeps the read below from
        // triggering an "undefined array key" warning while still exercising the "not is_array" guard.
        $GLOBALS['TCA']['tt_content'] = null;
        $subject = $this->createSubject();

        $result = $subject->traverseFlexFormXMLData('tt_content', 'pi_flexform', ['uid' => 1], $this->recordingCallbackObject(), 'record');

        self::assertSame('TCA table/field was not defined.', $result);
    }

    #[Test]
    public function traverseFlexFormXMLDataReturnsAnErrorStringWhenTheTcaFieldColumnIsNotConfigured(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['pi_flexform'] = null;
        $subject = $this->createSubject();

        $result = $subject->traverseFlexFormXMLData('tt_content', 'pi_flexform', ['uid' => 1], $this->recordingCallbackObject(), 'record');

        self::assertSame('TCA table/field was not defined.', $result);
    }

    #[Test]
    public function traverseFlexFormXMLDataReturnsAParsingErrorStringWhenTheFieldValueIsNotValidFlexFormXml(): void
    {
        // No "default" ds fallback and an unregistered CType: getDataStructureIdentifier() throws a
        // catchable AbstractInvalidDataStructureException, so the dummy sheets=>sDEF=>[] structure is
        // used instead - proving the method reaches XML parsing of $row[$field] even when data
        // structure resolution itself fails, rather than the two being conflated.
        $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config'] = [
            'ds_pointerField' => 'CType',
            'ds' => ['known_type' => '<xml/>'],
        ];
        $subject = $this->createSubject($this->passthroughEventDispatcher());
        $row = ['uid' => 1, 'CType' => 'unregistered_type', 'pi_flexform' => 'this is not xml at all'];

        $result = $subject->traverseFlexFormXMLData('tt_content', 'pi_flexform', $row, $this->recordingCallbackObject(), 'record');

        self::assertStringStartsWith('Parsing error: ', $result);
    }

    #[Test]
    public function traverseFlexFormXMLDataReturnsAnErrorStringWhenTheResolvedDataStructureHasANonArraySheetsValue(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds']['default']
            = '<T3FlexForms><sheets>notarray</sheets></T3FlexForms>';
        $subject = $this->createSubject($this->passthroughEventDispatcher());
        $row = ['uid' => 5, 'pi_flexform' => '<T3FlexForms><data/></T3FlexForms>'];

        $result = $subject->traverseFlexFormXMLData('tt_content', 'pi_flexform', $row, $this->recordingCallbackObject(), 'record');

        self::assertSame('Data Structure ERROR: sheets is defined but not an array for table tt_content and uid 5', $result);
    }

    #[Test]
    public function traverseFlexFormXMLDataReturnsAnErrorStringWhenASheetHasNoUsableRootElement(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds']['default']
            = '<T3FlexForms><ROOT><type>array</type><el>notarray</el></ROOT></T3FlexForms>';
        $subject = $this->createSubject($this->passthroughEventDispatcher());
        $row = ['uid' => 7, 'pi_flexform' => '<T3FlexForms><data/></T3FlexForms>'];

        $result = $subject->traverseFlexFormXMLData('tt_content', 'pi_flexform', $row, $this->recordingCallbackObject(), 'record');

        self::assertSame('Data Structure ERROR: No ROOT element found for sheet "sDEF".', $result);
    }

    #[Test]
    public function traverseFlexFormXMLDataInvokesTheCallbackForEachFieldValueAndReturnsTrueOnSuccess(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds']['default']
            = '<T3FlexForms><ROOT><type>array</type><el><field1><TCEforms><config><type>input</type></config></TCEforms></field1></el></ROOT></T3FlexForms>';
        $subject = $this->createSubject($this->passthroughEventDispatcher());
        $callback = $this->recordingCallbackObject();
        $row = [
            'uid' => 10,
            'pi_flexform' => '<T3FlexForms><data><sDEF><lDEF><field1><vDEF>Hello</vDEF></field1></lDEF></sDEF></data></T3FlexForms>',
        ];

        $result = $subject->traverseFlexFormXMLData('tt_content', 'pi_flexform', $row, $callback, 'record');

        self::assertTrue($result);
        self::assertCount(1, $callback->calls);
        [$value, $editDataValue, $PA, $path, $selfReference] = $callback->calls[0];
        self::assertSame(['TCEforms' => ['config' => ['type' => 'input']]], $value);
        self::assertSame('Hello', $editDataValue);
        self::assertSame('data/sDEF/lDEF/field1/vDEF', $path);
        self::assertSame($subject, $selfReference);
        self::assertSame(['table' => 'tt_content', 'field' => 'pi_flexform', 'uid' => 10], [
            'table' => $PA['table'],
            'field' => $PA['field'],
            'uid' => $PA['uid'],
        ]);
    }

    #[Test]
    public function traverseFlexFormXMLDataRecurseInvokesTheCallbackOnceForEachConfiguredVKeyPresentInEditData(): void
    {
        $subject = $this->createSubject();
        $callback = $this->recordingCallbackObject();
        $dataStruct = ['field1' => ['config' => ['type' => 'input']]];
        $editData = ['field1' => ['vDEF' => 'valueA', 'vENG' => 'valueB']];
        $PA = ['vKeys' => ['DEF', 'ENG'], 'callBackMethod_value' => 'record'];
        $subject->callBackObj = $callback;

        $subject->traverseFlexFormXMLData_recurse($dataStruct, $editData, $PA, 'path');

        self::assertCount(2, $callback->calls);
        self::assertSame('valueA', $callback->calls[0][1]);
        self::assertSame('valueB', $callback->calls[1][1]);
    }

    #[Test]
    public function traverseFlexFormXMLDataRecurseDoesNotInvokeTheCallbackWhenNoCallBackMethodIsConfigured(): void
    {
        $subject = $this->createSubject();
        $callback = $this->recordingCallbackObject();
        $dataStruct = ['field1' => ['config' => ['type' => 'input']]];
        $editData = ['field1' => ['vDEF' => 'valueA']];
        $PA = ['vKeys' => ['DEF'], 'callBackMethod_value' => ''];
        $subject->callBackObj = $callback;

        $subject->traverseFlexFormXMLData_recurse($dataStruct, $editData, $PA, 'path');

        self::assertSame([], $callback->calls);
    }

    #[Test]
    public function traverseFlexFormXMLDataRecurseDoesNotInvokeTheCallbackWhenTheEditDataHasNoValueForTheVKey(): void
    {
        $subject = $this->createSubject();
        $callback = $this->recordingCallbackObject();
        $dataStruct = ['field1' => ['config' => ['type' => 'input']]];
        $editData = ['field1' => []];
        $PA = ['vKeys' => ['DEF'], 'callBackMethod_value' => 'record'];
        $subject->callBackObj = $callback;

        $subject->traverseFlexFormXMLData_recurse($dataStruct, $editData, $PA, 'path');

        self::assertSame([], $callback->calls);
    }

    #[Test]
    public function traverseFlexFormXMLDataRecurseWalksIntoNestedNonSectionArrayElements(): void
    {
        $subject = $this->createSubject();
        $callback = $this->recordingCallbackObject();
        $dataStruct = ['container' => ['type' => 'array', 'el' => ['field1' => ['config' => ['type' => 'input']]]]];
        $editData = ['container' => ['el' => ['field1' => ['vDEF' => 'nested']]]];
        $PA = ['vKeys' => ['DEF'], 'callBackMethod_value' => 'record'];
        $subject->callBackObj = $callback;

        $subject->traverseFlexFormXMLData_recurse($dataStruct, $editData, $PA, 'path');

        self::assertCount(1, $callback->calls);
        self::assertSame('nested', $callback->calls[0][1]);
        self::assertSame('path/container/el/field1/vDEF', $callback->calls[0][3]);
    }

    #[Test]
    public function traverseFlexFormXMLDataRecurseWalksIntoEachSectionItemKeyedByItsOwnType(): void
    {
        $subject = $this->createSubject();
        $callback = $this->recordingCallbackObject();
        // Section item types are themselves type=array containers with their own "el" - the same
        // shape a plain (non-section) array element has, just reached via the section's numbered
        // "el" entries instead of directly.
        $dataStruct = ['mySection' => [
            'type' => 'array',
            'section' => true,
            'el' => ['itemTypeA' => ['type' => 'array', 'el' => ['field1' => ['config' => ['type' => 'input']]]]],
        ]];
        $editData = ['mySection' => ['el' => [3 => ['itemTypeA' => ['el' => ['field1' => ['vDEF' => 'sectionValue']]]]]]];
        $PA = ['vKeys' => ['DEF'], 'callBackMethod_value' => 'record'];
        $subject->callBackObj = $callback;

        $subject->traverseFlexFormXMLData_recurse($dataStruct, $editData, $PA, 'path');

        self::assertCount(1, $callback->calls);
        self::assertSame('sectionValue', $callback->calls[0][1]);
        self::assertSame('path/mySection/el/3/itemTypeA/el/field1/vDEF', $callback->calls[0][3]);
    }

    #[Test]
    public function traverseFlexFormXMLDataRecurseIgnoresASectionItemWhoseTypeHasNoMatchingElementDefinition(): void
    {
        $subject = $this->createSubject();
        $callback = $this->recordingCallbackObject();
        $dataStruct = ['mySection' => [
            'type' => 'array',
            'section' => true,
            'el' => ['itemTypeA' => ['type' => 'array', 'el' => ['field1' => ['config' => ['type' => 'input']]]]],
        ]];
        // The section item's own type key ("itemTypeB") has no counterpart in the data structure's
        // "el" definition (only "itemTypeA" is configured there) - a real, easy-to-miss guard against
        // stale section data left over after a data structure change.
        $editData = ['mySection' => ['el' => [3 => ['itemTypeB' => ['el' => ['field1' => ['vDEF' => 'orphaned']]]]]]];
        $PA = ['vKeys' => ['DEF'], 'callBackMethod_value' => 'record'];
        $subject->callBackObj = $callback;

        $subject->traverseFlexFormXMLData_recurse($dataStruct, $editData, $PA, 'path');

        self::assertSame([], $callback->calls);
    }

    #[Test]
    public function traverseFlexFormXMLDataRecurseRenumbersSectionIndexesWhenTheFlagIsEnabled(): void
    {
        $subject = $this->createSubject();
        $subject->reNumberIndexesOfSectionData = true;
        $callback = $this->recordingCallbackObject();
        $dataStruct = ['mySection' => [
            'type' => 'array',
            'section' => true,
            'el' => ['itemTypeA' => ['type' => 'array', 'el' => ['field1' => ['config' => ['type' => 'input']]]]],
        ]];
        // Deliberately non-contiguous, non-zero-based original keys (5, 9) to prove renumbering
        // actually happens rather than just "still works with whatever keys were already there".
        $editData = ['mySection' => ['el' => [
            5 => ['itemTypeA' => ['el' => ['field1' => ['vDEF' => 'first']]]],
            9 => ['itemTypeA' => ['el' => ['field1' => ['vDEF' => 'second']]]],
        ]]];
        $PA = ['vKeys' => ['DEF'], 'callBackMethod_value' => 'record'];
        $subject->callBackObj = $callback;

        $subject->traverseFlexFormXMLData_recurse($dataStruct, $editData, $PA, 'path');

        self::assertCount(2, $callback->calls);
        self::assertSame('first', $callback->calls[0][1]);
        self::assertSame('path/mySection/el/1/itemTypeA/el/field1/vDEF', $callback->calls[0][3]);
        self::assertSame('second', $callback->calls[1][1]);
        self::assertSame('path/mySection/el/2/itemTypeA/el/field1/vDEF', $callback->calls[1][3]);
    }
}
