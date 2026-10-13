<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesInventoryFixtures;
use Tests\TestCase;

class DashboardPipelineTest extends TestCase
{
    use CreatesInventoryFixtures;
    use RefreshDatabase;

    public function test_pipeline_tiles_follow_the_users_permissions(): void
    {
        $this->seedBasics();
        CompanySetting::current();

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Order-to-Delivery Pipeline')
            ->assertSee('Sales Orders Pending')
            ->assertSee('Production Running');

        $this->actingAs($this->userWithRole('Factory Manager'))->get(route('dashboard'))->assertOk()
            ->assertSee('Production Running')
            ->assertSee('Requisitions Awaiting Approval');

        $this->actingAs($this->userWithRole('Sales Person'))->get(route('dashboard'))->assertOk()
            ->assertSee('Sales Orders Pending')
            ->assertDontSee('Production Running');

        $this->actingAs($this->userWithRole('HR'))->get(route('dashboard'))->assertOk()
            ->assertDontSee('Order-to-Delivery Pipeline');
    }
}
