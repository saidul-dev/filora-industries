<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(DepartmentSeeder::class);
        $this->call(DesignationSeeder::class);
        $this->call(LeaveTypeSeeder::class);
        $this->call(LedgerAccountSeeder::class);
        $this->call(UnitSeeder::class);
        $this->call(CategorySeeder::class);
        $this->call(BrandSeeder::class);
        $this->call(AttributeSeeder::class);
        $this->call(ProductSeeder::class);
        $this->call(PartySeeder::class);
        $this->call(HeroImageSeeder::class);
        $this->call(CompanyProfileSeeder::class);

        User::firstOrCreate(
            ['email' => 'admin@businesserp.test'],
            ['name' => 'Super Admin', 'password' => 'password']
        )->syncRoles('Super Admin');

        User::firstOrCreate(
            ['email' => 'owner@businesserp.test'],
            ['name' => 'Company Admin', 'password' => 'password']
        )->syncRoles('Admin');

        User::firstOrCreate(
            ['email' => 'manager@businesserp.test'],
            ['name' => 'Branch Manager', 'password' => 'password']
        )->syncRoles('Manager');

        User::firstOrCreate(
            ['email' => 'hr@businesserp.test'],
            ['name' => 'HR Executive', 'password' => 'password']
        )->syncRoles('HR');

        User::firstOrCreate(
            ['email' => 'accountant@businesserp.test'],
            ['name' => 'Head Accountant', 'password' => 'password']
        )->syncRoles('Accountant');

        User::firstOrCreate(
            ['email' => 'sales@businesserp.test'],
            ['name' => 'Sales Executive', 'password' => 'password']
        )->syncRoles('Sales Person');

        User::firstOrCreate(
            ['email' => 'factory@businesserp.test'],
            ['name' => 'Factory Manager', 'password' => 'password']
        )->syncRoles('Factory Manager');

        $this->call(SiteSeeder::class);
    }
}
