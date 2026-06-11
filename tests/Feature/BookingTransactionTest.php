<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

use App\Models\User;
use App\Models\Student;
use App\Models\ClassSession;
use App\Services\BookingService;
use App\Models\Booking;
use App\Enums\BookingStatus;

class BookingTransactionTest extends TestCase
{
    use RefreshDatabase;
    /**
     * Booking Transaction – Happy Path
     *
     * Purpose:
     * When a student submits a booking for class sessions
     * that still have available capacity,
     * the system should create the booking with `confirmed` status.
     *
     * This test verifies that:
     * - Booking logic is handled at the service layer
     * - A booking is marked as confirmed when capacity is not exceeded
     */
    public function test_it_confirms_booking_when_capacity_is_available(): void
    {
        // given: a student
        $student = Student::factory()->create();

        // and: a clas session with available capacity
        $classSession = ClassSession::factory()->create(['max_students' => 2]);

        // when: the student submits a booking request
        $service = app(BookingService::class);

        $booking = $service->createBooking(
            user: $student->user,
            data: [
                'class_session_id' => $classSession->id,
                'date' => '2026-01-10',
            ]
        );

        // then: booking is confirmed
        $this->assertEquals(BookingStatus::CONFIRMED, $booking->status);
    }

    /**
     * GIVEN a student already has an active booking (CONFIRMED or WAITING)
     * WHEN the student submits another booking request
     * THEN the request is rejected with HTTP 409
     * AND no new booking record is created
     */
    public function test_duplicate_active_booking_is_rejected_for_active_status()
    {
        // Given: a student with an active booking
        $user = User::factory()->create();
        $student = Student::factory()->create([
            'user_id' => $user->id,
        ]);
        $classSession = ClassSession::factory()->create();

        Booking::factory()->create([
            'student_id' => $student->id,
            'class_session_id' => $classSession->id,
            'status' => BookingStatus::CONFIRMED,
        ]);

        // When: the student tries to create another booking
        $response = $this->actingAs($user)
            ->postJson('/api/bookings', [
                'class_session_id' => $classSession->id,
                'booking_date' => now()->toDateString(),
            ]);

        // Then: booking is rejected
        $response->assertStatus(409)
            ->assertJson([
                'code' => 'ACTIVE_BOOKING_EXISTS',
            ]);

        // And: no additional booking is created
        $this->assertDatabaseCount('bookings', 1);
    }

    public static function activeBookingStatuses(): array
    {
        return [
            [BookingStatus::CONFIRMED],
            [BookingStatus::WAITING],
        ];
    }


    /**
     * Given
     * - class_session capacity = 1
     * - already confirmed booking 1 exists for the class_session
     * 
     * When
     * -  another student requests booking for the same class_session
     * 
     * Then
     * - booking is created
     * - status = WAITING
     */
    public function test_capacity_exceeded_results_in_waiting()
    {
        // Given
        $classSession = ClassSession::factory()->create([
            'max_students' => 1,
        ]);

        $existingStudent = Student::factory()->create();
        Booking::factory()->create([
            'student_id' => $existingStudent->id,
            'class_session_id' => $classSession->id,
            'status' => BookingStatus::CONFIRMED,
        ]);

        $newUser = User::factory()->create();
        $newStudent = Student::factory()->create([
            'user_id' => $newUser->id,
        ]);

        // When
        $response = $this->actingAs($newUser)
            ->postJson('/api/bookings', [
                'class_session_id' => $classSession->id,
                'booking_date' => now()->toDateString(),
            ]);

        // Then
        $response->assertStatus(201);

        $this->assertDatabaseHas('bookings', [
            'student_id' => $newStudent->id,
            'class_session_id' => $classSession->id,
            'status' => BookingStatus::WAITING,
        ]);
    }

