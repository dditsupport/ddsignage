<?php
/**
 * DangeeCast — runtime configuration TEMPLATE.
 *
 * The actual config file is NOT this one. Copy it to:
 *
 *   <deploy-root>/config_dc/config.php
 *
 * which sits OUTSIDE public_html/ so it is never servable as static text.
 * The loaders look in that location automatically — see
 * cloudserver/api/_common.php and cloudserver/admin/_db.php.
 *
 * To generate ADMIN_PASSWORD_HASH from the command line:
 *   php -r 'echo password_hash("your-password-here", PASSWORD_BCRYPT)."\n";'
 *
 * On-disk paths (media_dir, devices_dir, apk_dir, logs_dir) are injected by
 * the loaders based on cloudserver/'s actual location, so don't repeat them
 * here.
 */

return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'gtvpheud_DangeeCast',
        'user'     => 'gtvpheud_DangeeCast',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],

    'admin' => [
        'username'      => 'admin',
        'password_hash' => '$2y$10$REPLACE_WITH_A_REAL_BCRYPT_HASH_FROM_password_hash',
        'session_ttl'   => 8 * 60 * 60,
    ],

    'public_base_url' => 'https://aromen.biz/signage',

    'uploads' => [
        'max_bytes'    => 500 * 1024 * 1024,
        'image_mimes'  => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'video_mimes'  => ['video/mp4', 'video/webm', 'video/x-matroska', 'video/quicktime'],
        'audio_mimes'  => ['audio/mpeg', 'audio/mp3', 'audio/aac', 'audio/ogg', 'audio/wav'],
    ],

    'log_pings' => false,
];
