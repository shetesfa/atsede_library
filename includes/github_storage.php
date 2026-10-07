<?php
/**
 * includes/github_storage.php  —  Atsede Library
 * Automatically uploads user-uploaded book covers to GitHub repository via GitHub REST API.
 * Ensures uploaded covers persist permanently even on ephemeral hosting platforms like Render.
 */

/**
 * Uploads a local file directly into GitHub repository under uploads/covers/
 * 
 * @param string $localFilePath Absolute path to local file on server
 * @param string $fileName Base filename (e.g. cover_123456_abcdef.jpg)
 * @return string|false Permanent Raw GitHub URL on success, or false if not configured / failed
 */
function upload_cover_to_github($localFilePath, $fileName) {
    if (!file_exists($localFilePath)) {
        return false;
    }

    // Read credentials from Environment or local config
    $token = getenv('GITHUB_TOKEN') ?: null;
    $repo  = getenv('GITHUB_REPO') ?: 'shetesfa/atsede_library';
    $branch = getenv('GITHUB_BRANCH') ?: 'main';

    if (!$token) {
        $localCfgFile = __DIR__ . '/../config.local.php';
        if (file_exists($localCfgFile)) {
            $localCfg = @include $localCfgFile;
            if (is_array($localCfg)) {
                $token = $localCfg['github_token'] ?? null;
                $repo  = $localCfg['github_repo'] ?? $repo;
                $branch = $localCfg['github_branch'] ?? $branch;
            }
        }
    }

    if (empty($token) || empty($repo)) {
        // GitHub persistent storage not configured; local disk storage remains active
        return false;
    }

    $fileContent = @file_get_contents($localFilePath);
    if ($fileContent === false) {
        return false;
    }

    $b64Content = base64_encode($fileContent);
    $apiPath = "uploads/covers/" . ltrim($fileName, '/');
    $apiUrl = "https://api.github.com/repos/{$repo}/contents/{$apiPath}";

    $payload = [
        'message' => "Upload book cover {$fileName} [skip ci]",
        'content' => $b64Content,
        'branch'  => $branch
    ];

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Atsede-Library-App',
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT        => 15
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 || $httpCode === 201) {
        // Return permanent Raw GitHub URL or CDN URL
        return "https://raw.githubusercontent.com/{$repo}/{$branch}/{$apiPath}";
    }

    error_log("GitHub Cover Upload failed with HTTP {$httpCode}: {$response} (curl: {$curlError})");
    return false;
}

/**
 * Resolves a book cover value to a fully qualified URL.
 * Handles both full URLs (stored from GitHub) and local relative filenames.
 */
function resolve_cover_url($coverImage) {
    if (empty($coverImage)) {
        return '';
    }

    // If already a full URL (GitHub Raw, Cloudinary, S3, etc.)
    if (str_starts_with($coverImage, 'http://') || str_starts_with($coverImage, 'https://')) {
        return $coverImage;
    }

    $baseUrl = defined('BASE_URL') ? BASE_URL : '/';
    $localPath = __DIR__ . '/../uploads/covers/' . ltrim($coverImage, '/');

    // If local file exists, use local URL
    if (file_exists($localPath)) {
        return rtrim($baseUrl, '/') . '/uploads/covers/' . htmlspecialchars(ltrim($coverImage, '/'), ENT_QUOTES, 'UTF-8');
    }

    // Fallback: If on Render and local file was pruned by ephemeral restart, fetch from GitHub repository
    $repo   = getenv('GITHUB_REPO') ?: 'shetesfa/atsede_library';
    $branch = getenv('GITHUB_BRANCH') ?: 'main';
    return "https://raw.githubusercontent.com/{$repo}/{$branch}/uploads/covers/" . ltrim($coverImage, '/');
}
