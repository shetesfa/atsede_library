<?php
namespace Tests\Feature;

use Tests\TestCase;

class SetupSecurityTest extends TestCase {
    public function testSetupScriptBlockedWhenLockFileExists(): void {
        $lockFile = __DIR__ . '/../../database/.installed';
        file_put_contents($lockFile, "installed\n");

        try {
            ob_start();
            include __DIR__ . '/../../setup.php';
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
        }

        $this->assertEquals(404, http_response_code());
        @unlink($lockFile);
    }

    public function testSetupScriptRejectsShortPasswordAndMissingCsrf(): void {
        $lockFile = __DIR__ . '/../../database/.installed';
        @unlink($lockFile);

        // Test with short password and invalid csrf
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'invalid_token';
        $_POST['admin_password'] = 'short';

        ob_start();
        include __DIR__ . '/../../setup.php';
        $output = ob_get_clean();

        $this->assertStringContainsString('CSRF', $output);
        $this->assertFalse(file_exists($lockFile));
    }

    public function testSetupWebhookRequiresAdminSession(): void {
        // Unauthenticated access
        $_SESSION = [];
        ob_start();
        try {
            include __DIR__ . '/../../setup_webhook.php';
        } catch (\Throwable $e) {}
        $output = ob_get_clean();

        $this->assertEquals(403, http_response_code());
        $this->assertStringContainsString('403', $output);

        // Member access
        $this->loginAs('member');
        ob_start();
        try {
            include __DIR__ . '/../../setup_webhook.php';
        } catch (\Throwable $e) {}
        $output = ob_get_clean();

        $this->assertEquals(403, http_response_code());
    }

    public function testSetupWebhookCodeAudit(): void {
        $code = file_get_contents(__DIR__ . '/../../setup_webhook.php');
        $this->assertStringNotContainsString('atsede2024', $code);
        $this->assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER => false', $code);
        $this->assertStringContainsString('inline_query', $code);
        $this->assertStringContainsString('callback_query', $code);
        $this->assertStringContainsString('telegram_webhook_secret', $code);
    }
}
