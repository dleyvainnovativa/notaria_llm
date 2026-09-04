<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'project' => env('OPENAI_PROJECT'),
        // Pin a dated snapshot for reliable strict structured outputs.
        'model' => env('OPENAI_MODEL', 'gpt-4.1-mini-2025-04-14'),
        'request_timeout' => (int) env('OPENAI_REQUEST_TIMEOUT', 600),
        'max_schema_retries' => (int) env('AI_MAX_SCHEMA_RETRIES', 2),
    ],

    'ocr' => [
        'tesseract' => env('OCR_TESSERACT_PATH', 'tesseract'),
        'pdftotext' => env('OCR_PDFTOTEXT_PATH', 'pdftotext'),
        'pdftoppm' => env('OCR_PDFTOPPM_PATH', 'pdftoppm'),
        'language' => env('OCR_LANGUAGE', 'spa'),
        'min_chars_per_page' => (int) env('OCR_MIN_CHARS_PER_PAGE', 40),
        'dpi' => (int) env('OCR_DPI', 300),
    ],

];
