<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Command\Import;
use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers the pure-logic/lightweight pieces only: getWsIdFromCATXML(), checkFileType(),
 * initializeCallParameters(), configure(), and execute()'s "no --task given" early validation.
 * importCATXML()/importXMLFile()/previewSource() drive the full import pipeline through
 * L10nBaseService (one of the giant service classes already slated for its own dedicated batch),
 * TranslationDataFactory and DataHandler-backed saves - deferred there. sendMailNotification()
 * needs real sys_workspace/tx_l10nmgr_cfg database reads. getFilesFromFtp() needs a real FTP
 * server and has no practical way to be exercised in this test suite.
 */
class ImportTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $languageService = self::createStub(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);
        $GLOBALS['LANG'] = $languageService;
        $GLOBALS['BE_USER'] = self::createStub(BackendUserAuthentication::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function createCommand(): Import
    {
        // EmConfiguration's constructor falls back to reading TYPO3\CMS\Core\Configuration\
        // ExtensionConfiguration (needs a booted DI container) whenever empty($configuration) is
        // true, so an empty array must be avoided here - any non-empty array skips that fallback.
        return new Import(new EmConfiguration(['enable_ftp' => 0]));
    }

    #[Test]
    public function getWsIdFromCatXmlExtractsTheWorkspaceIdFromTheXmlString(): void
    {
        $subject = $this->createCommand();
        $method = new \ReflectionMethod($subject, 'getWsIdFromCATXML');

        $result = $method->invoke($subject, '<TYPO3L10N><head><t3_workspaceId>5</t3_workspaceId></head></TYPO3L10N>');

        self::assertSame(5, $result);
    }

    #[Test]
    public function getWsIdFromCatXmlThrowsOnEmptyXml(): void
    {
        $subject = $this->createCommand();
        $method = new \ReflectionMethod($subject, 'getWsIdFromCATXML');

        $this->expectException(Exception::class);

        $method->invoke($subject, '');
    }

    #[Test]
    public function getWsIdFromCatXmlThrowsWhenNoWorkspaceIdTagIsPresent(): void
    {
        $subject = $this->createCommand();
        $method = new \ReflectionMethod($subject, 'getWsIdFromCATXML');

        $this->expectException(Exception::class);

        $method->invoke($subject, '<TYPO3L10N><head></head></TYPO3L10N>');
    }

    #[Test]
    public function checkFileTypeKeepsOnlyFilesMatchingTheGivenExtension(): void
    {
        $subject = $this->createCommand();
        $method = new \ReflectionMethod($subject, 'checkFileType');

        $result = $method->invoke($subject, ['a.xml', 'b.zip', 'c.xml'], 'xml');

        self::assertSame(['a.xml', 'c.xml'], $result);
    }

    #[Test]
    public function checkFileTypeReturnsEmptyArrayWhenNothingMatches(): void
    {
        $subject = $this->createCommand();
        $method = new \ReflectionMethod($subject, 'checkFileType');

        self::assertSame([], $method->invoke($subject, ['a.zip', 'b.zip'], 'xml'));
    }

    #[Test]
    public function initializeCallParametersReadsAllOptionsFromTheInput(): void
    {
        $subject = $this->createCommand();
        $input = new ArrayInput([
            '--task' => 'importString',
            '--string' => 'some\\\\xml',
            '--file' => '/tmp/foo.xml',
            '--server' => 'https://example.com/',
            '--importAsDefaultLanguage' => true,
            '--srcPID' => '7',
        ], $subject->getDefinition());
        $method = new \ReflectionMethod($subject, 'initializeCallParameters');

        $result = $method->invoke($subject, $input);

        self::assertSame('importString', $result['task']);
        self::assertSame('some\\xml', $result['string'], 'stripslashes() must be applied to the --string option');
        self::assertSame('/tmp/foo.xml', $result['file']);
        self::assertSame('https://example.com/', $result['server']);
        self::assertTrue($result['importAsDefaultLanguage']);
        self::assertSame('7', $result['sourcePid']);
    }

    #[Test]
    public function initializeCallParametersThrowsWhenTaskIsMissingOrInvalid(): void
    {
        $subject = $this->createCommand();
        $input = new ArrayInput(['--task' => 'notARealTask'], $subject->getDefinition());
        $method = new \ReflectionMethod($subject, 'initializeCallParameters');

        $this->expectException(Exception::class);

        $method->invoke($subject, $input);
    }

    #[Test]
    public function configureRegistersAllExpectedOptions(): void
    {
        $subject = $this->createCommand();

        $definition = $subject->getDefinition();

        foreach (['task', 'file', 'importAsDefaultLanguage', 'preview', 'server', 'srcPID', 'string'] as $optionName) {
            self::assertTrue($definition->hasOption($optionName), "expected option --{$optionName} to be registered");
        }
    }

    #[Test]
    public function executeFailsWithoutATaskOption(): void
    {
        $subject = $this->createCommand();
        $tester = new CommandTester($subject);

        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Please specify a task', $tester->getDisplay());
    }
}
