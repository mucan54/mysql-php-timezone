<?php

namespace Tests\Feature;

use App\Models\LogsSms;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SmsService $smsService;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze time to 2 PM Melbourne so Australia/Melbourne is always within the
        // sending window (9 AM - 10 PM). Tests that need a specific time will override this.
        Carbon::setTestNow(Carbon::parse('2024-06-15 14:00:00', 'Australia/Melbourne'));
        $this->smsService = new SmsService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test that pending messages can be retrieved.
     */
    public function test_can_retrieve_pending_messages(): void
    {
        // Create a pending message that should be sent (send_after in the past)
        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Test message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => 'Australia/Melbourne',
            'send_after' => Carbon::now()->subHour(),
        ]);

        $messages = $this->smsService->getAndMarkMessagesToSend(5, 'inhousesms');

        $this->assertCount(1, $messages);
        $this->assertEquals('0412345678', $messages->first()->phone);
        $this->assertEquals(1, $messages->first()->status);
        $this->assertEquals(1, $messages->first()->sent);
        $this->assertNotNull($messages->first()->sent_at);
    }

    /**
     * Test that messages with future send_after are not retrieved.
     */
    public function test_future_messages_not_retrieved(): void
    {
        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Test message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => 'Australia/Melbourne',
            'send_after' => Carbon::now()->addHours(2),
        ]);

        $messages = $this->smsService->getAndMarkMessagesToSend(5, 'inhousesms');

        $this->assertCount(0, $messages);
    }

    /**
     * Test that already sent messages are not retrieved.
     */
    public function test_sent_messages_not_retrieved(): void
    {
        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Test message',
            'provider' => 'inhousesms',
            'status' => 1, // Already sent
            'sent' => 1,
            'time_zone' => 'Australia/Melbourne',
            'send_after' => Carbon::now()->subHour(),
        ]);

        $messages = $this->smsService->getAndMarkMessagesToSend(5, 'inhousesms');

        $this->assertCount(0, $messages);
    }

    /**
     * Test that messages are ordered by ID ascending.
     */
    public function test_messages_ordered_by_id(): void
    {
        // Create messages in reverse order with same priority
        for ($i = 3; $i >= 1; $i--) {
            LogsSms::create([
                'phone' => "041234567{$i}",
                'message' => "Test message {$i}",
                'provider' => 'inhousesms',
                'status' => 0,
                'priority' => 0, // Same priority
                'time_zone' => 'Australia/Melbourne',
                'send_after' => Carbon::now()->subHour(),
            ]);
        }

        $messages = $this->smsService->getAndMarkMessagesToSend(5, 'inhousesms');

        $this->assertCount(3, $messages);
        $ids = $messages->pluck('id')->toArray();
        $this->assertEquals($ids, collect($ids)->sort()->values()->toArray());
    }

    /**
     * Test that messages are ordered by priority DESC, then id ASC.
     */
    public function test_messages_ordered_by_priority_then_id(): void
    {
        // Create messages with different priorities
        LogsSms::create([
            'phone' => '0412345671',
            'message' => 'Low priority message 1',
            'provider' => 'inhousesms',
            'status' => 0,
            'priority' => 1,
            'time_zone' => 'Australia/Melbourne',
            'send_after' => Carbon::now()->subHour(),
        ]);

        LogsSms::create([
            'phone' => '0412345672',
            'message' => 'High priority message',
            'provider' => 'inhousesms',
            'status' => 0,
            'priority' => 5,
            'time_zone' => 'Australia/Melbourne',
            'send_after' => Carbon::now()->subHour(),
        ]);

        LogsSms::create([
            'phone' => '0412345673',
            'message' => 'Low priority message 2',
            'provider' => 'inhousesms',
            'status' => 0,
            'priority' => 1,
            'time_zone' => 'Australia/Melbourne',
            'send_after' => Carbon::now()->subHour(),
        ]);

        $messages = $this->smsService->getAndMarkMessagesToSend(5, 'inhousesms');

        $this->assertCount(3, $messages);

        // High priority (5) should be first
        $this->assertEquals(5, $messages[0]->priority);
        $this->assertEquals('0412345672', $messages[0]->phone);

        // Then low priority messages ordered by ID
        $this->assertEquals(1, $messages[1]->priority);
        $this->assertEquals(1, $messages[2]->priority);
        $this->assertTrue($messages[1]->id < $messages[2]->id);
    }

    /**
     * Test limit is respected.
     */
    public function test_limit_is_respected(): void
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

        $messages = $this->smsService->getAndMarkMessagesToSend(5, 'inhousesms');

        $this->assertCount(5, $messages);
    }

    /**
     * Test provider filtering.
     */
    public function test_provider_filtering(): void
    {
        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'InhouseSMS message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => 'Australia/Melbourne',
            'send_after' => Carbon::now()->subHour(),
        ]);

        LogsSms::create([
            'phone' => '0498765432',
            'message' => 'Wholesale message',
            'provider' => 'wholesalesms',
            'status' => 0,
            'time_zone' => 'Australia/Melbourne',
            'send_after' => Carbon::now()->subHour(),
        ]);

        $inhouseMessages = $this->smsService->getAndMarkMessagesToSend(5, 'inhousesms');
        $this->assertCount(1, $inhouseMessages);
        $this->assertEquals('0412345678', $inhouseMessages->first()->phone);

        $wholesaleMessages = $this->smsService->getAndMarkMessagesToSend(5, 'wholesalesms');
        $this->assertCount(1, $wholesaleMessages);
        $this->assertEquals('0498765432', $wholesaleMessages->first()->phone);
    }

    /**
     * Test messages with null send_after are retrieved.
     */
    public function test_null_send_after_messages_retrieved(): void
    {
        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Test message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => 'Australia/Melbourne',
            'send_after' => null,
        ]);

        $messages = $this->smsService->getAndMarkMessagesToSend(5, 'inhousesms');

        $this->assertCount(1, $messages);
    }

    /**
     * Test messages with null timezone are retrieved.
     */
    public function test_null_timezone_messages_retrieved(): void
    {
        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Test message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => null,
            'send_after' => Carbon::now()->subHour(),
        ]);

        $messages = $this->smsService->getAndMarkMessagesToSend(5, 'inhousesms');

        $this->assertCount(1, $messages);
    }

    /**
     * Test statistics method.
     */
    public function test_can_get_statistics(): void
    {
        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Pending message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => 'Australia/Melbourne',
        ]);

        LogsSms::create([
            'phone' => '0498765432',
            'message' => 'Sent message',
            'provider' => 'inhousesms',
            'status' => 1,
            'sent' => 1,
            'time_zone' => 'Australia/Sydney',
        ]);

        $stats = $this->smsService->getStatistics();

        $this->assertEquals(2, $stats['total']);
        $this->assertEquals(1, $stats['pending']);
        $this->assertEquals(1, $stats['sent']);
    }

    /**
     * Test preview does not mark messages as sent.
     */
    public function test_preview_does_not_mark_as_sent(): void
    {
        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Test message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => 'Australia/Melbourne',
            'send_after' => Carbon::now()->subHour(),
        ]);

        $messages = $this->smsService->previewMessagesToSend(5, 'inhousesms');

        $this->assertCount(1, $messages);
        $this->assertEquals(0, $messages->first()->status);
        $this->assertEquals(0, $messages->first()->sent);
    }

    /**
     * Test that getValidTimezones returns correct timezones during business hours.
     */
    public function test_get_valid_timezones_during_business_hours(): void
    {
        // At 6 PM Melbourne (18:00), most/all supported timezones should be in window
        Carbon::setTestNow(Carbon::parse('2024-06-15 18:00:00', 'Australia/Melbourne'));

        $reflection = new \ReflectionClass($this->smsService);
        $method = $reflection->getMethod('getValidTimezones');
        $method->setAccessible(true);

        $validTimezones = $method->invoke($this->smsService);

        // Should return multiple valid timezones
        $this->assertNotEmpty($validTimezones);
        $this->assertContains('Australia/Melbourne', $validTimezones);

        Carbon::setTestNow();
    }

    /**
     * Test that getValidTimezones returns empty array when outside all business hours.
     */
    public function test_get_valid_timezones_outside_business_hours(): void
    {
        // At 3 AM Melbourne (03:00):
        // - Melbourne: 3 AM (outside 9-22)
        // - Sydney: 3 AM (outside 9-22)
        // - Brisbane: 2 AM (outside 9-22)
        // - Adelaide: 2:30 AM (outside 9-22)
        // - Perth: 1 AM (outside 9-22)
        // - Hobart: 3 AM (outside 9-22)
        // - Auckland: 5 AM (outside 9-22)
        // - Kuala Lumpur: 1 AM (outside 9-22)
        // - Istanbul: 8 PM previous day / 20:00 (IN window 9-22!)
        Carbon::setTestNow(Carbon::parse('2024-06-15 03:00:00', 'Australia/Melbourne'));

        $reflection = new \ReflectionClass($this->smsService);
        $method = $reflection->getMethod('getValidTimezones');
        $method->setAccessible(true);

        $validTimezones = $method->invoke($this->smsService);

        // Istanbul should still be in window at 8 PM
        $this->assertContains('Europe/Istanbul', $validTimezones);
        // Melbourne should NOT be in window at 3 AM
        $this->assertNotContains('Australia/Melbourne', $validTimezones);

        Carbon::setTestNow();
    }

    /**
     * Test that messages outside valid timezone window are not retrieved.
     */
    public function test_messages_outside_timezone_window_not_retrieved(): void
    {
        // At 3 AM Melbourne, Melbourne timezone is outside window
        Carbon::setTestNow(Carbon::parse('2024-06-15 03:00:00', 'Australia/Melbourne'));

        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Test message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => 'Australia/Melbourne', // 3 AM - outside window
            'send_after' => Carbon::now()->subHour(),
        ]);

        $messages = $this->smsService->getAndMarkMessagesToSend(5, 'inhousesms');

        // Should NOT be retrieved because Melbourne is at 3 AM (outside 9-22)
        $this->assertCount(0, $messages);

        Carbon::setTestNow();
    }

    /**
     * Test that messages inside valid timezone window are retrieved.
     */
    public function test_messages_inside_timezone_window_retrieved(): void
    {
        // At 3 AM Melbourne, Istanbul is at 8 PM (in window)
        Carbon::setTestNow(Carbon::parse('2024-06-15 03:00:00', 'Australia/Melbourne'));

        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Test message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => 'Europe/Istanbul', // 8 PM - in window
            'send_after' => Carbon::now()->subHour(),
        ]);

        $messages = $this->smsService->getAndMarkMessagesToSend(5, 'inhousesms');

        // Should be retrieved because Istanbul is at 8 PM (in 9-22 window)
        $this->assertCount(1, $messages);

        Carbon::setTestNow();
    }

    /**
     * Test that messages with different timezones are filtered correctly.
     */
    public function test_mixed_timezone_messages_filtered_correctly(): void
    {
        // At 3 AM Melbourne:
        // - Melbourne: 3 AM (outside window)
        // - Istanbul: 8 PM (inside window)
        Carbon::setTestNow(Carbon::parse('2024-06-15 03:00:00', 'Australia/Melbourne'));

        LogsSms::create([
            'phone' => '0412345678',
            'message' => 'Melbourne message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => 'Australia/Melbourne', // 3 AM - outside
            'send_after' => Carbon::now()->subHour(),
        ]);

        LogsSms::create([
            'phone' => '0498765432',
            'message' => 'Istanbul message',
            'provider' => 'inhousesms',
            'status' => 0,
            'time_zone' => 'Europe/Istanbul', // 8 PM - inside
            'send_after' => Carbon::now()->subHour(),
        ]);

        $messages = $this->smsService->getAndMarkMessagesToSend(5, 'inhousesms');

        // Only Istanbul message should be retrieved
        $this->assertCount(1, $messages);
        $this->assertEquals('0498765432', $messages->first()->phone);

        Carbon::setTestNow();
    }

    /**
     * Test that all messages are retrieved when all timezones are in window.
     */
    public function test_all_timezones_in_window_retrieves_all_messages(): void
    {
        // Set time to when all timezones should be in window (6 PM Melbourne)
        Carbon::setTestNow(Carbon::parse('2024-06-15 18:00:00', 'Australia/Melbourne'));

        // Create messages with different timezones
        $timezones = [
            'Australia/Melbourne',
            'Australia/Sydney',
            'Pacific/Auckland',
            'Europe/Istanbul',
            'Asia/Kuala_Lumpur',
        ];

        foreach ($timezones as $i => $tz) {
            LogsSms::create([
                'phone' => "041234567{$i}",
                'message' => "Test message for {$tz}",
                'provider' => 'inhousesms',
                'status' => 0,
                'time_zone' => $tz,
                'send_after' => Carbon::now()->subHour(),
            ]);
        }

        $messages = $this->smsService->getAndMarkMessagesToSend(10, 'inhousesms');

        // All messages should be retrieved (all timezones in window at 6 PM Melbourne)
        $this->assertCount(5, $messages);

        Carbon::setTestNow();
    }

    /**
     * Test getSupportedTimezones returns the correct list.
     */
    public function test_get_supported_timezones(): void
    {
        $timezones = $this->smsService->getSupportedTimezones();

        $this->assertIsArray($timezones);
        $this->assertContains('Australia/Melbourne', $timezones);
        $this->assertContains('Australia/Hobart', $timezones);
        $this->assertContains('Europe/Istanbul', $timezones);
        $this->assertNotContains('Australia/Tasmania', $timezones); // Invalid timezone
    }

    /**
     * Test getTimezoneStatus returns correct structure.
     */
    public function test_get_timezone_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-06-15 18:00:00', 'Australia/Melbourne'));

        $status = $this->smsService->getTimezoneStatus();

        $this->assertIsArray($status);
        $this->assertArrayHasKey('Australia/Melbourne', $status);
        $this->assertArrayHasKey('local_time', $status['Australia/Melbourne']);
        $this->assertArrayHasKey('hour', $status['Australia/Melbourne']);
        $this->assertArrayHasKey('in_window', $status['Australia/Melbourne']);

        Carbon::setTestNow();
    }
}

