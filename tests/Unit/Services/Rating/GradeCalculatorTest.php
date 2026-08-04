<?php

namespace Tests\Unit\Services\Rating;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\User;
use App\Services\Rating\GradeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradeCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private GradeCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new GradeCalculator;
    }

    public function test_new_client_has_baseline_grade(): void
    {
        $user = User::factory()->create();
        $grade = $this->calc->compute($user->id, Role::Client, 5.0);

        // C1=0, C2=0, C3=0, C4=(4/4)*20=20, C5=15 → 35
        $this->assertEquals(35, $grade);
    }

    public function test_active_client_high_grade(): void
    {
        $client = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            Order::factory()->for($client, 'client')
                ->status(OrderStatus::Completed)
                ->create(['completed_at' => now()]);
        }

        $grade = $this->calc->compute($client->id, Role::Client, 4.8);

        // C1=25 (10/10=1), C2≈0 (no payments), C3=0, C4=(3.8/4)*20=19, C5=15
        // Total = 25+0+0+19+15 = 59
        $this->assertGreaterThanOrEqual(50, $grade);
    }

    public function test_client_cancel_penalty(): void
    {
        $client = User::factory()->create();

        Order::factory()->for($client, 'client')
            ->status(OrderStatus::Cancelled)
            ->create(['updated_at' => now()]);

        $gradeWithCancel = $this->calc->compute($client->id, Role::Client, 5.0);

        // C5 = 15 - 3 = 12 → total with default: 0+0+0+20+12=32
        $this->assertEquals(32, $gradeWithCancel);
    }

    public function test_seller_returns_stub_50(): void
    {
        $user = User::factory()->create();
        $grade = $this->calc->compute($user->id, Role::Seller, 5.0);

        $this->assertEquals(50, $grade);
    }

    public function test_agent_baseline(): void
    {
        $agent = User::factory()->create();
        $grade = $this->calc->compute($agent->id, Role::Agent, 5.0);

        // A1=0, A2=0, A3=0, A4=(4/4)*25=25, A5=0, A6=10 → 35
        $this->assertEquals(35, $grade);
    }

    public function test_designer_baseline(): void
    {
        $designer = User::factory()->create();
        $grade = $this->calc->compute($designer->id, Role::Designer, 5.0);

        // D1=0, D2=0, D3=0, D4=25, D5=0, D6=10, D7=5 → 40
        $this->assertEquals(40, $grade);
    }

    public function test_grade_listing_boost(): void
    {
        $this->assertEquals(5, GradeCalculator::listingBoost(100));
        $this->assertEquals(0, GradeCalculator::listingBoost(50));
        $this->assertEquals(-5, GradeCalculator::listingBoost(0));
        $this->assertEquals(3, GradeCalculator::listingBoost(80));
    }
}
