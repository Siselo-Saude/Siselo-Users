<?php
declare(strict_types=1);

return [
  'frontend_origin' => getenv('FRONTEND_ORIGIN') ?: 'http://localhost:3000',
  'coopere' => [
    'allowed_group' => getenv('COOPERE_ALLOWED_GROUP') ?: 'Teste - UBS/CADH',
    'group_id' => getenv('COOPERE_GROUP_ID') ?: getenv('COOPERE_ALLOWED_GROUP') ?: 'Teste - UBS/CADH',
    'provider' => getenv('COOPERE_PROVIDER') ?: 'coopere',
    'token_secret' => getenv('COOPERE_TOKEN_SECRET') ?: '',
    'token_ttl_seconds' => (int)(getenv('COOPERE_TOKEN_TTL_SECONDS') ?: 900),
    'dev_mode' => getenv('COOPERE_DEV_MODE') === '1',
    'api_base_url' => getenv('COOPERE_API_BASE_URL') ?: '',
    'api_username' => getenv('COOPERE_API_USERNAME') ?: '',
    'api_password' => getenv('COOPERE_API_PASSWORD') ?: '',
    'api_timeout_seconds' => (int)(getenv('COOPERE_API_TIMEOUT_SECONDS') ?: 10),
  ],
];
