<?php

namespace Database\Seeders;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use App\Enums\ForSaleOnDiscogsStatusEnum;
use App\Enums\RecordTypeEnum;
use App\Models\Record;
use Illuminate\Database\Seeder;

class RecordSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $record_array = [
            [
                'barcode' => '7277016606127',
                'cat_number' => '660612',
                'release_id' => '3683910',
                'type' => RecordTypeEnum::SecondHand->value,
                'title' => 'Wönderful',
                'retail_price' => 1999,
                'wholesale_price' => 0,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::Mint->value,
                'cover_status' => CoverStatusEnum::NearMint->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 19, // CD
                'label_id' => 1, // Century Media Records
                'artist_id' => 1, // Circle Jerks
            ],
            [
                'barcode' => '680341156019',
                'cat_number' => 'JNR360',
                'release_id' => '20129638',
                'type' => RecordTypeEnum::New->value,
                'title' => 'The Witness',
                'retail_price' => 2999,
                'wholesale_price' => 0,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::Mint->value,
                'cover_status' => CoverStatusEnum::Mint->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 31, // LP
                'label_id' => 2, // Joyful Noise Recordings
                'artist_id' => 2, // Suuns
            ],
            [
                'barcode' => '077779006618',
                'cat_number' => '64 7900661',
                'release_id' => '2176148',
                'type' => RecordTypeEnum::SecondHand->value,
                'title' => 'Destiny',
                'retail_price' => 1199,
                'wholesale_price' => 0,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::VeryGoodPlus->value,
                'cover_status' => CoverStatusEnum::VeryGoodPlus->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 31, // LP
                'label_id' => 3, // Emi
                'artist_id' => 3, // Saxon
            ],
            [
                'barcode' => '769791973800',
                'cat_number' => 'WELLE119',
                'release_id' => '20391109',
                'type' => RecordTypeEnum::New->value,
                'title' => 'Tetterettet',
                'retail_price' => 2400,
                'wholesale_price' => 1500,
                'purchase_price' => 1000,
                'disk_status' => DiskStatusEnum::Mint->value,
                'cover_status' => CoverStatusEnum::Mint->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 31, // LP
                'label_id' => 4, // Our Swimmer
                'artist_id' => 4, // Icp Tentet
            ],
            [
                'barcode' => '8055323521611',
                'cat_number' => 'CNPL807',
                'release_id' => '20055934',
                'type' => RecordTypeEnum::New->value,
                'title' => 'About Time',
                'retail_price' => 2999,
                'wholesale_price' => 0,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::Mint->value,
                'cover_status' => CoverStatusEnum::Mint->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 31, // LP
                'label_id' => 5, // Cinedelic Records
                'artist_id' => 5, // Ping Pong
            ],
            [
                'barcode' => '5060672880565',
                'cat_number' => 'TDP 54056',
                'release_id' => '0',
                'type' => RecordTypeEnum::New->value,
                'title' => 'Fly Away',
                'retail_price' => 0,
                'wholesale_price' => 1100,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::Mint->value,
                'cover_status' => CoverStatusEnum::Mint->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 31, // LP
                'label_id' => 6, // Trading Places
                'artist_id' => 6, // Agincourt
            ],
            [
                'barcode' => '5016583502010',
                'cat_number' => 'GRUB 20',
                'release_id' => '1496001',
                'type' => RecordTypeEnum::SecondHand->value,
                'title' => 'Rape Of The Earth',
                'retail_price' => 1199,
                'wholesale_price' => 0,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::VeryGoodPlus->value,
                'cover_status' => CoverStatusEnum::VeryGoodPlus->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 31, // LP
                'label_id' => 7, // Food For Thought Records
                'artist_id' => 7, // Patrick Rondat
            ],
            [   // 8
                'barcode' => '0745860737467',
                'cat_number' => 'HPS141',
                'release_id' => '15970260',
                'type' => RecordTypeEnum::New->value,
                'title' => '...from The Earth To The Sky And Back',
                'retail_price' => 2699,
                'wholesale_price' => 0,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::Mint->value,
                'cover_status' => CoverStatusEnum::Mint->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 34, // LP COLOR
                'label_id' => 8, // Heavy Psych Sounds
                'artist_id' => 8, // The Pilgrim
            ],
            [   // 9
                'barcode' => '098787141436',
                'cat_number' => 'SP1414',
                'release_id' => '19643347',
                'type' => RecordTypeEnum::New->value,
                'title' => 'Every Good Boy Deserves Fudge',
                'retail_price' => 3699,
                'wholesale_price' => 0,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::Mint->value,
                'cover_status' => CoverStatusEnum::Mint->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => 'Sealed, colour vinyl',
                'comments' => '',
                'format_id' => 4, // 2LP
                'label_id' => 9, // Sub Pop
                'artist_id' => 9, // Mudhoney
            ],
            [   // 10
                'barcode' => '077779455720',
                'cat_number' => 'CDP 7 94557 2',
                'release_id' => '917800',
                'type' => RecordTypeEnum::SecondHand->value,
                'title' => 'School Of Fish',
                'retail_price' => 799,
                'wholesale_price' => 0,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::VeryGoodPlus->value,
                'cover_status' => CoverStatusEnum::VeryGoodPlus->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 19, // CD
                'label_id' => 10, // Capitol Records
                'artist_id' => 10, // School Of Fish
            ],
            [   // 11
                'barcode' => '5034202019626',
                'cat_number' => 'WIGCD196',
                'release_id' => '1636257',
                'type' => RecordTypeEnum::New->value,
                'title' => 'The Sun',
                'retail_price' => 699,
                'wholesale_price' => 0,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::Mint->value,
                'cover_status' => CoverStatusEnum::Mint->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 19, // CD
                'label_id' => 11, // Domino
                'artist_id' => 11, // Fridge
            ],
            [   // 12
                'barcode' => '7320470255308',
                'cat_number' => 'PP1003',
                'release_id' => '18417439',
                'type' => RecordTypeEnum::New->value,
                'title' => 'Natura Morta',
                'retail_price' => 3499,
                'wholesale_price' => 0,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::Mint->value,
                'cover_status' => CoverStatusEnum::Mint->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 31, // LP
                'label_id' => 12, // Piano Piano
                'artist_id' => 12, // Sven Wunder
            ],
            [   // 13
                'barcode' => '5060672880596',
                'cat_number' => 'TDP54059',
                'release_id' => '0',
                'type' => RecordTypeEnum::New->value,
                'title' => 'Lord Of The Skies',
                'retail_price' => 0,
                'wholesale_price' => 1100,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::Mint->value,
                'cover_status' => CoverStatusEnum::Mint->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 31, // LP
                'label_id' => 13, // Trading Places
                'artist_id' => 13, // Outskirts Of Infinity
            ],
            [   // 14
                'barcode' => '5060180322885',
                'cat_number' => 'BWOOD0155CD',
                'release_id' => '9088035',
                'type' => RecordTypeEnum::New->value,
                'title' => 'Wisdom Of Elders',
                'retail_price' => 1699,
                'wholesale_price' => 0,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::Mint->value,
                'cover_status' => CoverStatusEnum::Mint->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 19, // CD
                'label_id' => 14, // Brownswood Recordings
                'artist_id' => 14, // Shabaka And The Ancestors
            ],
            [   // 15
                'barcode' => '025218250122',
                'cat_number' => '2MCD-2501-2',
                'release_id' => '8083254',
                'type' => RecordTypeEnum::New->value,
                'title' => 'Silver City - A Celebration Of 25 Years On Milestone',
                'retail_price' => 1999,
                'wholesale_price' => 0,
                'purchase_price' => 0,
                'disk_status' => DiskStatusEnum::Mint->value,
                'cover_status' => CoverStatusEnum::Mint->value,
                'for_sale_on_discogs' => ForSaleOnDiscogsStatusEnum::NotForSale->value,
                'description' => '',
                'comments' => '',
                'format_id' => 3, // 2CD
                'label_id' => 15, // Milestone
                'artist_id' => 15, // Sonny Rollins
            ],
        ];

        foreach ($record_array as $record_data) {
            Record::factory()->create($record_data);
        }

    }
}
