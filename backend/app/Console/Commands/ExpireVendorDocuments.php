<?php

namespace App\Console\Commands;

use App\Services\VendorKycService;
use Illuminate\Console\Command;

/**
 * Daily: KYC documents past their expiry date become Expired and the vendor
 * is notified (VendorKycService::expireDue). Scheduled in routes/console.php.
 */
class ExpireVendorDocuments extends Command
{
    protected $signature = 'kyc:expire-documents';

    protected $description = 'Mark vendor KYC documents past their expiry date as Expired and notify the vendors';

    public function handle(VendorKycService $kyc): int
    {
        $count = $kyc->expireDue();

        $this->info("{$count} document(s) marked expired.");

        return self::SUCCESS;
    }
}
