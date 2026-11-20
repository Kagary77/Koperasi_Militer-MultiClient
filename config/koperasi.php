<?php

return [
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin Pusat'),
        'email' => env('SUPER_ADMIN_EMAIL', 'pusat@koperasi.test'),
        'password' => env('SUPER_ADMIN_PASSWORD', 'password'),
    ],

    // Wajib MFA bagi Super Admin Pusat & Admin Koperasi (SRS v3.2 FR-KOP-01 & NFR-SEC-01).
    'enforce_mfa' => (bool) env('KOPERASI_ENFORCE_MFA', env('APP_ENV') === 'production'),
];
