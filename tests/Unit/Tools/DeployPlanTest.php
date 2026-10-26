<?php declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../tools/Deploy/DeployPlan.php';

use Tools\Deploy\DeployPlan;

class DeployPlanTest extends TestCase
{
    private const INCLUDES = ['src/', 'public/', '.htaccess'];

    public function testModifiedAndAddedFilesInsideIncludesAreUploaded(): void
    {
        $plan = DeployPlan::build("M\tsrc/Core/Iban.php\nA\tpublic/app.php\n", self::INCLUDES);
        $this->assertSame(['src/Core/Iban.php', 'public/app.php'], $plan['upload']);
        $this->assertSame([], $plan['delete']);
    }

    public function testFilesOutsideIncludesAreIgnored(): void
    {
        $plan = DeployPlan::build("M\ttests/Unit/Core/IbanTest.php\nM\tdocs/plan.md\nM\t.htaccess\n", self::INCLUDES);
        $this->assertSame(['.htaccess'], $plan['upload']);
    }

    public function testIncludeMatchesDirectoryPrefixNotPartialName(): void
    {
        $plan = DeployPlan::build("M\tsrcbackup/x.php\n", self::INCLUDES);
        $this->assertSame([], $plan['upload']);
    }

    public function testMarkdownFilesAreNeverUploaded(): void
    {
        $plan = DeployPlan::build("M\tsrc/Domain/CLAUDE.md\n", self::INCLUDES);
        $this->assertSame([], $plan['upload']);
    }

    public function testDeletedFilesGoToDeleteList(): void
    {
        $plan = DeployPlan::build("D\tsrc/Old.php\n", self::INCLUDES);
        $this->assertSame([], $plan['upload']);
        $this->assertSame(['src/Old.php'], $plan['delete']);
    }

    public function testRenameUploadsNewPathAndDeletesOldPath(): void
    {
        $plan = DeployPlan::build("R100\tsrc/Old.php\tsrc/New.php\n", self::INCLUDES);
        $this->assertSame(['src/New.php'], $plan['upload']);
        $this->assertSame(['src/Old.php'], $plan['delete']);
    }

    public function testWarnsWhenMigrationsOrComposerLockChange(): void
    {
        $plan = DeployPlan::build("A\tmigrations/010_x.sql\nM\tcomposer.lock\n", self::INCLUDES);
        $this->assertSame([], $plan['upload']);
        $this->assertCount(2, $plan['warnings']);
    }

    public function testEmptyDiffProducesEmptyPlan(): void
    {
        $plan = DeployPlan::build('', self::INCLUDES);
        $this->assertSame(['upload' => [], 'delete' => [], 'warnings' => []], $plan);
    }
}
