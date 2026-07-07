<?php
/**
 * DangeeCast — runtime configuration (REAL values, not for source control).
 *
 * Lives OUTSIDE public_html so PHP secrets are never servable as static text
 * even if Apache stops parsing .php for any reason. Loaded by:
 *   cloudserver/api/_common.php::api_config()
 *   cloudserver/admin/_db.php::admin_config()
 *
 * Both loaders look here first via the relative path
 *   __DIR__ . '/../../../config_dc/config.php'   (production: cloudserver = public_html/signage)
 *   __DIR__ . '/../../config_dc/config.php'      (dev: config_dc sibling of cloudserver)
 *
 * On-disk paths (media_dir, devices_dir, apk_dir, logs_dir) are NOT defined
 * here — the loaders inject them based on the actual cloudserver location.
 *
 * To regenerate ADMIN_PASSWORD_HASH:
 *   php -r 'echo password_hash("your-password-here", PASSWORD_BCRYPT)."\n";'
 */

return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'db_name',
        'user'     => 'username',
        'password' => 'password',
        'charset'  => 'utf8mb4',
    ],

    // Single-admin auth. Multi-user support is a v1.1 item.
    'admin' => [
        'username'      => 'admin',
        // Bcrypt hash. Generate via password_hash() — never store plaintext.
        'password_hash' => '$2y$10$REPLACE_WITH_A_REAL_BCRYPT_HASH_FROM_password_hash',
        'session_ttl'   => 8 * 60 * 60, // 8 hours of inactivity
    ],

    // Public-facing base URL for the deployment. Used to build the playlist
    // base_url and absolute media URLs. No trailing slash.
    'public_base_url' => 'https://yourdomain/signage',

    // Upload limits & accepted MIME types.
    'uploads' => [
        'max_bytes'    => 500 * 1024 * 1024, // 500 MB cap
        'image_mimes'  => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'video_mimes'  => ['video/mp4', 'video/webm', 'video/x-matroska', 'video/quicktime'],
        'audio_mimes'  => ['audio/mpeg', 'audio/mp3', 'audio/aac', 'audio/ogg', 'audio/wav'],
    ],

    // When true, every register/ping writes a row to device_logs.
    // Useful in dev; turn off in production once volume gets high.
    'log_pings' => false,
];
