<?php
namespace Tests\Feature;

use Tests\TestCase;

class UploadHardeningTest extends TestCase {
    private string $testUploadDir;

    protected function setUp(): void {
        parent::setUp();
        $this->testUploadDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atsede_upload_test_' . bin2hex(random_bytes(4));
        @mkdir($this->testUploadDir, 0755, true);
    }

    protected function tearDown(): void {
        if (is_dir($this->testUploadDir)) {
            $files = glob($this->testUploadDir . '/*');
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($this->testUploadDir);
        }
        parent::tearDown();
    }

    public function testPolyglotPhpJpegIsSanitizedOrRejected(): void {
        // Create a 10x10 valid GD JPEG with PHP payload appended
        $img = imagecreatetruecolor(10, 10);
        $tmpJpeg = tempnam(sys_get_temp_dir(), 'test_poly_');
        imagejpeg($img, $tmpJpeg);
        imagedestroy($img);

        // Append PHP shell payload to bytes
        file_put_contents($tmpJpeg, '<?php phpinfo(); ?>', FILE_APPEND);

        $result = secure_process_image($tmpJpeg, $this->testUploadDir, 'avatar_test');
        @unlink($tmpJpeg);

        if ($result['success']) {
            // Must have .jpg extension and never .php
            $this->assertStringEndsWith('.jpg', $result['file_name']);
            $this->assertStringNotContainsString('.php', $result['file_name']);

            // Payload must NOT be present in re-encoded output
            $savedBytes = file_get_contents($result['full_path']);
            $this->assertStringNotContainsString('<?php phpinfo(); ?>', $savedBytes);
        } else {
            // Rejection is also safe and valid
            $this->assertFalse($result['success']);
        }
    }

    public function testValidJpegAndPngAreAccepted(): void {
        // Valid JPEG
        $img = imagecreatetruecolor(20, 20);
        $tmpJpeg = tempnam(sys_get_temp_dir(), 'valid_jpg_');
        imagejpeg($img, $tmpJpeg);
        imagedestroy($img);

        $resJpeg = secure_process_image($tmpJpeg, $this->testUploadDir, 'avatar_valid');
        @unlink($tmpJpeg);

        $this->assertTrue($resJpeg['success']);
        $this->assertFileExists($resJpeg['full_path']);
        $this->assertStringEndsWith('.jpg', $resJpeg['file_name']);

        // Valid PNG
        $img2 = imagecreatetruecolor(20, 20);
        $tmpPng = tempnam(sys_get_temp_dir(), 'valid_png_');
        imagepng($img2, $tmpPng);
        imagedestroy($img2);

        $resPng = secure_process_image($tmpPng, $this->testUploadDir, 'avatar_png');
        @unlink($tmpPng);

        $this->assertTrue($resPng['success']);
        $this->assertFileExists($resPng['full_path']);
        $this->assertStringEndsWith('.jpg', $resPng['file_name']);
    }

    public function testNonImageFileIsRejected(): void {
        $tmpFile = tempnam(sys_get_temp_dir(), 'fake_');
        file_put_contents($tmpFile, '<?php echo "evil"; ?>');

        $res = secure_process_image($tmpFile, $this->testUploadDir, 'test');
        @unlink($tmpFile);

        $this->assertFalse($res['success']);
    }

    public function testUploadDirectoriesHaveHtaccessProtection(): void {
        $uploadsHtaccess = __DIR__ . '/../../uploads/.htaccess';
        $avatarsHtaccess = __DIR__ . '/../../uploads/avatars/.htaccess';
        $coversHtaccess  = __DIR__ . '/../../uploads/covers/.htaccess';

        $this->assertFileExists($uploadsHtaccess);
        $this->assertFileExists($avatarsHtaccess);
        $this->assertFileExists($coversHtaccess);

        $content = file_get_contents($uploadsHtaccess);
        $this->assertStringContainsString('php_flag engine off', $content);
        $this->assertStringContainsString('Options -ExecCGI', $content);
    }
}
