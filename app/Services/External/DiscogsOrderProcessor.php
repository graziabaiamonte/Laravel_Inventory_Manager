<?php

namespace App\Services\External;

use App\Enums\RolesEnum;
use App\Enums\SaleTypeEnum;
use App\Models\Record;
use App\Models\Sale;
use App\Models\SaleRecord;
use App\Models\Stock;
use App\Models\User;
use App\Traits\LogsToChannel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class DiscogsOrderProcessor
{
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'discogs';
    }

    public function __construct(private DiscogsClient $discogsClient) {}

    /**
     * Process a single Discogs order payload: create the Sale row up-front
     * and handle each item in its own DB transaction, so one bad item cannot
     * roll back its siblings or abort the remaining items.
     *
     * Idempotent: returns early if a Sale already exists for the order id.
     *
     * When $dryRun is true, all DB writes are wrapped in an outer transaction
     * that is always rolled back, external Discogs mutations (relist) and
     * admin error emails are skipped. Useful for inspecting the effect of a
     * reconciliation against production data without any side effects.
     */
    public function processOrder(array $orderData, bool $dryRun = false): void
    {
        $existingSale = Sale::where('discogs_order_id', $orderData['id'])->first();

        if ($existingSale) {
            $this->logInfo('Skipping already processed Discogs order', [
                'discogs_order_id' => $orderData['id'],
                'existing_sale_id' => $existingSale->id,
                'existing_sale_date' => $existingSale->date,
                'dry_run' => $dryRun,
            ]);

            return;
        }

        $this->logInfo('Processing Discogs order', [
            'remote_customer_id' => $orderData['buyer']['id'] ?? null,
            'remote_customer_name' => $orderData['buyer']['username'] ?? null,
            'discogs_order_id' => $orderData['id'],
            'created' => $orderData['created'],
            'total_items' => count($orderData['items']),
            'dry_run' => $dryRun,
        ]);

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            $sale = DB::transaction(fn () => Sale::create([
                'remote_customer_id' => $orderData['buyer']['id'] ?? null,
                'remote_customer_name' => $orderData['buyer']['username'] ?? null,
                'type' => SaleTypeEnum::Web->value,
                'discogs_order_id' => $orderData['id'],
                'amount' => 0,
                'date' => Carbon::parse($orderData['created'])
                    ->setTimezone(config('services.discogs.timezone'))
                    ->format('Y-m-d H:i:s'),
            ]));

            $totalPrice = 0;
            $processedItems = 0;
            $failedItems = 0;

            foreach ($orderData['items'] as $item) {
                try {
                    DB::transaction(function () use ($sale, $item, $orderData, $dryRun) {
                        $this->processOrderItem($sale, $item, $orderData['id'], $dryRun);
                    });
                    $totalPrice += $item['price']['value'] ?? 0;
                    $processedItems++;
                } catch (\Throwable $e) {
                    $failedItems++;
                    $this->logError('Item processing failed; continuing with remaining items', [
                        'discogs_order_id' => $orderData['id'],
                        'sale_id' => $sale->id,
                        'listing_id' => $item['id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $sale->update([
                'amount' => $totalPrice,
            ]);

            $this->logInfo(($dryRun ? 'DRY-RUN: would create' : 'Created')." sale #{$sale->id} for Discogs order #{$orderData['id']}", [
                'sale_id' => $sale->id,
                'total_items' => count($orderData['items']),
                'processed_items' => $processedItems,
                'failed_items' => $failedItems,
                'dry_run' => $dryRun,
            ]);
        } finally {
            if ($dryRun) {
                DB::rollBack();
                $this->logInfo('DRY-RUN: rolled back all DB writes for order', [
                    'discogs_order_id' => $orderData['id'],
                ]);
            }
        }
    }

    private function processOrderItem(Sale $sale, array $item, string $discogs_order_id, bool $dryRun = false): void
    {
        $listingId = $item['id'];
        $itemPrice = $item['price']['value'] ?? 0;

        $this->logInfo("Processing item (listing) #{$listingId}");

        $record = Record::where('discogs_id', $listingId)->first();

        if (! $record) {
            $this->logError("Item not found: Listing ID {$listingId}");
            $this->sendErrorEmail("Discogs order #{$discogs_order_id}: Item not found: Listing ID {$listingId}", $dryRun);

            throw new \Exception("Record not found for Discogs listing ID: {$listingId}");
        }

        $listingDetails = $this->discogsClient->getListing($record);

        if (! $listingDetails['success']) {
            $this->logError("Could not fetch listing details from Discogs: Listing ID {$listingId}");
            $this->sendErrorEmail("Discogs order #{$discogs_order_id}: could not fetch listing details from Discogs: Listing ID {$listingId}", $dryRun);

            throw new \Exception("Could not fetch listing details for listing ID: {$listingId}");
        }

        $location = $listingDetails['data']['location'] ?? null;

        $this->logInfo("Location for listing ID {$listingId}", [
            'location' => $location,
        ]);

        $locationId = null;
        if ($location) {
            $locationId = (int) explode(' - ', $location)[0];

            $this->logInfo('Extracted location ID', [
                'location_id' => $locationId,
                'location' => $location,
            ]);
        }

        $stock = null;

        if ($locationId) {
            $stock = Stock::where('record_id', $record->id)
                ->where('area_id', $locationId)
                ->where('quantity', '>', 0)
                ->first();
        }

        if (! $stock) {
            $stock = Stock::where('record_id', $record->id)
                ->where('quantity', '>', 0)
                ->ordered()
                ->first();
        }

        if (! $stock) {
            $this->logError("No stock available for Record ID {$record->id}, Listing ID {$listingId}");
            $this->sendErrorEmail("Discogs order #{$discogs_order_id}: No stock available for Record ID {$record->id}, Listing ID {$listingId}", $dryRun);

            throw new \Exception("No stock available for record ID: {$record->id}");
        }

        SaleRecord::create([
            'sale_id' => $sale->id,
            'record_id' => $record->id,
            'stock_id' => $stock->id,
            'quantity' => 1,
            'price' => $itemPrice,
            'discount' => 0,
            'total_price' => $itemPrice,
            'discogs_id' => $listingId,
        ]);

        $stock->decrement('quantity', 1);

        $record->updateQuietly(['discogs_id' => null]);

        $remainingStock = Stock::where('record_id', $record->id)->sum('quantity');

        if ($remainingStock > 0 && $record->for_sale_on_discogs) {
            $relistingStock = Stock::where('record_id', $record->id)
                ->where('quantity', '>', 0)
                ->ordered()
                ->first();

            if ($dryRun) {
                $this->logInfo('DRY-RUN: would relist record on Discogs', [
                    'record_id' => $record->id,
                    'area_id' => $relistingStock->area_id,
                ]);
            } else {
                $this->logInfo("Relisting record on Discogs: Record ID {$record->id}");

                try {
                    $result = $this->discogsClient->listItem($record, $relistingStock->area);

                    if ($result['success']) {
                        $this->logInfo('Successfully relisted record on Discogs', [
                            'record_id' => $record->id,
                            'new_listing_id' => $record->fresh()->discogs_id,
                        ]);
                    } else {
                        $this->logError('Failed to relist record on Discogs', [
                            'record_id' => $record->id,
                            'error' => $result['error'] ?? 'Unknown error',
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->logError('Error relisting record on Discogs', [
                        'record_id' => $record->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logInfo('Processed Discogs SaleRecord', [
            'sale_id' => $sale->id,
            'record_id' => $record->id,
            'stock_id' => $stock->id,
            'quantity' => 1,
            'price' => $itemPrice,
            'discount' => 0,
            'total_price' => $itemPrice,
            'remaining_stock' => $remainingStock,
        ]);
    }

    private function sendErrorEmail(string $message, bool $dryRun = false): void
    {
        if ($dryRun) {
            $this->logInfo('DRY-RUN: would email admins', [
                'message' => $message,
            ]);

            return;
        }

        try {
            $recipientEmails = User::role(RolesEnum::Admin->value)
                ->get()
                ->pluck('email')
                ->filter(fn (?string $email) => $email && str_ends_with(strtolower($email), '@atomicastudio.com'))
                ->values()
                ->toArray();

            if (empty($recipientEmails)) {
                $this->logWarning('No @atomicastudio.com admin users found to send error email');

                return;
            }

            Mail::raw($message, function ($mail) use ($recipientEmails) {
                $mail->to($recipientEmails)
                    ->subject('Discogs Order Processing Error');
            });

            $this->logInfo('Error email sent to atomicastudio admins', [
                'recipient_emails' => $recipientEmails,
                'recipient_count' => count($recipientEmails),
            ]);

        } catch (\Exception $e) {
            $this->logError('Failed to send error email to admins', [
                'message' => $message,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
