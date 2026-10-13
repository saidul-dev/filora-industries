<?php

namespace Database\Seeders;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SiteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // The office — holds finished sample stock and part of the finished
        // goods inventory (see docs/order-to-delivery.md).
        $office = Site::firstOrCreate(
            ['code' => 'HO-01'],
            [
                'name' => 'Head Office',
                'type' => 'Head Office',
                'address' => 'Dhaka, Bangladesh',
                'status' => true,
            ]
        );

        // The production unit — where Production Orders consume raw
        // materials and finished goods are received into stock. Also the
        // second Site Stock Transfer needs (a movement always has a
        // from-Site and a to-Site).
        $factory = Site::firstOrCreate(
            ['code' => 'FAC-01'],
            [
                'name' => 'Factory',
                'type' => 'Factory',
                'address' => 'Gazipur, Bangladesh',
                'status' => true,
            ]
        );

        $manager = User::where('email', 'manager@businesserp.test')->first();

        if ($manager) {
            $manager->sites()->syncWithoutDetaching([
                $office->id => ['is_default' => true],
                $factory->id => ['is_default' => false],
            ]);

            $manager->update(['current_site_id' => $office->id]);
        }

        $salesPerson = User::where('email', 'sales@businesserp.test')->first();

        if ($salesPerson) {
            $salesPerson->sites()->syncWithoutDetaching([$office->id => ['is_default' => true]]);
            $salesPerson->update(['current_site_id' => $office->id]);
        }

        $factoryManager = User::where('email', 'factory@businesserp.test')->first();

        if ($factoryManager) {
            $factoryManager->sites()->syncWithoutDetaching([$factory->id => ['is_default' => true]]);
            $factoryManager->update(['current_site_id' => $factory->id]);
        }
    }
}
