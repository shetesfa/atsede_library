<?php
namespace Tests\Unit;

use Tests\TestCase;

class ConfigSecurityTest extends TestCase {
    public function testConfigDoesNotContainHardcodedCredentials(): void {
        $configContent = file_get_contents(__DIR__ . '/../../config.php');
        $this->assertStringNotContainsString('953624187', $configContent);
        $this->assertStringNotContainsString('if0_41150294', $configContent);
    }

    public function testExampleConfigFilesExist(): void {
        $this->assertFileExists(__DIR__ . '/../../config.local.php.example');
        $this->assertFileExists(__DIR__ . '/../../config.remote.php.example');
        $this->assertFileExists(__DIR__ . '/../../config.php.backup.example');

        $localExample = require __DIR__ . '/../../config.local.php.example';
        $this->assertIsArray($localExample);
        $this->assertArrayHasKey('host', $localExample);
        $this->assertArrayHasKey('user', $localExample);
        $this->assertArrayHasKey('pass', $localExample);
        $this->assertArrayHasKey('db', $localExample);
    }

    public function testGitignorePatterns(): void {
        $gitignore = file_get_contents(__DIR__ . '/../../.gitignore');
        $patterns = [
            '*.sql',
            'backups/',
            'tmp/',
            'uploads/avatars/',
            'uploads/covers/*',
            'config.local.php',
            'config.remote.php',
            'config.php.backup',
            'download/*.apk',
            '*.zip',
            '.env'
        ];

        foreach ($patterns as $pattern) {
            $this->assertStringContainsString($pattern, $gitignore, "Missing gitignore pattern: $pattern");
        }
    }
}
