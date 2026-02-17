<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use DOMDocument;
use DOMXPath;
use DateTime;
use DateTimeZone;

class MetroImportService
{
    protected int $maxPages = 100;

    public function run()
    {
        $page = 1;
        $totalInserted = 0;

        while ($page <= $this->maxPages) {

            $pageUrl = env('EXTERNAL_PORTAL_URL') . '&page=' . $page;

            Log::info("Fetching page {$page}");

            $response = Http::withBasicAuth(
                env('EXTERNAL_PORTAL_USERNAME'),
                env('EXTERNAL_PORTAL_PASSWORD')
            )->get($pageUrl);

            if ($response->status() !== 200) {
                Log::error("Portal request failed on page {$page}");
                break;
            }

            libxml_use_internal_errors(true);

            $dom = new DOMDocument();
            $dom->loadHTML($response->body());
            $xpath = new DOMXPath($dom);

            $rows = $xpath->query('//table//tr');

            if ($rows->length < 2) {
                Log::info("No rows found on page {$page}");
                break;
            }

            // Extract headers (first row)
            $headers = [];
            foreach ($rows->item(0)->getElementsByTagName('th') as $th) {
                $headers[] = trim($th->textContent);
            }

            $inserted = 0;

            for ($i = 1; $i < $rows->length; $i++) {

                $cells = $rows->item($i)->getElementsByTagName('td');
                if ($cells->length === 0) continue;

                $row = [];
                foreach ($cells as $idx => $cell) {
                    $row[$headers[$idx]] = trim($cell->textContent);
                }

             $rawOrderId = $row['Order ID'] ?? null;
if (!$rawOrderId) continue;

$clientName  = $row['Priority'] ?? '';
$property    = $row['Address'] ?? '';
$instruction = $rawOrderId;
$projectType = 'Romio'; // changed from Metro to Sestmatic

// Parse date
$parsedDateTime = null;

if (!empty($row['Order Date'])) {
    $dt = DateTime::createFromFormat(
        'D d M y (h:i a)',
        trim($row['Order Date']),
        new DateTimeZone('Australia/Sydney')
    );

    if ($dt !== false) {
        $dt->modify('-6 hours'); // adjust timezone
        $parsedDateTime = $dt;
    }
}

if (!$parsedDateTime) {
    $parsedDateTime = new DateTime('now', new DateTimeZone('Asia/Karachi'));
}

$year      = $parsedDateTime->format('Y');
$month     = $parsedDateTime->format('m');
$date      = $parsedDateTime->format('d-m-Y');
$ausDatein = $parsedDateTime->format('Y-m-d H:i:s');

// due_in field from API (assuming the API provides it, otherwise null)
$dueIn = $row['Due In'] ?? null;

// Insert or update
DB::table('orders')->updateOrInsert(
    ['order_number' => $rawOrderId],
    [
        'project_id'   => 1, // یہاں اپنا default project ID ڈالیں
        'year'         => $year,
        'month'        => $month,
        'date'         => $date,
        'client_name'  => $clientName,
        'address'      => $property,
        'ausDatein'    => $ausDatein,
        'code'         => 'FP&SP',
        'plan_type'    => 'Colour',
        'instruction'  => $instruction,
        'project_type' => $projectType,
        'due_in'       => $dueIn,
        'import_source'       => 'api',
    ]
);



                $inserted++;
                $totalInserted++;
            }

            Log::info("Page {$page} processed. Inserted: {$inserted}");

            $page++;
            sleep(1);
        }

        Log::info("Completed. Total processed: {$totalInserted}");
    }
}