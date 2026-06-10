<?php

namespace App\DataTransferObjects;

use App\DataTransferObjects\ClassSessionData;
use App\Enums\BookingStatus;

use App\Models\Booking;
use Carbon\Carbon;

class BookingData
{
    public function __construct(
        public int $id,
        public int $student_id, // Might not be needed by frontend, but good to have for consistency
        public int $class_session_id, // Might not be needed, but good to have
        public string $booking_date, // Formatted date for display
        public string $status, // Human-readable status
        public ?string $created_at, // Optional formatted created_at
        public ClassSessionData $classSession, // Nested DTO for related session
    ) {}

    /**
     * Factory method to create from a Booking model.
     * Requires 'classSession' relationship to be eager loaded.
     */
    public static function fromModel(Booking $booking): self
    {
        return new self(
            id: $booking->id,
            student_id: $booking->student_id,
            class_session_id: $booking->class_session_id,
            booking_date: $booking->booking_date->format('Y-m-d'), // Format booking date for display
            status: self::getStatusDisplayName($booking->status), // Get human-readable status
            created_at: $booking->created_at ? $booking->created_at->format('Y-m-d H:i:s') : null,
            classSession: $booking->classSession ? ClassSessionData::fromModel($booking->classSession) : null,
        );
    }

    /**
     * Convert a collection of models to an array of DTOs.
     */
    public static function fromCollection(iterable $collection): array
    {
        return array_map(function ($model) {
            // Ensure the model has the classSession relationship loaded
            return self::fromModel($model);
        }, iterator_to_array($collection));
    }

    /**
     * Helper method to get a human-readable status name.
     * You can expand this based on your BookingStatus enum values.
     */
    protected static function getStatusDisplayName(\App\Enums\BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::CONFIRMED => 'Confirmed',
            BookingStatus::WAITING => 'Waiting List',
            BookingStatus::CANCELLED => 'Cancelled',
            // Add other statuses if you have them
            default => 'Unknown',
        };
    }

    // You might not need toArray() here if fromModel directly returns the structure,
    // but it can be useful for further transformations if needed.
    // If fromModel directly constructs the final output array, toArray() might be redundant.
    // For now, let's assume fromModel constructs the DTO properties, and Laravel handles
    // converting the DTO object into an array response (though explicit toArray is safer).
    // If Laravel doesn't automatically convert your DTO object to array, you might need this:
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'class_session_id' => $this->class_session_id,
            'booking_date' => $this->booking_date,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'class_session' => $this->classSession->toArray(), // Ensure ClassSessionData has toArray() or is directly convertible
        ];
    }
}
