<?php

namespace Tests\Feature;

use App\Models\LogsSms;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze time to 2 PM Melbourne so Australia/Melbourne is always within the
        // sending window (9 AM - 10 PM).
        Carbon::setTestNow(Carbon::parse('2024-06-15 14:00:00', 'Australia/Melbourne'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test the get messages API endpoint.
     */
    public function test_can_get_messages_via_api(): void
    {
        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Test message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => 'Australia/Melbourne',
            'send_after' => Carbon::now()->subHour(),
        ]);

        $response = $this->getJson('/api/sms/messages');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'count',
                'data' => [
                    '*' => [
                        'id',
                        'phone',
                        'message',
                        'provider',
                        'time_zone',
                        'send_after',
                        'sent_at',
                    ],
                ],
            ])
            ->assertJson([
                'success' => true,
                'count' => 1,
            ]);
    }

    /**
     * Test API with custom limit.
     */
    public function test_api_respects_limit_parameter(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            LogsSms::create([
                'phone' => "041234567{$i}",
                'message' => "Test message {$i}",
                'provider' => 'inhousesms',
                'status' => 0,
                'time_zone' => 'Australia/Melbourne',
                'send_after' => Carbon::now()->subHour(),
            ]);
        }

        $response = $this->getJson('/api/sms/messages?limit=3');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'count' => 3,
            ]);
    }

    /**
     * Test API with custom provider.
     */
    public function test_api_respects_provider_parameter(): void
    {
        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Test message',
            'provider' => 'wholesalesms',
            'status' => 0,
            'time_zone' => 'Australia/Melbourne',
            'send_after' => Carbon::now()->subHour(),
        ]);

        $response = $this->getJson('/api/sms/messages?provider=wholesalesms');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'count' => 1,
            ]);
    }

    /**
     * Test API returns empty when no messages available.
     */
    public function test_api_returns_empty_when_no_messages(): void
    {
        $response = $this->getJson('/api/sms/messages');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'count' => 0,
                'data' => [],
            ]);
    }

    /**
     * Test statistics endpoint.
     */
    public function test_can_get_statistics_via_api(): void
    {
        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Test message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => 'Australia/Melbourne',
        ]);

        $response = $this->getJson('/api/sms/statistics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total',
                    'pending',
                    'sent',
                    'delivered',
                    'by_provider',
                    'by_timezone',
                ],
            ]);
    }

    /**
     * Test validation rejects invalid limit.
     */
    public function test_api_validates_limit_parameter(): void
    {
        $response = $this->getJson('/api/sms/messages?limit=200');

        $response->assertStatus(422);
    }

    /**
     * Test validation rejects invalid provider.
     */
    public function test_api_validates_provider_parameter(): void
    {
        $response = $this->getJson('/api/sms/messages?provider=invalid_provider');

        $response->assertStatus(422);
    }

    /**
     * Test messages with future send_after not returned via API.
     */
    public function test_api_does_not_return_future_messages(): void
    {
        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Test message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => 'Australia/Melbourne',
            'send_after' => Carbon::now()->addHours(2),
        ]);

        $response = $this->getJson('/api/sms/messages');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'count' => 0,
            ]);
    }
}

