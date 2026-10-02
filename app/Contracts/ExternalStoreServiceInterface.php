<?php

namespace App\Contracts;

use App\Models\Area;
use App\Models\Record;

interface ExternalStoreServiceInterface
{
    public function getListing(Record $record): array;

    public function listItem(Record $record, Area $area): array;

    public function updateListing(Record $record): array;

    public function deleteListing(Record $record): array;

    public function getOrders(array $params = []): array;
}
