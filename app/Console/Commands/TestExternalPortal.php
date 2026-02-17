<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestExternalPortal extends Command
{
    protected $signature = 'portal:test';
    protected $description = 'Test external portal response';

    public function handle()
    {
        $url = env('EXTERNAL_PORTAL_URL');
        $username = env('EXTERNAL_PORTAL_USERNAME');
        $password = env('EXTERNAL_PORTAL_PASSWORD');

        $response = Http::withBasicAuth($username, $password)
            ->get($url);

        $this->info("Status Code: " . $response->status());

        // Raw response دیکھنے کیلئے
        dump($response->body());

        // اگر JSON ہو
        if ($response->json()) {
            dump($response->json());
        }

        return 0;
    }
}