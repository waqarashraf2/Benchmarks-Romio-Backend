<?php
// app/Console/Commands/TestNewPortalEndpoint.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TestNewPortalEndpoint extends Command
{
    protected $signature = 'portal:test-new';
    protected $description = 'Test the new plann3d endpoint';

    public function handle()
    {
        $this->info('Testing new portal endpoint...');
        
        $url = env('EXTERNAL_PORTAL_URL');
        $username = env('EXTERNAL_PORTAL_USERNAME');
        $password = env('EXTERNAL_PORTAL_PASSWORD');
        
        $this->info("URL: " . $url);
        $this->info("Username: " . $username);
        
        try {
            $response = Http::withBasicAuth($username, $password)
                ->timeout(30)
                ->withOptions(['verify' => false])
                ->get($url);
            
            $this->info("Status Code: " . $response->status());
            
            if ($response->successful()) {
                $body = $response->body();
                $this->info("Response size: " . strlen($body) . " bytes");
                
                // Check if it's HTML or JSON
                if (str_starts_with(trim($body), '<')) {
                    $this->info("Response type: HTML");
                    
                    // Check for table
                    if (str_contains($body, '<table')) {
                        $this->info("✅ Table found in HTML");
                        
                        // Count rows
                        preg_match_all('/<tr>/i', $body, $matches);
                        $rowCount = count($matches[0] ?? []);
                        $this->info("Approximate rows: " . $rowCount);
                    } else {
                        $this->warn("❌ No table found in HTML");
                        
                        // Save HTML to file for inspection
                        $filename = storage_path('logs/portal-response-' . date('Y-m-d-H-i-s') . '.html');
                        file_put_contents($filename, $body);
                        $this->info("Saved response to: " . $filename);
                    }
                } else {
                    $this->info("Response type: JSON/Other");
                    
                    // Try to decode JSON
                    $json = json_decode($body, true);
                    if ($json) {
                        $this->info("JSON data found with " . count($json) . " items");
                        dump(array_slice($json, 0, 2));
                    } else {
                        $this->warn("Not valid JSON, saving raw response");
                        $filename = storage_path('logs/portal-response-' . date('Y-m-d-H-i-s') . '.txt');
                        file_put_contents($filename, $body);
                        $this->info("Saved response to: " . $filename);
                    }
                }
            } else {
                $this->error("Failed with status: " . $response->status());
                $this->error("Response: " . $response->body());
            }
        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage());
        }
    }
}