    /**
     * Given
     * - class_session capacity = 1
     * - already confirmed booking 1 exists for the class_session
     * - waiting booking 2 exists for the class_session
     * 
     * When
     * - confirmed booking 1 is cancelled
     * 
     * Then
     * - waiting booking 2 is promoted to confirmed
     */
    public function test_waiting_booking_is_promoted_when_confirmed_booking_is_cancelled()
    {
        // Given
        $classSession = ClassSession::factory()->create([
            'max_students' => 1,
        ]);

        $confirmedStudent = Student::factory()->create();
        $confirmedBooking = Booking::factory()->create([
            'student_id' => $confirmedStudent->id,
            'class_session_id' => $classSession->id,
            'status' => BookingStatus::CONFIRMED,
        ]);

        $waitingStudent = Student::factory()->create();
        $waitingBooking = Booking::factory()->create([
            'student_id' => $waitingStudent->id,
            'class_session_id' => $classSession->id,
            'status' => BookingStatus::WAITING,
        ]);

        // When
        $response = $this->actingAs($confirmedStudent->user)
            ->deleteJson("/api/bookings/{$confirmedBooking->id}");

        // Then
        $response->assertNoContent();

        $this->assertDatabaseHas('bookings', [
            'id' => $confirmedBooking->id,
            'status' => BookingStatus::CANCELLED->value,
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $waitingBooking->id,
            'status' => BookingStatus::CONFIRMED->value,
        ]);

        $this->assertEquals(
            1,
            Booking::where('class_session_id', $classSession->id)
                ->where('status', BookingStatus::CONFIRMED)
                ->count()
        );
    }


    /**
     * Given
     * - class_session capacity = 1
     * - already confirmed booking 1 exists for the class_session
     * - waiting booking 2 exists for the class_session
     * 
     * When
     * - waiting booking 2 is cancelled
     * 
     * Then
     * - confirmed booking 1 remains confirmed
     * - no promotion occurs
     */
    public function test_cancelling_waiting_booking_does_not_trigger_promotion()
    {
        // Given
        $classSession = ClassSession::factory()->create([
            'max_students' => 1,
        ]);

        $confirmedStudent = Student::factory()->create();
        $waitingStudent = Student::factory()->create();

        $confirmedBooking = Booking::factory()->create([
            'student_id' => $confirmedStudent->id,
            'class_session_id' => $classSession->id,
            'status' => BookingStatus::CONFIRMED,
        ]);

        $waitingBooking = Booking::factory()->create([
            'student_id' => $waitingStudent->id,
            'class_session_id' => $classSession->id,
            'status' => BookingStatus::WAITING,
        ]);

        // When
        $response = $this->actingAs($waitingStudent->user)
            ->deleteJson("/api/bookings/{$waitingBooking->id}");

        // Then
        $response->assertNoContent();

        $this->assertEquals(
            BookingStatus::CANCELLED,
            $waitingBooking->fresh()->status
        );

        $this->assertEquals(
            BookingStatus::CONFIRMED,
            $confirmedBooking->fresh()->status
        );

        $this->assertDatabaseHas('bookings', [
            'id' => $waitingBooking->id,
            'status' => BookingStatus::CANCELLED->value,
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $confirmedBooking->id,
            'status' => BookingStatus::CONFIRMED->value,
        ]);

        $this->assertEquals(
            1,
            Booking::where('class_session_id', $classSession->id)
                ->where('status', BookingStatus::CONFIRMED)
                ->count(),
            'Should still have exactly 1 confirmed booking'
        );
    }


    /**
     * Given
     * - class_session capacity = 1
     * - already confirmed booking 1 exists for the class_session
     * - no waiting bookings exist for the class_session
     * 
     * When
     * - confirmed booking 1 is cancelled via API
     * 
     * Then
     * - booking 1 status is set to CANCELLED
     * - no other bookings are promoted
     */
    public function test_cancelling_confirmed_booking_with_no_waiting_booking_via_api()
    {
        // Given
        $session = ClassSession::factory()->create([
            'max_students' => 1,
        ]);
        $confirmedStudent = Student::factory()->create();
        $booking = Booking::factory()->create([
            'student_id' => $confirmedStudent->id,
            'class_session_id' => $session->id,
            'status' => BookingStatus::CONFIRMED,
        ]);

        // When
        $response = $this->actingAs($confirmedStudent->user)
            ->deleteJson(route('bookings.destroy', $booking->id));

        // Then
        $response->assertNoContent();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::CANCELLED->value,
        ]);

        $this->assertEquals(0, Booking::where('status', BookingStatus::CONFIRMED)->count());
    }
}
