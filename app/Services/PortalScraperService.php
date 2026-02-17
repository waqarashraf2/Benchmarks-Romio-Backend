<?php
// app/Services/PortalScraperService.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use DOMDocument;
use DOMXPath;
use App\DTOs\PortalOrderDTO;

class PortalScraperService
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private const MAX_PAGES = 100;

    public function __construct()
    {
        $this->baseUrl = config('services.external_portal.url', '');
        $this->username = config('services.external_portal.username') ?? env('CAPTUR3D_USERNAME', '');
        $this->password = config('services.external_portal.password') ?? env('CAPTUR3D_PASSWORD', '');
        
        if (empty($this->username) || empty($this->password)) {
            Log::warning('Portal credentials are missing');
        }
    }

    public function fetchAllPendingOrders(): array
    {
        if (empty($this->baseUrl) || empty($this->username) || empty($this->password)) {
            Log::error('Cannot fetch orders: Portal credentials missing');
            return [];
        }

        $allOrders = [];
        $page = 1;

        while ($page <= self::MAX_PAGES) {
            $pageUrl = $this->baseUrl . '&page=' . $page;
            
            Log::info("Portal: Fetching page {$page}");
            
            $html = $this->fetchPage($pageUrl);
            if (!$html) {
                break;
            }

            $orders = $this->parseOrdersFromHtml($html, $page);
            
            if (empty($orders)) {
                Log::info("Portal: No orders found on page {$page}");
                break;
            }

            $allOrders = array_merge($allOrders, $orders);
            $page++;
            sleep(1);
        }

        Log::info("Portal: Total orders fetched: " . count($allOrders));
        return $allOrders;
    }

    private function fetchPage(string $url): ?string
    {
        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->timeout(30)
                ->withOptions(['verify' => false])
                ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
                ->get($url);

            if ($response->failed()) {
                Log::error("Portal: Failed to fetch page - HTTP {$response->status()}");
                return null;
            }

            return $response->body();
        } catch (\Exception $e) {
            Log::error("Portal: Exception fetching page - " . $e->getMessage());
            return null;
        }
    }

    private function parseOrdersFromHtml(string $html, int $page): array
    {
        $dom = $this->createDOMDocument($html);
        $xpath = new DOMXPath($dom);
        
        // Find all tables
        $tables = $xpath->query('//table');
        if ($tables->length === 0) {
            Log::warning("Portal: No tables found in HTML on page {$page}");
            return [];
        }

        // Use the first table
        $table = $tables->item(0);
        
        // Get headers from the first row
        $headers = [];
        $headerRows = $xpath->query('.//tr[1]/th', $table);
        if ($headerRows->length > 0) {
            foreach ($headerRows as $th) {
                $headers[] = trim($th->textContent) ?: 'Action'; // Name empty column as 'Action'
            }
        } else {
            Log::warning("Portal: No headers found in table on page {$page}");
            return [];
        }

        // Get data rows (skip header row)
        $dataRows = $xpath->query('.//tr[position()>1]', $table);
        if ($dataRows->length === 0) {
            return [];
        }

        $orders = [];
        foreach ($dataRows as $row) {
            $cells = $row->getElementsByTagName('td');
            if ($cells->length === 0) {
                continue;
            }

            $rowData = [];
            foreach ($cells as $idx => $cell) {
                $header = $headers[$idx] ?? "column_{$idx}";
                $rowData[$header] = trim($cell->textContent);
            }

            // Skip if no Order ID
            if (empty($rowData['Order ID'])) {
                continue;
            }

            // Log the raw data for debugging
            Log::info("Portal: Processing order: " . json_encode($rowData));
            
            $orders[] = PortalOrderDTO::fromArray($rowData);
        }

        return $orders;
    }

    private function createDOMDocument(string $html): DOMDocument
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();
        return $dom;
    }
}