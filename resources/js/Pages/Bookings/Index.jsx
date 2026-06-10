import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';

/* ================= CONFIG & HELPERS ================= */
const TIME_SLOTS = ["09:00", "10:00", "11:00", "12:00", "13:00", "14:00", "15:00", "16:00", "17:00", "18:00", "19:00"];
const WEEKDAYS = ["monday", "tuesday", "wednesday", "thursday", "friday", "saturday"];
const DAY_NUMBER_MAP = { 1: 'monday', 2: 'tuesday', 3: 'wednesday', 4: 'thursday', 5: 'friday', 6: 'saturday', 7: 'sunday' };
const COLORS = {
    english: "border-l-4 border-sky-400",
    math: "border-l-4 border-rose-400",
    science: "border-l-4 border-emerald-400",
    default: "border-l-4 border-slate-300"
};
const ERROR_MESSAGES = {
    3: 'already booked this class session on the selected date.',
    8: 'same time slot is already booked.'
};

const normalizeDay = (day) => {
    if (day == null) return null;
    const num = Number(day);
    if (!isNaN(num)) return DAY_NUMBER_MAP[num] ?? null;
    return day.toLowerCase().trim();
};
const normalizeTime = (t) => t?.slice(0, 5);
const subjectColor = (s) => COLORS[s?.toLowerCase()] || COLORS.default;

const nextDates = (session, count = 2) => {
    const day = normalizeDay(session.day_of_week);
    if (!day) return [];
    let d = new Date();
    const target = Number(Object.entries(DAY_NUMBER_MAP).find(([k, v]) => v === day)?.[0]);
    while (d.getDay() !== target) d.setDate(d.getDate() + 1);
    return Array.from({ length: count }, () => {
        const copy = new Date(d);
        d.setDate(d.getDate() + 7);
        return copy;
    });
};

