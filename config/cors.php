<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'register'], // Added logout, register
    
    'allowed_methods' => ['*'],
    
    'allowed_origins' => [
        'http://localhost:5173',           // Vite dev server (no trailing slash)
        'http://127.0.0.1:5173',            // Alternative local address
        'http://localhost:3000',             // Common alternative port
        'https://romio-frontend.vercel.app', // Your production frontend (note: https)
    ],
    
    'allowed_origins_patterns' => [],
    
    'allowed_headers' => ['*'],
    
    'exposed_headers' => [],
    
    'max_age' => 3600,
    
    'supports_credentials' => true, // Must be true for Sanctum
];
