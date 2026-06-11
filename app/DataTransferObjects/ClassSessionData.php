<?php

namespace App\DataTransferObjects;

use App\Models\ClassSession;
use Carbon\Carbon;

class ClassSessionData
{
    public function __construct(
        public int $id,
        public string $class_name, // Use 'class_name' from the model
        public string $class_subject, // Use 'class_subject' from the model
        public Carbon $start_date, // Assuming start_date is cast to Carbon
        public Carbon $end_date,   // Assuming end_date is cast to Carbon
        public string $day_of_week,
        public string $start_time, // Keep as string for formatting
        public int $duration_min,
        public int $max_students,
        public int $booked_count, // This IS available from withCount()
    ) {}

    /**
     * Factory method to create from a model.
     * This is where you define how your model maps to the DTO.
     */
    public static function fromModel(ClassSession $classSession): self
    {
        return new self(
            id: $classSession->id,
            class_name: $classSession->class_name, // Map to your model's property
            class_subject: $classSession->class_subject, // Map to your model's property
            start_date: $classSession->start_date, // Assuming it's already a Carbon instance
            end_date: $classSession->end_date,     // Assuming it's already a Carbon instance
            day_of_week: $classSession->day_of_week,
            start_time: $classSession->start_time, // Keep as string, format if needed for API
            duration_min: $classSession->duration_min,
            max_students: $classSession->max_students,
            booked_count: (int) ($classSession->booked_count ?? 0), // Access the calculated property
        );
    }

    /**
     * Convert DTO to an array for JSON response.
     */
    public function toArray(): array
    {
        // We might want to format dates/times here for the API
        return [
            'id' => $this->id,
            'class_name' => $this->class_name,
            'class_subject' => $this->class_subject,
            // Format dates/times for the API response
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date->format('Y-m-d'),
            'day_of_week' => $this->day_of_week,
            'start_time' => substr($this->start_time, 0, 5), // Keep as string format HH:mm
            'duration_min' => $this->duration_min,
            'max_students' => $this->max_students,
            'booked_count' => $this->booked_count,
            'capacity_available' => $this->max_students - $this->booked_count,
        ];
    }

    /**
     * Convert a collection of models to an array of DTOs.
     */
    public static function fromCollection(iterable $collection): array
    {
        // Ensure the collection being passed has the 'booked_count' property appended.
        // If not, the fromModel and toArray methods will need adjustments.
        return collect($collection)->map(function ($model) {
            return self::fromModel($model)->toArray();
        })->all();
    }
}
