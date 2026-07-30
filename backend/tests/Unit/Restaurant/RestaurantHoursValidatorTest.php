<?php

namespace Tests\Unit\Restaurant;

use App\Services\Restaurant\RestaurantHoursValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RestaurantHoursValidatorTest extends TestCase
{
    private RestaurantHoursValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new RestaurantHoursValidator;
    }

    public function test_split_hours_valid(): void
    {
        $this->validator->validateRegularHours([
            ['day_of_week' => 1, 'opens_at' => '11:00', 'closes_at' => '14:00', 'is_closed' => false, 'service_type' => 'all'],
            ['day_of_week' => 1, 'opens_at' => '17:00', 'closes_at' => '22:00', 'is_closed' => false, 'service_type' => 'all'],
        ]);
        $this->assertTrue(true);
    }

    public function test_overlap_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validateRegularHours([
            ['day_of_week' => 1, 'opens_at' => '11:00', 'closes_at' => '15:00', 'is_closed' => false, 'service_type' => 'all'],
            ['day_of_week' => 1, 'opens_at' => '14:00', 'closes_at' => '18:00', 'is_closed' => false, 'service_type' => 'all'],
        ]);
    }

    public function test_overnight_accepted(): void
    {
        $this->validator->validateRegularHours([
            ['day_of_week' => 5, 'opens_at' => '18:00', 'closes_at' => '02:00', 'is_closed' => false, 'service_type' => 'all'],
        ]);
        $this->assertTrue(true);
    }
}
