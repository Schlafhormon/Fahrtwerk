<?php

return [
    'organization_name' => env('ORGANIZATION_NAME', 'Kommunale Verwaltung'), 'organization_address' => '',
    'allow_private_km' => env('ALLOW_PRIVATE_KM', true), 'allow_commute_km' => env('ALLOW_COMMUTE_KM', true),
    'enforce_odometer_continuity' => env('ENFORCE_ODOMETER_CONTINUITY', false), 'audit_log_enabled' => env('AUDIT_LOG_ENABLED', true),
    'initial_password_mode' => env('INITIAL_PASSWORD_MODE', 'random'), 'allow_self_registration' => false,
    'bootstrap_username' => env('BOOTSTRAP_ADMIN_USERNAME', 'admin'), 'bootstrap_password' => env('BOOTSTRAP_ADMIN_PASSWORD'),
    'page_size' => 25, 'default_period' => 'monthly', 'number_format' => 'de-DE', 'date_format' => 'd.m.Y', 'retention_years' => 10,
    'demo_enabled' => env('DEMO_ENABLED', false),
];
