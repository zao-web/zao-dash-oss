<?php

namespace Tests\Unit\Services\Google;

use App\Models\GoogleCredential;
use App\Services\Google\CalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CalendarService $service;

    protected GoogleCredential $credential;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CalendarService;
        $this->credential = GoogleCredential::factory()->create([
            'access_token' => 'test-token',
        ]);
    }

    /** @test */
    public function it_lists_calendars()
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [
                    [
                        'id' => 'primary',
                        'summary' => 'Primary Calendar',
                    ],
                ],
            ], 200),
        ]);

        $calendars = $this->service->listCalendars($this->credential);

        $this->assertCount(1, $calendars);
        $this->assertEquals('Primary Calendar', $calendars[0]['summary']);
    }

    /** @test */
    public function it_lists_events()
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [
                    [
                        'id' => 'event1',
                        'summary' => 'Meeting',
                        'start' => ['dateTime' => '2025-01-01T10:00:00Z'],
                        'end' => ['dateTime' => '2025-01-01T11:00:00Z'],
                    ],
                ],
            ], 200),
        ]);

        $events = $this->service->listEvents($this->credential, 'primary');

        $this->assertCount(1, $events);
        $this->assertEquals('Meeting', $events[0]['summary']);
    }

    /** @test */
    public function it_sends_auth_token()
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [],
            ], 200),
        ]);

        $this->service->listCalendars($this->credential);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }
}
