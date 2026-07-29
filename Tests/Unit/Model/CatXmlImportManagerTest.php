<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Model\CatXmlImportManager;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers only the pure array-parsing/error-accumulation logic (getPidsFromCATXMLNodes(),
 * getDelL10NDataFromCATXMLNodes(), getErrorMessages(), getXMLNodes(), and the protected header/
 * validation helpers via reflection). parseAndCheckXMLFile()/parseAndCheckXMLString() additionally
 * dispatch a real PSR-14 event and read files from disk, and delL10N() needs a real database -
 * both belong in a functional test instead.
 */
class CatXmlImportManagerTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // L10NMGR_FILEVERSION is normally defined in ext_localconf.php, which a plain unit test
        // bootstrap never loads - guarded the same way the extension itself defines it.
        if (!defined('L10NMGR_FILEVERSION')) {
            define('L10NMGR_FILEVERSION', '2.0');
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function stubLanguageService(): void
    {
        $languageService = self::createStub(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);
        $GLOBALS['LANG'] = $languageService;
    }

    private function stubBackendUserWithWorkspace(int $workspace): void
    {
        $beUser = self::createStub(BackendUserAuthentication::class);
        $beUser->workspace = $workspace;
        $GLOBALS['BE_USER'] = $beUser;
    }

    #[Test]
    public function getPidsFromCatXmlNodesExtractsAllPageGroupIds(): void
    {
        $subject = new CatXmlImportManager('', 0, '');

        $xmlNodes = [
            'TYPO3L10N' => [[
                'ch' => [
                    'pageGrp' => [
                        ['attrs' => ['id' => '5']],
                        ['attrs' => ['id' => '7']],
                    ],
                ],
            ]],
        ];

        self::assertSame(['5', '7'], $subject->getPidsFromCATXMLNodes($xmlNodes));
    }

    #[Test]
    public function getPidsFromCatXmlNodesReturnsEmptyArrayWhenNoPageGroupsExist(): void
    {
        $subject = new CatXmlImportManager('', 0, '');

        self::assertSame([], $subject->getPidsFromCATXMLNodes(['TYPO3L10N' => [['ch' => []]]]));
    }

    #[Test]
    public function getDelL10NDataFromCatXmlNodesCollectsOnlyRowsWithNewInTheirKey(): void
    {
        $subject = new CatXmlImportManager('', 0, '');

        $xmlNodes = [
            'TYPO3L10N' => [[
                'ch' => [
                    'pageGrp' => [[
                        'ch' => [
                            'data' => [
                                ['attrs' => ['key' => 'tt_content:NEW123:header', 'table' => 'tt_content', 'elementUid' => 'NEW123']],
                                ['attrs' => ['key' => 'tt_content:5:header', 'table' => 'tt_content', 'elementUid' => '5']],
                            ],
                        ],
                    ]],
                ],
            ]],
        ];

        self::assertSame(['tt_content:NEW123'], $subject->getDelL10NDataFromCATXMLNodes($xmlNodes));
    }

    #[Test]
    public function getDelL10NDataFromCatXmlNodesDeduplicatesRepeatedTableUidCombinations(): void
    {
        $subject = new CatXmlImportManager('', 0, '');

        $xmlNodes = [
            'TYPO3L10N' => [[
                'ch' => [
                    'pageGrp' => [[
                        'ch' => [
                            'data' => [
                                ['attrs' => ['key' => 'NEW123', 'table' => 'tt_content', 'elementUid' => 'NEW123']],
                                ['attrs' => ['key' => 'NEW123', 'table' => 'tt_content', 'elementUid' => 'NEW123']],
                            ],
                        ],
                    ]],
                ],
            ]],
        ];

        self::assertSame([0 => 'tt_content:NEW123'], $subject->getDelL10NDataFromCATXMLNodes($xmlNodes));
    }

    #[Test]
    public function getErrorMessagesJoinsAccumulatedMessagesWithHtmlLineBreaks(): void
    {
        $this->stubLanguageService();
        $this->stubBackendUserWithWorkspace(0);
        $subject = new CatXmlImportManager('', 5, '');

        $method = new \ReflectionMethod($subject, '_isIncorrectXMLString');
        $headerDataProperty = new \ReflectionProperty($subject, 'headerData');
        $headerDataProperty->setValue($subject, []);
        $method->invoke($subject);

        self::assertStringContainsString('<br />', $subject->getErrorMessages());
    }

    #[Test]
    public function getXmlNodesReturnsByReferenceSoCallerMutationsPersist(): void
    {
        $subject = new CatXmlImportManager('', 0, '');
        $xmlNodesProperty = new \ReflectionProperty($subject, 'xmlNodes');
        $xmlNodesProperty->setValue($subject, ['original' => true]);

        $reference = &$subject->getXMLNodes();
        $reference['mutated'] = true;

        self::assertSame(['original' => true, 'mutated' => true], $subject->getXMLNodes());
    }

    #[Test]
    public function setHeaderDataExtractsTheFirstValueOfEachWellFormedNode(): void
    {
        $subject = new CatXmlImportManager('', 0, '');
        $method = new \ReflectionMethod($subject, '_setHeaderData');

        $method->invoke($subject, [
            't3_formatVersion' => [['values' => ['1.0']]],
            't3_workspaceId' => [['values' => ['0']]],
        ]);

        self::assertSame(['t3_formatVersion' => '1.0', 't3_workspaceId' => '0'], $subject->headerData);
    }

    #[Test]
    public function setHeaderDataDefaultsToEmptyStringForAMalformedNode(): void
    {
        $subject = new CatXmlImportManager('', 0, '');
        $method = new \ReflectionMethod($subject, '_setHeaderData');

        $method->invoke($subject, ['t3_formatVersion' => 'not-an-array']);

        self::assertSame(['t3_formatVersion' => ''], $subject->headerData);
    }

    #[Test]
    public function isIncorrectXmlStringDetectsAllThreeHeaderMismatchesAtOnce(): void
    {
        $this->stubLanguageService();
        $this->stubBackendUserWithWorkspace(3);
        $subject = new CatXmlImportManager('', 7, '');
        $headerDataProperty = new \ReflectionProperty($subject, 'headerData');
        $headerDataProperty->setValue($subject, [
            't3_formatVersion' => 'wrong-version',
            't3_workspaceId' => 0,
        ]);

        $method = new \ReflectionMethod($subject, '_isIncorrectXMLString');
        $result = $method->invoke($subject);

        self::assertTrue($result);
        self::assertCount(3, explode('<br />', $subject->getErrorMessages()));
    }

    #[Test]
    public function isIncorrectXmlStringPassesWhenFormatVersionAndWorkspaceMatchAndSysLangIsPresent(): void
    {
        $this->stubLanguageService();
        $this->stubBackendUserWithWorkspace(0);
        $subject = new CatXmlImportManager('', 7, '');
        $headerDataProperty = new \ReflectionProperty($subject, 'headerData');
        $headerDataProperty->setValue($subject, [
            't3_formatVersion' => L10NMGR_FILEVERSION,
            't3_workspaceId' => 0,
            't3_sysLang' => 7,
        ]);

        $method = new \ReflectionMethod($subject, '_isIncorrectXMLString');
        $result = $method->invoke($subject);

        self::assertFalse($result);
        self::assertSame('', $subject->getErrorMessages());
    }

    #[Test]
    public function isIncorrectXmlFileAlsoRequiresTheSysLangValueToMatchExactly(): void
    {
        // Unlike _isIncorrectXMLString(), _isIncorrectXMLFile() additionally requires
        // t3_sysLang to equal $this->sysLang exactly, not just be present.
        $this->stubLanguageService();
        $this->stubBackendUserWithWorkspace(0);
        $subject = new CatXmlImportManager('', 7, '');
        $headerDataProperty = new \ReflectionProperty($subject, 'headerData');
        $headerDataProperty->setValue($subject, [
            't3_formatVersion' => L10NMGR_FILEVERSION,
            't3_workspaceId' => 0,
            't3_sysLang' => 2,
        ]);

        $method = new \ReflectionMethod($subject, '_isIncorrectXMLFile');
        $result = $method->invoke($subject);

        self::assertTrue($result);
    }

    #[Test]
    public function isIncorrectXmlFileOverwritesTheBackendUsersWorkspaceWhenItDiffersFromTheHeader(): void
    {
        // Real, currently-live side effect worth knowing about: unlike _isIncorrectXMLString(),
        // _isIncorrectXMLFile() does not just compare the workspace mismatch - it actively
        // overwrites $this->getBackendUser()->workspace with the header's value as a side effect
        // of building the error message.
        $this->stubLanguageService();
        $beUser = self::createStub(BackendUserAuthentication::class);
        $beUser->workspace = 0;
        $GLOBALS['BE_USER'] = $beUser;
        $subject = new CatXmlImportManager('', 7, '');
        $headerDataProperty = new \ReflectionProperty($subject, 'headerData');
        $headerDataProperty->setValue($subject, [
            't3_formatVersion' => L10NMGR_FILEVERSION,
            't3_workspaceId' => 5,
            't3_sysLang' => 7,
        ]);

        $method = new \ReflectionMethod($subject, '_isIncorrectXMLFile');
        $method->invoke($subject);

        self::assertSame(5, $beUser->workspace);
    }
}
