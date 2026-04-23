<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\DataTransferObjects\ClassSessionData;
use App\Enums\BookingStatus;
use DomainException;
use Carbon\Carbon;

class BookingService
{
    public function getIndexData(?User $user): array
    {
        $classSessions = ClassSession::withCount([
            'bookings as booked_count' => function ($q) {
                $q->where('status', BookingStatus::CONFIRMED);
            }
        ])->get();

        $sessionData = ClassSessionData::fromCollection($classSessions);

        $student = $user ? $user->student : null;
        $bookings = $student ? $student->bookings()->with('classSession')->get() : collect();

        return [
            'sessions' => $sessionData,
            'bookings' => $bookings
        ];
    }

    /**
     * Creates a new booking for a student if no active booking exists.
     *
     * @param User $user The user making the booking.
     * @param int $classSessionId The ID of the class session to book.
     * @param string $date The date of the booking.
     *
     * @throws \DomainException if an active booking already exists for the student.
     *
     * @return void
     */
    /**
     * Creates a new booking for a student.
     * This method is transactional to ensure atomicity and consistency.
     *
     * @param User $user The user making the booking.
     * @param int $classSessionId The ID of the class session to book.
     * @param string $date The date of the booking.
     *
     * @throws \DomainException If an active booking already exists for the student,
     *                          or if there's a time conflict, or if the session is invalid.
     *
     * @return void
     */
    public function createBooking(User $user, int $classSessionId, string $date): void
    {
        // Wrap the entire operation in a database transaction.
        // This ensures that if any part fails, the entire operation is rolled back,
        // maintaining data integrity.
        DB::transaction(function () use ($user, $classSessionId, $date) {
            $student = $user->student;

            if (!$student) {
                throw new DomainException('STUDENT_NOT_FOUND');
            }

            // Pre-condition check: Ensure the student doesn't have an active booking already.
            // This is a critical business rule enforced before proceeding.
            if ($this->hasActiveBookingForSession($student, $classSessionId)) {
                throw new DomainException('ACTIVE_BOOKING_EXISTS', 3);
            }

            // Lock the class session row for the duration of the transaction.
            // This prevents other concurrent requests from modifying the session's capacity
            // while we are evaluating it. Essential for concurrency control.
            $classSession = ClassSession::lockForUpdate()->findOrFail($classSessionId);

            // Construct the exact start and end times for the potential new booking
            // using the provided date and the session's time/duration.
            $newStart = Carbon::parse($date)
                ->setTimeFromTimeString($classSession->start_time);

            $newEnd = $newStart->copy()->addMinutes($classSession->duration_min);

            // Check for time conflicts with the student's existing confirmed or waiting bookings.
            if ($this->hasTimeConflict($student, $newStart, $newEnd)) {
                throw new DomainException('TIME_CONFLICT', 8);
            }

            // Determine the booking status based on current capacity.
            // This logic is part of the core business rules and is evaluated within the transaction.
            $status = $classSession->hasCapacity()
                ? BookingStatus::CONFIRMED
                : BookingStatus::WAITING;

             \Log::info('Creating booking', ['status' => $status, 'date' => $date]);

            // Create the booking record. This is the final write operation within the transaction.
            Booking::create([
                'student_id' => $student->id,
                'class_session_id' => $classSessionId,
                'booking_date' => Carbon::parse($date),
                'status' => $status->value,
            ]);
        });
    }

    /**
     * Submits a booking for a student for given class sessions on a specific date.
     *
     * @param Student $student The student making the booking.
     * @param array $classSessionIds Array of class session IDs to book.
     * @param string $date The date of the booking.
     * @param string|null $comment Optional comment for the booking.
     * @return Booking The created booking instance.
     */
    public function submitBooking(
        Student $student,
        array $classSessionIds,
        string $date,
        ?string $comment
    ): Booking {
        return Booking::create([
            'student_id' => $student->id,
            'class_session_id' => $classSessionIds[0],
            'booking_date' => $date,
            'status' => BookingStatus::CONFIRMED->value,
        ]);
    }



    /**
     * Checks if a student has an active booking for a specific class session.
     *
     * @param Student $student The student to check.
     * @param int $classSessionId The ID of the class session.
     * @return bool True if an active booking exists, false otherwise.
     */
    public function hasActiveBookingForSession(
        Student $student,
        int $classSessionId
    ): bool {
        return Booking::where('student_id', $student->id)
            ->where('class_session_id', $classSessionId)
            ->whereIn('status', [
                BookingStatus::CONFIRMED,
                BookingStatus::WAITING,
            ])
            ->exists();
    }



    public function cancelBooking(Booking $booking): void
    {
        DB::transaction(function () use ($booking) { // lockForUpdate()
            $wasConfirmed = $booking->isConfirmed();

            $booking->cancel();

            if ($wasConfirmed) {
                $booking->classSession->promoteNextWaitingBooking();
            }
        }); //commit / rollback → auto unlock
    }

    public function hasTimeConflict(
        Student $student,
        Carbon $newStart,
        Carbon $newEnd
    ): bool {
        $bookings = Booking::with('classSession')
            ->where('student_id', $student->id)
            ->where('booking_date', $newStart->toDateString()) // Optimization: Only fetch bookings for the specific date
            ->whereIn('status', [
                BookingStatus::CONFIRMED,
                BookingStatus::WAITING,
            ])
            ->get();

        foreach ($bookings as $booking) {
            $session = $booking->classSession;

            $existingStart = $session->startDateTime();
            $existingEnd   = $session->endDateTime();

            if (
                $existingStart < $newEnd &&
                $existingEnd > $newStart
            ) {
                return true;
            }
        }

        return false;
    }
}
