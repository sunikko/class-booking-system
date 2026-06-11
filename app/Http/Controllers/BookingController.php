<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Services\BookingService;
use App\Models\Booking;
use App\DataTransferObjects\BookingData;
use App\Http\Requests\StoreBookingRequest;
use Inertia\Inertia;

class BookingController extends Controller
{
    /**
     * Display a listing of the resource.
     * 1. retrieve data using the service layer
     * 2. return JSON response if it's an API request, otherwise render Inertia view for web requests
     */
    public function index(Request $request, BookingService $bookingService)
    {
        $data = $bookingService->getIndexData($request->user());

        if ($request->wantsJson() || $request->is('api/*')) {
            // Explicitly convert BookingData and ClassSessionData to arrays for JSON response
            // This assumes BookingData and ClassSessionData have a toArray() method or are Eloquent Models
            $data['bookings'] = collect($data['bookings'])->map(fn($booking) => $booking->toArray())->toArray();
            $data['sessions'] = collect($data['sessions'])->map(fn($session) => $session->toArray())->toArray();
            return response()->json($data);
        }
        // dd($data);
        return Inertia::render('Bookings/Index', $data);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBookingRequest $request, BookingService $bookingService)
    {
        try {
            $bookingService->createBooking(
                user: $request->user(),
                data: [
                    'class_session_id' => $request->validated('class_session_id'),
                    'date' => $request->validated('booking_date'),
                ]
            );

            return response()->json([
                'message' => 'Booking created',
            ], Response::HTTP_CREATED);
        } catch (\DomainException $e) {
            return response()->json([
                'code' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Booking $booking, BookingService $bookingService)
    {
        $bookingService->cancelBooking($booking);

        return response()->noContent();
    }
}
