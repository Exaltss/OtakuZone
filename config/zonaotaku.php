<?php
/**
 * Zonaotaku v2-final-cpanel
 * File   : config/zonaotaku.php
 * Fungsi : seed runtime config dari .env, sumber kebenaran tetap settings DB
 * Rujuk  : ZONAOTAKU_V2_FINAL.md §2.1, §4, §5, §10.1
 */
declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Zonaotaku'),
    'url' => env('APP_URL', 'https://zonaotaku.my.id'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),

    'animeku' => [
        'base_url_primary' => env('ANIMEKU_BASE', 'https://pencarinafkah.xyz/vA6//api'),
        'base_url_fallback_1' => env('ANIMEKU_BASE_FALLBACK_1'),
        'base_url_fallback_2' => env('ANIMEKU_BASE_FALLBACK_2'),
        'api_key' => env('ANIMEKU_API_KEY'),
        'timeout' => (int) env('ANIMEKU_TIMEOUT', 20),
        'throttle_ms' => (int) env('SYNC_THROTTLE_MS', 400),
        'jitter_ms' => (int) env('SYNC_JITTER_MS', 300),
        'pages' => (int) env('SYNC_PAGES', 3),
        'worker_relay_url' => env('CF_WORKER_RELAY_URL'),
        'stream_header_profile' => env('ANIMEKU_STREAM_HEADER_PROFILE', 'default'),
        'extra_quality_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('ANIMEKU_EXTRA_QUALITY_KEYS', ''))))),
        'upstream_whitelist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ANIMEKU_UPSTREAM_WHITELIST', 'pencarinafkah.xyz,whatbox.ca'))))),
        'headers' => [
            'User-Agent' => env('ANIMEKU_UA', 'Dalvik/7.1.12.1.0 (com.newanimeku.animechanneldonghuasubindosubenglish U; Android ; 20175 Build/NMF260)'),
            'Data-Agent' => env('ANIMEKU_DATA_AGENT', 'Your Videos Channel'),
            'Accept' => env('ANIMEKU_ACCEPT', 'application/vnd.yourapi.v1.full+json'),
            'Cache-Control' => 'max-age=0',
        ],
        'endpoint_templates' => [
            'videos' => '/get_videos?page={page}&count={count}&api_key={api_key}',
            'popular' => '/get_category_popular?page={page}&count={count}&api_key={api_key}',
            'genre' => '/get_category_genre?search={genre}&count={count}&api_key={api_key}',
            'detail' => '/get_anime_detail?id={id}&api_key={api_key}',
            'post_detail' => '/get_post_detail?id={id}&api_key={api_key}',
            'next_previous' => '/get_post_detail_next_provious?id={id}&cat_id={cat_id}&api_key={api_key}',
        ],
    ],

    'stream' => [
        'secret' => env('STREAM_SECRET'),
        'token_ttl' => (int) env('STREAM_TOKEN_TTL', 2100),
        'guest_token_ttl' => (int) env('STREAM_GUEST_TOKEN_TTL', 1200),
        'nonce_ttl' => (int) env('STREAM_NONCE_TTL', 30),
        'chunk_size' => (int) env('STREAM_CHUNK_SIZE', 2097152),
        'bound_ip' => (bool) env('STREAM_BOUND_IP', true),
        'bound_ua' => (bool) env('STREAM_BOUND_UA', true),
        'default_quality' => (string) env('STREAM_DEFAULT_QUALITY', '720'),
        'max_concurrent' => (int) env('MAX_STREAM_CONCURRENT', 12),
        'per_user_max' => (int) env('PER_USER_STREAM_MAX', 2),
        'qualities' => ['360', '480', '720', '1080'],
    ],

    'security' => [
        'audit_salt' => env('AUDIT_SALT'),
        'cipher_keys' => env('CIPHER_KEYS'),
        'log_level' => env('LOG_LEVEL', 'error'),
        'log_sanitize' => (bool) env('LOG_SANITIZE', true),
        'open_registration' => (bool) env('OPEN_REGISTRATION', false),
        'admin_2fa_enabled' => (bool) env('ADMIN_2FA_ENABLED', true),
    ],

    'sync' => [
        'pages' => (int) env('SYNC_PAGES', 3),
        'throttle_ms' => (int) env('SYNC_THROTTLE_MS', 400),
        'jitter_ms' => (int) env('SYNC_JITTER_MS', 300),
    ],

    'throttle' => [
        'catalog' => (int) env('THROTTLE_KATALOG', 60),
        'sign' => (int) env('THROTTLE_SIGN', 20),
        'nonce' => (int) env('THROTTLE_SIGN', 20),
        'login' => (int) env('THROTTLE_LOGIN', 5),
        'guest_sign' => (int) env('THROTTLE_GUEST_SIGN', 30),
    ],

    'backup' => [
        'r2_account_id' => env('R2_ACCOUNT_ID'),
        'r2_access_key_id' => env('R2_ACCESS_KEY_ID'),
        'r2_secret_access_key' => env('R2_SECRET_ACCESS_KEY'),
        'r2_bucket' => env('R2_BUCKET', 'zonaotaku-backup'),
        'r2_endpoint' => env('R2_ENDPOINT'),
        'retain_weeks' => (int) env('BACKUP_RETAIN_WEEKS', 4),
        'encrypt_key' => env('BACKUP_ENCRYPT_KEY'),
    ],
];
