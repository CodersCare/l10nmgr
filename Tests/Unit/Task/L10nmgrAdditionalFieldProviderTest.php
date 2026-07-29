<?php

declare(strict_types=1);

namespace Localizationteam\L10nmgr\Test;

use Localizationteam\L10nmgr\Task\L10nmgrAdditionalFieldProvider;
use Localizationteam\L10nmgr\Task\L10nmgrFileGarbageCollection;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class L10nmgrAdditionalFieldProviderTest extends UnitTestCase
{
    // validateAdditionalFields() calls addMessage() on invalid input, which registers the
    // FlashMessageService singleton via GeneralUtility::makeInstance() - must be reset in tearDown().
    protected bool $resetSingletonInstances = true;

    protected L10nmgrAdditionalFieldProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new L10nmgrAdditionalFieldProvider();
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * SchedulerModuleController is final (cannot be mocked) and its constructor needs 8 heavy,
     * container-autowired dependencies unavailable in a plain unit test. Its only property this
     * class cares about, getCurrentAction(), just returns the private $currentAction - so bypassing
     * the constructor entirely and setting that one property via reflection is both simpler and
     * more accurate than trying to satisfy (or double) the full dependency list.
     */
    private function createParentObjectWithAction(string $action): SchedulerModuleController
    {
        $parentObject = (new \ReflectionClass(SchedulerModuleController::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(SchedulerModuleController::class, 'currentAction');
        $enumClass = \TYPO3\CMS\Scheduler\SchedulerManagementAction::class;
        $actionValue = class_exists($enumClass)
            ? $enumClass::from($action)
            : new \TYPO3\CMS\Scheduler\Task\Enumeration\Action($action);
        $property->setValue($parentObject, $actionValue);
        return $parentObject;
    }

    /**
     * AbstractTask::__construct() calls GeneralUtility::makeInstance(Scheduler::class), which needs
     * a booted DI container unavailable in a plain unit test. None of these tests touch the
     * scheduler/execution properties that constructor sets up, so bypassing it entirely is safe -
     * property defaults (age = 30, excludePattern = the default regex) are still applied by PHP
     * regardless of whether the constructor runs.
     */
    private function createTaskWithoutConstructor(): L10nmgrFileGarbageCollection
    {
        return (new \ReflectionClass(L10nmgrFileGarbageCollection::class))->newInstanceWithoutConstructor();
    }

    #[Test]
    public function getAdditionalFieldsUsesDefaultsWhenTaskInfoIsNotYetPopulated(): void
    {
        $parentObject = $this->createParentObjectWithAction('add');
        $taskInfo = [];

        $fields = $this->subject->getAdditionalFields($taskInfo, null, $parentObject);

        self::assertSame(30, $taskInfo['l10nmgr_fileGarbageCollection_age']);
        self::assertSame('(index\.html|\.htaccess)', $taskInfo['l10nmgr_fileGarbageCollection_excludePattern']);
        self::assertStringContainsString('value="30"', $fields['task_fileGarbageCollection_age']['code']);
    }

    #[Test]
    public function getAdditionalFieldsDoesNotOverwriteAlreadyPresentTaskInfoValues(): void
    {
        $parentObject = $this->createParentObjectWithAction('add');
        $taskInfo = ['l10nmgr_fileGarbageCollection_age' => 99];

        $this->subject->getAdditionalFields($taskInfo, null, $parentObject);

        self::assertSame(99, $taskInfo['l10nmgr_fileGarbageCollection_age']);
    }

    #[Test]
    public function getAdditionalFieldsHtmlEscapesThePatternValue(): void
    {
        $parentObject = $this->createParentObjectWithAction('add');
        $taskInfo = ['l10nmgr_fileGarbageCollection_excludePattern' => '"><script>alert(1)</script>'];

        $fields = $this->subject->getAdditionalFields($taskInfo, null, $parentObject);

        self::assertStringNotContainsString('<script>', $fields['task_fileGarbageCollection_excludePattern']['code']);
        self::assertStringContainsString('&lt;script&gt;', $fields['task_fileGarbageCollection_excludePattern']['code']);
    }

    #[Test]
    public function getAdditionalFieldsUsesTheExistingTaskValuesInEditMode(): void
    {
        $parentObject = $this->createParentObjectWithAction('edit');
        $task = $this->createTaskWithoutConstructor();
        $task->age = 45;
        $task->excludePattern = 'custom-pattern';
        $taskInfo = [];

        $this->subject->getAdditionalFields($taskInfo, $task, $parentObject);

        self::assertSame(45, $taskInfo['l10nmgr_fileGarbageCollection_age']);
        self::assertSame('custom-pattern', $taskInfo['l10nmgr_fileGarbageCollection_excludePattern']);
    }

    #[Test]
    public function validateAdditionalFieldsAcceptsAPositiveNumericAge(): void
    {
        $submittedData = ['l10nmgr_fileGarbageCollection_age' => '15'];
        $parentObject = $this->createParentObjectWithAction('add');

        self::assertTrue($this->subject->validateAdditionalFields($submittedData, $parentObject));
    }

    #[Test]
    public function validateAdditionalFieldsRejectsANonNumericAge(): void
    {
        $submittedData = ['l10nmgr_fileGarbageCollection_age' => 'not-a-number'];
        $parentObject = $this->createParentObjectWithAction('add');

        self::assertFalse($this->subject->validateAdditionalFields($submittedData, $parentObject));
    }

    #[Test]
    public function validateAdditionalFieldsRejectsAZeroOrNegativeAge(): void
    {
        $submittedData = ['l10nmgr_fileGarbageCollection_age' => '0'];
        $parentObject = $this->createParentObjectWithAction('add');

        self::assertFalse($this->subject->validateAdditionalFields($submittedData, $parentObject));
    }

    #[Test]
    public function validateAdditionalFieldsPassesThroughWhenTheAgeFieldWasNotSubmittedAtAll(): void
    {
        $submittedData = [];
        $parentObject = $this->createParentObjectWithAction('add');

        self::assertTrue($this->subject->validateAdditionalFields($submittedData, $parentObject));
    }

    #[Test]
    public function saveAdditionalFieldsCastsTheSubmittedAgeToInt(): void
    {
        $task = $this->createTaskWithoutConstructor();

        $this->subject->saveAdditionalFields(['l10nmgr_fileGarbageCollection_age' => '45'], $task);

        self::assertSame(45, $task->age);
    }

    #[Test]
    public function saveAdditionalFieldsDefaultsToZeroWhenTheAgeFieldIsMissing(): void
    {
        $task = $this->createTaskWithoutConstructor();

        $this->subject->saveAdditionalFields([], $task);

        self::assertSame(0, $task->age);
    }
}
