<?php
// app/DTOs/PortalOrderDTO.php

namespace App\DTOs;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PortalOrderDTO
{
    public function __construct(
        public readonly string $externalOrderId,
        public readonly ?string $orderNumber,
        public readonly ?string $address, // Changed from propertyAddress
        public readonly string $priority,
        public readonly string $instruction,
        public readonly ?string $dueAt,
        public readonly string $source,
        public readonly string $status,
        public readonly ?string $ausDatein,
        public readonly ?array $rawData = []
    ) {}

    public static function fromArray(array $row): self
    {
        $rawOrderId = $row['Order ID'] ?? '';
        $orderNumber = preg_replace('/[^0-9]/', '', $rawOrderId);
        
        $priority = isset($row['Priority']) ? strtolower(trim($row['Priority'])) : 'regular';
        $parsedDateTime = self::parseOrderDate($row['Order Date'] ?? '');
        
        // Get address (from Address column)
        $address = $row['Address'] ?? '';
        
        // Parse Due In field to timestamp
        $dueAt = self::parseDueIn($row['Due In'] ?? null, $parsedDateTime);
        
        return new self(
            externalOrderId: $rawOrderId,
            orderNumber: $orderNumber ?: uniqid('PORTAL-'),
            address: $address, // Changed from propertyAddress
            priority: $priority,
            instruction: $rawOrderId,
            dueAt: $dueAt,
            source: 'captur3d_portal',
            status: 'pending',
            ausDatein: $parsedDateTime?->format('Y-m-d H:i:s'),
            rawData: array_merge($row, [
                'due_in_text' => $row['Due In'] ?? null,
                'elapsed_time' => $row['Elapsed time since order'] ?? null
            ])
        );
    }

    private static function parseOrderDate(?string $orderDate): ?Carbon
    {
        if (empty($orderDate)) {
            return Carbon::now('Asia/Karachi');
        }

        try {
            // Format: "Sat 14 Feb 26 (12:21 am)"
            $dt = Carbon::createFromFormat(
                'D d M y (h:i a)',
                trim($orderDate),
                'Australia/Sydney'
            );
            
            if ($dt) {
                // Subtract 6 hours and convert to Karachi time
                return $dt->subHours(6)->setTimezone('Asia/Karachi');
            }
        } catch (\Exception $e) {
            Log::warning("Failed to parse date: {$orderDate} - " . $e->getMessage());
        }

        return Carbon::now('Asia/Karachi');
    }

    private static function parseDueIn(?string $dueInText, ?Carbon $orderDate): ?string
    {
        if (empty($dueInText) || !$orderDate) {
            return null;
        }

        try {
            $dueInText = strtolower(trim($dueInText));
            $dueDate = clone $orderDate;
            
            if (str_contains($dueInText, 'tomorrow')) {
                $dueDate->addDay();
            } elseif (str_contains($dueInText, 'today')) {
                // Keep as is
            } elseif (preg_match('/(\d+)\s*hour/', $dueInText, $matches)) {
                $dueDate->addHours((int)$matches[1]);
            } elseif (preg_match('/(\d+)\s*day/', $dueInText, $matches)) {
                $dueDate->addDays((int)$matches[1]);
            } elseif (preg_match('/(\d+)\s*week/', $dueInText, $matches)) {
                $dueDate->addWeeks((int)$matches[1]);
            }
            
            return $dueDate->format('Y-m-d H:i:s');
            
        } catch (\Exception $e) {
            Log::warning("Failed to parse due date: {$dueInText} - " . $e->getMessage());
            return null;
        }
    }
}