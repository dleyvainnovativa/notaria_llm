<?php

return [
    'api_key' => env('OPENAI_API_KEY'),
    'organization' => env('OPENAI_ORGANIZATION'),
    'project' => env('OPENAI_PROJECT'),
    // Pin a dated snapshot for reliable strict structured outputs.
    'model' => env('OPENAI_MODEL', 'gpt-4.1-mini-2025-04-14'),
    'request_timeout' => (int) env('OPENAI_REQUEST_TIMEOUT', 600),
    'max_schema_retries' => (int) env('AI_MAX_SCHEMA_RETRIES', 2),
];
