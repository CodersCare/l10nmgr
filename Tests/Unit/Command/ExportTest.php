<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Command\Export;
use Localizationteam\L10nmgr\Model\Dto\EmConfiguration;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers configure()'s CLI surface and execute()'s early validation branches only (missing
 * --config/--target, non-integer --workspace) - all of which return before ever touching
 * L10nConfiguration/CatXmlView/ExcelXmlView, so no database or site configuration is needed.
 * exportXML() itself (the real export pipeline: Site resolution, file I/O, DataHandler,
 * NotificationService, FTP upload) needs a disproportionate integration setup for a coverage
 * pass and is deferred alongside the other heavy integration pieces (see backlog).
 */
class ExportTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $languageService = self::createStub(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);
        $GLOBALS['LANG'] = $languageService;
        $beUser = self::createStub(BackendUserAuthentication::class);
        $GLOBALS['BE_USER'] = $beUser;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function createCommand(array $emConfigurationOverrides = []): Export
    {
        $emConfiguration = new EmConfiguration(array_merge([
            'l10nmgr_cfg' => '',
            'l10nmgr_tlangs' => '',
        ], $emConfigurationOverrides));
        return new Export($emConfiguration);
    }

    #[Test]
    public function configureRegistersAllExpectedOptions(): void
    {
        $command = $this->createCommand();

        $definition = $command->getDefinition();

        foreach (['check-exports', 'config', 'forcedSourceLanguage', 'format', 'target', 'workspace', 'baseUrl'] as $optionName) {
            self::assertTrue($definition->hasOption($optionName), "expected option --{$optionName} to be registered");
        }
        self::assertSame('CATXML', $definition->getOption('format')->getDefault());
        self::assertFalse($definition->getOption('check-exports')->acceptValue(), '--check-exports is a flag (VALUE_NONE), it must not accept a value');
    }

    #[Test]
    public function executeFailsWhenNoConfigIsGivenAndNoneIsConfiguredInExtensionSettings(): void
    {
        $command = $this->createCommand(['l10nmgr_cfg' => '']);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--config' => 'EXTCONF']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('error.no_l10ncfg.msg', $tester->getDisplay());
    }

    #[Test]
    public function executeFailsWhenNoTargetLanguageIsGivenAndNoneIsConfiguredInExtensionSettings(): void
    {
        $command = $this->createCommand(['l10nmgr_cfg' => '1', 'l10nmgr_tlangs' => '']);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--config' => 'EXTCONF', '--target' => '0']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('error.target_language_id.msg', $tester->getDisplay());
    }

    #[Test]
    public function executeFailsWhenTheWorkspaceOptionIsNotAnInteger(): void
    {
        $command = $this->createCommand(['l10nmgr_cfg' => '1', 'l10nmgr_tlangs' => '1']);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--config' => 'EXTCONF', '--target' => '1', '--workspace' => 'not-a-number']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('error.workspace_id_int.msg', $tester->getDisplay());
    }

    #[Test]
    public function executeUsesExplicitConfigOptionOverExtensionSettings(): void
    {
        // Passing --config short-circuits before reaching the l10nmgr_cfg-derived list, so an
        // explicit non-numeric config id surfaces the id-must-be-integer error, not the
        // "no config given" one - proves the CLI option takes precedence.
        $command = $this->createCommand(['l10nmgr_cfg' => '999']);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--config' => 'not-a-number', '--target' => '1']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('error.l10ncfg_id_int.msg', $tester->getDisplay());
    }
}