/* ================= MAIN COMPONENT ================= */
export default function Index({ auth, sessions = [], bookings = [] }) {
    const [selectedBookings, setSelectedBookings] = useState([]);
    const [statusMsg, setStatusMsg] = useState({ text: '', type: '' });
    const [isBooking, setIsBooking] = useState(false);

    const showMessage = (text, type = 'error') => {
        setStatusMsg({ text, type });
        setTimeout(() => setStatusMsg({ text: '', type: '' }), 3000);
    };

    // Booking Logic
    const handleRadioChange = (session, dateStr, checked) => {
        if (!checked) return;
        const timeStr = normalizeTime(session.start_time);
        
        setSelectedBookings(prev => {
            // Remove conflicts
            const filtered = prev.filter(b => b.id !== session.id && !(b.date === dateStr && b.time === timeStr));
            return [...filtered, {
                id: session.id,
                date: dateStr,
                time: timeStr,
                subject: session.class_subject || 'Class',
                className: session.class_name
            }];
        });
    };

    const removeSelection = (id, date) => {
        setSelectedBookings(prev => prev.filter(b => !(b.id === id && b.date === date)));
    };

    const handleBookClasses = async () => {
        if (selectedBookings.length === 0) return;
        setIsBooking(true);
        const errors = [];

        for (const { id, date } of selectedBookings) {
            try {
                await axios.post('/bookings', { class_session_id: id, booking_date: date });
            } catch (err) {
                const data = err.response?.data;
                const msg = ERROR_MESSAGES[data?.code] ?? data?.message ?? 'Unknown error';
                if (msg.includes('already booked')) errors.push('duplicate');
                else if (msg.includes('same time')) errors.push('conflict');
                else errors.push('unknown');
            }
        }

        if (errors.length === 0) {
            showMessage(`✓ Successfully booked ${selectedBookings.length} class(es)`, 'success');
        } else {
            const messages = [];
            if (errors.includes('duplicate')) messages.push('Some classes were already booked');
            if (errors.includes('conflict')) messages.push('Some classes had time conflicts');
            if (errors.includes('unknown')) messages.push('Some bookings failed');
            showMessage(`Partial success. ${messages.join('. ')}`);
        }

        setSelectedBookings([]);
        
        // 데이터 갱신을 위해 Inertia의 reload를 사용합니다 (서버에서 props를 다시 받아옴)
        router.reload({ only: ['sessions', 'bookings'] });
        setIsBooking(false);
    };

    // Cancel Booking Logic
    const handleCancelBooking = async (bookingId) => {
        if (!confirm('Are you sure you want to cancel this booking?')) {
            return;
        }

        try {
            const response = await axios.delete(`/bookings/${bookingId}`);
            if (response.status === 204 || response.status === 200) {
                showMessage('Booking successfully cancelled.', 'success');
                // Reload only specific data props from the server to update the UI
                router.reload({ only: ['sessions', 'bookings'] });
            }
        } catch (err) {
            const msg = err.response?.status === 403 ? 'Permission denied.' : 'Failed to cancel booking.';
            showMessage(msg, 'error');
        }
    };

    // State Checks
    const isSessionBooked = (sessionId, date = null) => {
        return bookings.some(b => b.class_session_id == sessionId && b.status?.toLowerCase() === 'confirmed' && (!date || b.booking_date === date));
    };
    const hasConflict = (session) => {
        return bookings.some(b => {
            if (b.status?.toLowerCase() !== 'confirmed') return false;
            if (b.class_session_id == session.id) return false; // 본인 세션은 conflict가 아니라 booked 처리되도록 무시
            
            // 타입 불일치(String/Int)를 막기 위해 == 사용
            const s = sessions.find(x => x.id == b.class_session_id);
            if (!s) return false;
            
            return normalizeDay(s.day_of_week) === normalizeDay(session.day_of_week) && 
                   normalizeTime(s.start_time) === normalizeTime(session.start_time);
        });
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Class Timetable</h2>}>
            <Head title="Class Bookings" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    
                    {/* Status Message */}
                    {statusMsg.text && (
                        <div className={`p-4 rounded-lg font-medium text-sm ${statusMsg.type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                            {statusMsg.text}
                        </div>
                    )}

                    {/* Selected Summary */}
                    {selectedBookings.length > 0 && (
                        <div className="bg-blue-50 border border-blue-200 rounded-lg p-6 shadow-sm">
                            <h3 className="font-semibold mb-3">Selected Bookings:</h3>
                            <div className="text-sm mb-4 space-y-2">
                                {selectedBookings.map(s => (
                                    <div key={`${s.id}-${s.date}`} className="flex items-center justify-between py-2 px-3 bg-white rounded border border-blue-200">
                                        <span>• {s.subject} ({s.className}) - {new Date(s.date).toLocaleDateString()}</span>
                                        <button onClick={() => removeSelection(s.id, s.date)} className="text-red-600 hover:text-red-800 ml-4 text-xs px-2 py-1 border border-red-300 rounded hover:bg-red-50 transition">
                                            Remove
                                        </button>
                                    </div>
                                ))}
                            </div>
                            <div className="flex gap-2">
                                <button disabled={isBooking} onClick={handleBookClasses} className="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 transition disabled:opacity-50">
                                    {isBooking ? 'Booking...' : 'Book Selected Classes'}
                                </button>
                                <button onClick={() => setSelectedBookings([])} className="bg-white text-red-600 border border-red-600 px-6 py-2 rounded hover:bg-red-50 transition">
                                    Cancel All
                                </button>
                            </div>
                        </div>
                    )}

                    {/* Timetable */}
                    <div className="bg-white rounded-lg shadow overflow-x-auto p-4">
                        <table className="w-full border-collapse min-w-[800px]">
                            <thead>
                                <tr className="bg-gray-100">
                                    <th className="border p-3 w-24 sticky left-0 bg-gray-100">Time</th>
                                    {WEEKDAYS.map(day => <th key={day} className="border p-3 capitalize">{day}</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {TIME_SLOTS.map(time => (
                                    <tr key={time}>
                                        <td className="border p-2 sticky left-0 bg-gray-50 font-semibold">{time}</td>
                                        {WEEKDAYS.map(day => {
                                            const cellSessions = sessions.filter(s => normalizeDay(s.day_of_week) === day && normalizeTime(s.start_time) === time);
                                            return (
                                                <td key={day} className="border p-2 align-top">
                                                    {cellSessions.length === 0 ? (
                                                        <p className="text-xs text-gray-400 italic">No class</p>
                                                    ) : (
                                                        cellSessions.map(session => {
                                                            const anyDateBooked = isSessionBooked(session.id);
                                                            const conflict = hasConflict(session);
                                                            const full = session.booked_count >= session.max_students;
                                                            const bookedSubjects = new Set(bookings.filter(b => b.status?.toLowerCase() === 'confirmed').map(b => sessions.find(s => s.id == b.class_session_id)?.class_subject).filter(Boolean));
                                                            const subjectBooked = bookedSubjects.has(session.class_subject);
                                                            
                                                            const isDisabled = anyDateBooked || conflict || full;
                                                            let styleClass = 'bg-white';
                                                            if (anyDateBooked || conflict) styleClass = 'bg-gray-200 opacity-60';
                                                            else if (full) styleClass = 'bg-gray-100 opacity-70';

                                                            let statusMessage = '';
                                                            if (anyDateBooked) statusMessage = '✓ BOOKED';
                                                            else if (conflict) statusMessage = '⚠ CONFLICT';
                                                            else if (subjectBooked) statusMessage = '⚠ SAME SUBJECT BOOKED';
                                                            else if (full) statusMessage = 'FULL';

                                                            const dates = nextDates(session);

                                                            return (
                                                                <div key={session.id} className={`class-card text-gray-800 ${subjectColor(session.class_subject)} rounded p-3 mb-2 text-sm shadow-sm ${styleClass} ${isDisabled ? 'pointer-events-none' : 'hover:-translate-y-0.5 transition-transform'}`}>
                                                                    <div className="font-bold">{session.class_subject || 'Class'}</div>
                                                                    <div className="text-xs opacity-80">{session.class_name}</div>
                                                                    <div className="mt-2 text-xs space-y-1">
                                                                        {dates.map(d => {
                                                                            const dateStr = d.toISOString().slice(0, 10);
                                                                            const isThisDateBooked = isSessionBooked(session.id, dateStr);
                                                                            const canBook = !isDisabled && !isThisDateBooked;
                                                                            const isChecked = selectedBookings.some(b => b.id == session.id && b.date === dateStr);

                                                                            return (
                                                                                <label key={dateStr} className={`flex items-center gap-1 ${!canBook ? 'text-gray-400' : 'cursor-pointer'}`}>
                                                                                    <input 
                                                                                        type="radio" 
                                                                                        name={`session-${session.id}`}
                                                                                        checked={isChecked}
                                                                                        disabled={!canBook}
                                                                                        onChange={(e) => handleRadioChange(session, dateStr, e.target.checked)}
                                                                                        className="rounded-full text-blue-600 focus:ring-blue-500 disabled:opacity-40"
                                                                                    />
                                                                                    {d.toLocaleDateString()}
                                                                                    {isThisDateBooked && <span className="ml-1 font-bold">✓</span>}
                                                                                </label>
                                                                            )
                                                                        })}
                                                                    </div>
                                                                    <div className="mt-2 text-xs font-semibold">{statusMessage}</div>
                                                                </div>
                                                            )
                                                        })
                                                    )}
                                                </td>
                                            )
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* My Bookings */}
                    <div className="bg-white rounded-lg shadow p-6">
                        <h2 className="text-xl font-semibold mb-4">My Bookings</h2>
                        <div className="space-y-2">
                            {bookings.length === 0 ? (
                                <p className="text-sm text-gray-500">No bookings yet</p>
                            ) : (
                                bookings.map(b => (
                                    <div key={b.id} className="flex justify-between items-center p-3 bg-gray-50 rounded border">
                                        <span className="text-sm">{b.class_session?.class_subject || ''} - {b.class_session?.class_name} {b.class_session?.start_time} ({new Date(b.booking_date).toLocaleDateString()})</span>
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs bg-green-100 text-green-700 px-2 py-1 font-semibold rounded uppercase">{b.status}</span>
                                            {/* Show cancel button only if status is confirmed or waiting */}
                                            {(b.status?.toLowerCase() === 'confirmed' || b.status?.toLowerCase() === 'waiting') && (
                                                <button
                                                    onClick={() => handleCancelBooking(b.id)}
                                                    className="text-xs bg-red-100 text-red-700 hover:bg-red-200 px-2 py-1 font-semibold rounded transition"
                                                >
                                                    Cancel
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}