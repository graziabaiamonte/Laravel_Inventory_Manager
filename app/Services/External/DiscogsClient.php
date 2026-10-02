<?php

namespace App\Services\External;

use App\Contracts\ExternalStoreServiceInterface;
use App\Models\Area;
use App\Models\Record;
use App\Traits\LogsToChannel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DiscogsClient implements ExternalStoreServiceInterface
{
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'discogs';
    }

    private string $baseUrl;

    private string $token;

    private string $userAgent;

    public function __construct()
    {
        $this->baseUrl = config('services.discogs.base_url');
        $this->token = config('services.discogs.token');
        $this->userAgent = config('services.discogs.user_agent');
    }

    /**
     * Retrieve detailed information about a specific release from Discogs.
     *
     * @param  string  $release_id  The Discogs release ID to fetch
     * @return array Returns success status, release data (title, artist, catalog number, label, barcode, image URL), and any error message
     */
    public function getRelease(string $release_id): array
    {
        try {
            $response = $this->makeRequest('GET', "/releases/{$release_id}");
            $resultArr = $response->json();

            if (! $resultArr) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'No data received from Discogs API',
                ];
            }

            $release = [
                'title' => $this->cleanString($resultArr['title'] ?? ''),
                'artist' => $this->cleanString($resultArr['artists'][0]['name'] ?? ''),
                'catno' => $resultArr['labels'][0]['catno'] ?? '',
                'label' => $this->cleanString($resultArr['labels'][0]['name'] ?? ''),
                'imgURL' => $resultArr['images'][0]['uri'] ?? $resultArr['thumb'] ?? '',
                'releaseId' => $resultArr['id'] ?? '',
            ];

            if (is_array($resultArr['identifiers'])) {
                foreach ($resultArr['identifiers'] as $identifiers) {
                    if ($identifiers['type'] == 'Barcode') {
                        $release['barcode'] = $identifiers['value'];
                        break;
                    }
                }
            }

            return [
                'success' => true,
                'data' => $release,
                'error' => null,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Search for releases on Discogs using barcode and/or catalog number.
     *
     * @param  string|null  $barcode  The barcode to search for (optional)
     * @param  string|null  $cat_number  The catalog number to search for (optional)
     * @return array Returns success status, array of matching releases with details, and any error message
     */
    public function search(?string $barcode, ?string $cat_number): array
    {
        try {
            $queryParams = ['type' => 'release'];
            if ($barcode) {
                $queryParams['barcode'] = $barcode;
            }
            if ($cat_number) {
                $queryParams['catno'] = $cat_number;
            }

            $response = $this->makeRequest('GET', '/database/search', $queryParams);
            $resultArr = $response->json();

            if (! $resultArr) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'No data received from Discogs API',
                ];
            }

            $releases = [];
            if ($resultArr && count($resultArr['results'])) {
                foreach ($resultArr['results'] as $result) {

                    $retBarcode = '';

                    if (! $barcode && ! empty($result['barcode'])) {
                        $retBarcode = str_replace('-', '', str_replace(' ', '', $result['barcode'][0]));
                    } else {
                        $retBarcode = $barcode;
                    }

                    $release = [
                        'id' => $result['id'] ?? '',
                        'title' => $result['title'] ?? '',
                        'catno' => $result['catno'] ?? '',
                        'barcode' => $retBarcode ?? '',
                        'country' => $result['country'] ?? '',
                        'label' => $result['label'][0] ?? '',
                        'cover_image' => $result['cover_image'] ?? '',
                        'thumb' => $result['thumb'] ?? '',
                        'community_want' => $result['community']['want'] ?? 0,
                        'uri' => isset($result['uri']) ? 'https://www.discogs.com'.$result['uri'] : '',
                        'url' => $result['resource_url'] ?? '',
                    ];

                    $releases[] = $release;

                }
            }

            return [
                'success' => true,
                'data' => $releases,
                'error' => null,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => [],
                'error' => $e->getMessage(),
            ];
        }

    }

    /**
     * Get details of a specific marketplace listing from Discogs.
     *
     * @param  Record  $record  The record to get listing info
     * @return array Returns success status, listing data, and any error message
     */
    public function getListing(Record $record): array
    {
        $this->logDetail('DISCOGS: getListing method called', [
            'record_id' => $record->id,
            'discogs_id' => $record->discogs_id,
        ]);

        try {
            if (! $record->discogs_id) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'No Discogs listing ID found for this record',
                ];
            }

            $response = $this->makeRequest('GET', "/marketplace/listings/{$record->discogs_id}");
            $resultArr = $response->json();

            if (! $resultArr) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'No data received from Discogs API',
                ];
            }

            return [
                'success' => true,
                'data' => $resultArr,
                'error' => null,
            ];

        } catch (\Illuminate\Http\Client\RequestException $e) {
            $errorMessage = 'Discogs API Error: '.$e->getMessage();

            $response = $e->response;
            if ($response) {
                $body = $response->json();
                if (isset($body['message'])) {
                    $errorMessage = $body['message'];
                }
            }

            $this->logError('DISCOGS: HTTP Request Exception in getListing:', [
                'message' => $errorMessage,
                'response_body' => $response ? $response->body() : null,
                'record_id' => $record->id,
                'discogs_id' => $record->discogs_id,
            ]);

            return [
                'success' => false,
                'error' => $errorMessage,
            ];
        } catch (\Exception $e) {
            $this->logError('DISCOGS: Exception caught in getListing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'record_id' => $record->id,
                'discogs_id' => $record->discogs_id,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a new marketplace listing on Discogs for a record.
     * Updates the record with the returned Discogs listing ID on success.
     *
     * @param  Record  $record  The record to list for sale
     * @param  Area|null  $area  Optional Area information for the listing
     * @return array Returns success status, listing data, and any error message
     */
    public function listItem(Record $record, ?Area $area = null): array
    {
        $this->logDetail('DISCOGS: listItem method called', [
            'record_id' => $record->id,
            'for_sale_on_discogs' => $record->for_sale_on_discogs,
        ]);

        try {
            $this->logDetail('DISCOGS: About to build payload');

            $payload = $this->buildListingPayload($record, $area);

            $this->logInfo('DISCOGS: Payload built successfully', ['payload' => $payload]);

            $this->logInfo('DISCOGS: About to call makeRequest');

            $response = $this->makeRequest('POST', '/marketplace/listings', $payload);

            $this->logInfo('DISCOGS: HTTP request completed');

            $result = $response->json();

            if ($response->successful() && isset($result['listing_id'])) {
                // Store the Discogs listing ID: this is the only place records.discogs_id is set
                $record->updateQuietly(['discogs_id' => $result['listing_id']]);

                return [
                    'success' => true,
                    'data' => $result,
                ];
            }

            // A 2xx without a listing_id (or any response that did not throw but is not a
            // confirmed listing) must NOT be reported as success: otherwise the record would be
            // shown as listed without a real Discogs listing behind it.
            $this->logError('DISCOGS: listItem did not return a listing_id', [
                'record_id' => $record->id,
                'status' => $response->status(),
                'response_body' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => $result['message'] ?? 'Discogs did not return a listing_id',
                'data' => $result,
            ];
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $errorMessage = 'Discogs API Error: '.$e->getMessage();

            $response = $e->response;
            if ($response) {
                $body = $response->json();
                if (isset($body['message'])) {
                    $errorMessage = $body['message']; // Show the full Discogs message
                }
            }

            $this->logError('DISCOGS: HTTP Request Exception:', [
                'message' => $errorMessage,
                'response_body' => $response ? $response->body() : null,
                'record_id' => $record->id,
            ]);

            return [
                'success' => false,
                'error' => $errorMessage,
            ];
        } catch (\Exception $e) {
            $this->logError('DISCOGS: Exception caught in listItem', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update an existing marketplace listing on Discogs for a record.
     * Requires the record to have a valid discogs_id from a previous listing.
     *
     * @param  Record  $record  The record with an existing Discogs listing to update
     * @param  Area|null  $area  Optional area information for the listing
     * @return array Returns success status, updated listing data, and any error message
     */
    public function updateListing(Record $record, ?Area $area = null): array
    {
        $this->logDetail('DISCOGS: updateListing method called', [
            'record_id' => $record->id,
            'discogs_id' => $record->discogs_id,
        ]);

        try {
            if (! $record->discogs_id) {
                throw new \Exception('No Discogs listing ID found for this record');
            }

            $this->logDetail('DISCOGS: About to build payload');

            $payload = $this->buildListingPayload($record, $area);

            $this->logInfo('DISCOGS: Payload built successfully', ['payload' => $payload]);

            $this->logInfo('DISCOGS: About to call makeRequest');

            $listingId = $record->discogs_id;
            $response = $this->makeRequest('POST', "/marketplace/listings/{$listingId}", $payload);

            $this->logInfo('DISCOGS: HTTP request completed');

            $result = $response->json();

            return [
                'success' => true,
                'data' => $result,
            ];
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $errorMessage = 'Discogs API Error: '.$e->getMessage();

            $response = $e->response;
            if ($response) {
                $body = $response->json();
                if (isset($body['message'])) {
                    $errorMessage = $body['message']; // Show the full Discogs message
                }
            }

            $this->logError('DISCOGS: HTTP Request Exception:', [
                'message' => $errorMessage,
                'response_body' => $response ? $response->body() : null,
                'record_id' => $record->id,
            ]);

            return [
                'success' => false,
                'error' => $errorMessage,
            ];
        } catch (\Exception $e) {
            $this->logError('DISCOGS: Exception caught in updateListing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Remove a marketplace listing from Discogs for a record.
     * Clears the record's discogs_id field on successful deletion.
     *
     * @param  Record  $record  The record with an existing Discogs listing to delete
     * @return array Returns success status, response data, and any error message
     */
    public function deleteListing(Record $record): array
    {
        $this->logDetail('DISCOGS: deleteListing method called', [
            'record_id' => $record->id,
            'for_sale_on_discogs' => $record->for_sale_on_discogs,
            'discogs_id' => $record->discogs_id,
        ]);

        try {
            $this->logInfo('DISCOGS: About to call makeRequest');

            $listingId = $record->discogs_id;
            $response = $this->makeRequest('DELETE', "/marketplace/listings/{$listingId}");

            $this->logInfo('DISCOGS: HTTP request completed', ['response' => $response]);

            $result = $response->json();

            if ($response->successful()) {
                $record->updateQuietly(['discogs_id' => null]);
            }

            return [
                'success' => true,
                'data' => $result,
            ];
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $errorMessage = 'Discogs API Error: '.$e->getMessage();

            $response = $e->response;
            if ($response) {
                $body = $response->json();
                if (isset($body['message'])) {
                    $errorMessage = $body['message']; // Show the full Discogs message
                }
            }

            $this->logError('DISCOGS: HTTP Request Exception:', [
                'message' => $errorMessage,
                'response_body' => $response ? $response->body() : null,
                'record_id' => $record->id,
            ]);

            return [
                'success' => false,
                'error' => $errorMessage,
            ];
        } catch (\Exception $e) {
            $this->logError('DISCOGS: Exception caught in deleteItem', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Delete a specific marketplace listing by its listing id.
     *
     * Unlike deleteListing(), this is not tied to a Record's discogs_id, so it can
     * remove arbitrary listings (e.g. the surplus listings from a multi-live record,
     * or a mismatched listing) without touching any record state. Idempotent: a
     * listing that is already gone (404) is treated as success.
     *
     * @param  int  $listingId  The Discogs marketplace listing id to delete
     * @return array success flag, whether it was already gone, remaining rate-limit, and any error
     */
    public function deleteListingById(int $listingId): array
    {
        try {
            $response = $this->makeRequest('DELETE', "/marketplace/listings/{$listingId}");

            return [
                'success' => true,
                'gone' => false,
                'rate_limit_remaining' => $response->header('X-Discogs-Ratelimit-Remaining'),
            ];
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $status = $e->response?->status();

            // Already deleted / never existed: idempotent no-op.
            if ($status === 404) {
                return [
                    'success' => true,
                    'gone' => true,
                    'rate_limit_remaining' => $e->response?->header('X-Discogs-Ratelimit-Remaining'),
                ];
            }

            $errorMessage = 'Discogs API Error: '.$e->getMessage();
            $body = $e->response?->json();
            if (is_array($body) && isset($body['message'])) {
                $errorMessage = $body['message'];
            }

            $this->logError('DISCOGS: deleteListingById failed', [
                'listing_id' => $listingId,
                'status' => $status,
                'message' => $errorMessage,
            ]);

            return [
                'success' => false,
                'error' => $errorMessage,
                'rate_limit_remaining' => $e->response?->header('X-Discogs-Ratelimit-Remaining'),
            ];
        } catch (\Exception $e) {
            $this->logError('DISCOGS: deleteListingById exception', [
                'listing_id' => $listingId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch orders from Discogs marketplace
     *
     * @param  array  $params  Query parameters for the orders request
     * @return array Returns array of orders or empty array on failure
     */
    public function getOrders(array $params = []): array
    {
        $queryParams = [
            'sort' => 'created',
            'sort_order' => 'desc',
            'created_after' => $params['created_after'] ?? now()->subDay()->startOfDay()->toISOString(),
        ];

        try {
            $response = $this->makeRequest('GET', '/marketplace/orders', $queryParams);
            $result = $response->json();

            return $result['orders'] ?? [];

        } catch (\Exception $e) {
            $this->logError('DISCOGS: Failed to fetch orders', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);

            return [];
        }
    }

    /**
     * Fetch a single order by ID from Discogs marketplace.
     *
     * @param  string  $orderId  The Discogs order ID (e.g. "1307226-69202")
     * @return array Returns success status, order data, and any error message
     */
    public function getOrder(string $orderId): array
    {
        $this->logDetail('DISCOGS: getOrder method called', [
            'order_id' => $orderId,
        ]);

        try {
            $response = $this->makeRequest('GET', "/marketplace/orders/{$orderId}");
            $resultArr = $response->json();

            if (! $resultArr) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'No data received from Discogs API',
                ];
            }

            return [
                'success' => true,
                'data' => $resultArr,
                'error' => null,
            ];

        } catch (\Illuminate\Http\Client\RequestException $e) {
            $errorMessage = 'Discogs API Error: '.$e->getMessage();

            $response = $e->response;
            if ($response) {
                $body = $response->json();
                if (isset($body['message'])) {
                    $errorMessage = $body['message'];
                }
            }

            $this->logError('DISCOGS: HTTP Request Exception in getOrder:', [
                'message' => $errorMessage,
                'response_body' => $response ? $response->body() : null,
                'order_id' => $orderId,
            ]);

            return [
                'success' => false,
                'error' => $errorMessage,
            ];
        } catch (\Exception $e) {
            $this->logError('DISCOGS: Exception caught in getOrder', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch one page of the authenticated seller's marketplace inventory.
     *
     * Discogs has no batch "get many listings" endpoint, so this is the bulk
     * primitive used to reconcile our records against what is actually live:
     * we page through the whole inventory once instead of curling each listing.
     *
     * @param  int  $page  1-based page number
     * @param  int  $perPage  listings per page (Discogs caps this at 100)
     * @param  string  $status  Discogs listing status to filter on (e.g. 'For Sale')
     * @return array success flag, listings array, pagination block, remaining rate-limit, and any error
     */
    public function getInventory(int $page = 1, int $perPage = 100, string $status = 'For Sale'): array
    {
        $username = config('services.discogs.username');

        if (empty($username)) {
            return [
                'success' => false,
                'error' => 'DISCOGS_USERNAME is not configured',
            ];
        }

        try {
            $response = $this->makeRequest('GET', "/users/{$username}/inventory", [
                'status' => $status,
                'page' => $page,
                'per_page' => $perPage,
                'sort' => 'listed',
                'sort_order' => 'asc',
            ]);

            $body = $response->json();

            return [
                'success' => true,
                'listings' => $body['listings'] ?? [],
                'pagination' => $body['pagination'] ?? [],
                'rate_limit_remaining' => $response->header('X-Discogs-Ratelimit-Remaining'),
                'error' => null,
            ];
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $errorMessage = 'Discogs API Error: '.$e->getMessage();
            $response = $e->response;

            if ($response) {
                $body = $response->json();
                if (isset($body['message'])) {
                    $errorMessage = $body['message'];
                }
            }

            $this->logError('DISCOGS: HTTP Request Exception in getInventory', [
                'message' => $errorMessage,
                'username' => $username,
                'page' => $page,
                'status' => $status,
            ]);

            return [
                'success' => false,
                'error' => $errorMessage,
            ];
        } catch (\Exception $e) {
            $this->logError('DISCOGS: Exception caught in getInventory', [
                'error' => $e->getMessage(),
                'username' => $username,
                'page' => $page,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Make authenticated HTTP requests to the Discogs API.
     * Handles authentication headers, timeouts, retries, and error logging.
     *
     * @param  string  $method  HTTP method (GET, POST, PUT, PATCH, DELETE)
     * @param  string  $endpoint  API endpoint path
     * @param  array  $data  Request payload data
     * @return Response The HTTP response object
     *
     * @throws \InvalidArgumentException For unsupported HTTP methods
     * @throws \Illuminate\Http\Client\RequestException For HTTP errors
     */
    private function makeRequest(string $method, string $endpoint, array $data = []): Response
    {
        $http = Http::withHeaders([
            'Authorization' => "Discogs token={$this->token}",
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->retry(3, 1000) // Retry 3 times with 1 second delay
            ->throw(); // Throw exceptions on HTTP errors

        $url = $this->baseUrl.$endpoint;

        $response = match (strtoupper($method)) {
            'GET' => $http->get($url, $data),
            'POST' => $http->post($url, $data),
            'PUT' => $http->put($url, $data),
            'PATCH' => $http->patch($url, $data),
            'DELETE' => $http->delete($url, $data),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}")
        };

        // Log the actual response before throwing
        if (! $response->successful()) {
            $this->logError('Discogs API call failed', [
                'method' => $method,
                'url' => $url,
                'status' => $response->status(),
                'response_body' => $response->body(),
                'payload' => $data,
            ]);
        }

        $response->throw(); // Now throw after logging

        return $response;
    }

    /**
     * Build the payload data structure for Discogs marketplace listing operations.
     * Formats pricing, maps record conditions, and prepares required fields.
     *
     * @param  Record  $record  The record to create payload for
     * @param  Area|null  $area  Optional area for the listing
     * @return array Formatted payload array with required Discogs fields
     */
    private function buildListingPayload(Record $record, ?Area $area = null): array
    {
        $formattedPrice = $record->retail_price ? (float) $record->retail_price->formatByDecimal() : 0;
        $discogsRetailPrice = number_format(ceil($formattedPrice * 1.10) - 0.01, 2, '.', '');

        $payload = [
            'release_id' => (int) $record->release_id, // force format required by Discogs
            'condition' => $this->mapDiskOrCoverStatus($record->disk_status),
            'sleeve_condition' => $this->mapDiskOrCoverStatus($record->cover_status),
            // 'price' => number_format($formattedPrice, 2, '.', ''),
            'price' => $discogsRetailPrice,
            'status' => 'For Sale',
            // 'status' => 'Draft', // DEBUG ONLY - otherwise, not having a valid payment method on Discogs, it would stay Pending, preventing updates
            'comments' => $record->comments ?? '',
            'external_id' => (string) $record->id, // force format required by Discogs
        ];

        // If no area is provided, get it from the first stock with quantity > 0, ordered by order_column
        if (! $area) {
            $firstStock = $record->stocks()
                ->where('quantity', '>', 0)
                ->ordered()
                ->with('area')
                ->first();

            $area = $firstStock?->area;
        }
        if ($area) {
            $storeName = $area->locations()->first()->name ?? '';
            $area_value = "{$area->id} - {$storeName} ({$area->name})";
            if (! empty($record->location_text)) {
                $maxLength = 100; // Discogs 'location' field character limit
                $separator = ' - ';
                $availableLength = $maxLength - mb_strlen($area_value) - mb_strlen($separator);
                if ($availableLength > 0) {
                    $area_value .= $separator.mb_substr($record->location_text, 0, $availableLength);
                }
            }
            $payload['location'] = $area_value;
        }

        // Remove any null values
        return array_filter($payload, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * Map internal disk status or cover status enum value to Discogs condition string.
     *
     * @param  int|null  $diskStatus  The disk status enum value from the database
     * @return string The Discogs condition description
     */
    private function mapDiskOrCoverStatus(?int $diskStatus): string
    {
        return match ($diskStatus) {
            0 => 'Mint (M)',
            1 => 'Near Mint (NM or M-)',
            2 => 'Very Good Plus (VG+)',
            3 => 'Very good (VG)',
            4 => 'Good Plus (G+)',
            5 => 'Fair (F)',
            default => 'Good Plus (G+)' // fallback for null or invalid values
        };
    }

    /**
     * Clean and format string data by removing parenthetical content and trimming whitespace.
     * Used to standardize artist and title information from Discogs responses.
     *
     * @param  string  $string  The string to clean
     * @return string The cleaned string with parenthetical content removed and trimmed
     */
    private function cleanString(string $string): string
    {
        return trim(preg_replace("/\([^)]+\)/", '', $string));
    }
}
