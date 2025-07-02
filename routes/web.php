<?php

use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', [WelcomeController::class, 'index'])->name('welcome');
Route::get('/privacy-policy', [WelcomeController::class, 'privacy'])->name('privacy');
Route::get('/terms-of-service', [WelcomeController::class, 'terms'])->name('terms');
Route::get('/about-us', [WelcomeController::class, 'about'])->name('about');
Route::get('/contact', [WelcomeController::class, 'contact'])->name('contact');
Route::post('/contact', [WelcomeController::class, 'submitContact'])->name('contact.submit');

Volt::route('/login', 'login')->name('login');
Volt::route('/register', 'auth.register')->name('register');
Volt::route('/forgot-password', 'auth.forgot-password')->name('password.request');
Volt::route('/reset-password/{token}', 'auth.reset-password')->name('password.reset');

// Logout
Route::get('/logout', function () {
    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
})->name('logout');

// Auth Required Routes
Route::middleware('auth')->group(function () {
    // Main Dashboard - Role Based Redirect
    Route::get('/dashboard', function () {
        $user = auth()->user();

        // Redirect based on user role
        if ($user->hasRole('admin')) {
            return redirect()->route('admin.dashboard');
        } elseif ($user->hasRole('teacher')) {
            return redirect()->route('teacher.dashboard');
        } elseif ($user->hasRole('parent')) {
            return redirect()->route('parent.dashboard');
        } elseif ($user->hasRole('student')) {
            return redirect()->route('student.dashboard');
        }

        // Default fallback
        return redirect()->route('profile.edit');
    })->name('dashboard');

    // Parent Routes
    Route::middleware(['role:parent'])->prefix('parent')->group(function () {
        // Parent Dashboard
        Volt::route('/dashboard', 'parent.dashboard')->name('parent.dashboard');

        Volt::route('/children', 'parent.children.index')->name('parent.children.index');
        Volt::route('/children/create', 'parent.children.create')->name('parent.children.create');
        Volt::route('/children/{childProfile}', 'parent.children.show')->name('parent.children.show');
        Volt::route('/children/{childProfile}/edit', 'parent.children.edit')->name('parent.children.edit');

        Volt::route('/enrollments', 'parent.enrollments.index')->name('parent.enrollments.index');
        Volt::route('/enrollments/create', 'parent.enrollments.create')->name('parent.enrollments.create');
        Volt::route('/enrollments/{programEnrollment}', 'parent.enrollments.show')->name('parent.enrollments.show');

        Volt::route('/attendance', 'parent.attendance.index')->name('parent.attendance.index');
        Volt::route('/exams', 'parent.exams.index')->name('parent.exams.index');
        Volt::route('/exams/{exam}', 'parent.exams.show')->name('parent.exams.show');

        Volt::route('/invoices', 'parent.invoices.index')->name('parent.invoices.index');
        Volt::route('/invoices/{invoice}', 'parent.invoices.show')->name('parent.invoices.show');
        Volt::route('/invoices/{invoice}/pay', 'parent.invoices.pay')->name('parent.invoices.pay');

        // Parent-specific quick actions
        Volt::route('/payments', 'parent.payments.index')->name('parent.payments.index');
        Volt::route('/schedule', 'parent.schedule.index')->name('parent.schedule.index');
    });

    // Teacher Routes
    Route::middleware(['role:teacher'])->prefix('teacher')->group(function () {
        // Teacher Dashboard
        Volt::route('/dashboard', 'teacher.dashboard')->name('teacher.dashboard');

        Volt::route('/profile', 'teacher.profile.edit')->name('teacher.profile.edit');

        Volt::route('/subjects', 'teacher.subjects.index')->name('teacher.subjects.index');
        Volt::route('/subjects/{subject}', 'teacher.subjects.show')->name('teacher.subjects.show');

        Volt::route('/sessions', 'teacher.sessions.index')->name('teacher.sessions.index');
        Volt::route('/sessions/create', 'teacher.sessions.create')->name('teacher.sessions.create');
        Volt::route('/sessions/{session}', 'teacher.sessions.show')->name('teacher.sessions.show');
        Volt::route('/sessions/{session}/edit', 'teacher.sessions.edit')->name('teacher.sessions.edit');

        Volt::route('/attendance', 'teacher.attendance.index')->name('teacher.attendance.index');
        Volt::route('/attendance/create', 'teacher.attendance.create')->name('teacher.attendance.create');
        Volt::route('/attendance/{session}', 'teacher.attendance.take')->name('teacher.attendance.take');

        Volt::route('/exams', 'teacher.exams.index')->name('teacher.exams.index');
        Volt::route('/exams/create', 'teacher.exams.create')->name('teacher.exams.create');
        Volt::route('/exams/{exam}', 'teacher.exams.show')->name('teacher.exams.show');
        Volt::route('/exams/{exam}/edit', 'teacher.exams.edit')->name('teacher.exams.edit');
        Volt::route('/exams/{exam}/results', 'teacher.exams.results')->name('teacher.exams.results');
        Volt::route('/exams/{exam}/results/create', 'teacher.exam-results.create')->name('teacher.exam-results.create');

        // Teacher-specific quick actions
        Volt::route('/timetable', 'teacher.timetable.index')->name('teacher.timetable.index');
        Volt::route('/students', 'teacher.students.index')->name('teacher.students.index');
    });

    // Student Routes (for client profiles/adult learners)
    Route::middleware(['role:student'])->prefix('student')->group(function () {
        // Student Dashboard
        Volt::route('/dashboard', 'student.dashboard')->name('student.dashboard');

        Volt::route('/profile', 'student.profile.edit')->name('student.profile.edit');

        Volt::route('/enrollments', 'student.enrollments.index')->name('student.enrollments.index');
        Volt::route('/enrollments/{programEnrollment}', 'student.enrollments.show')->name('student.enrollments.show');

        Volt::route('/sessions', 'student.sessions.index')->name('student.sessions.index');
        Volt::route('/sessions/{session}', 'student.sessions.show')->name('student.sessions.show');

        Volt::route('/exams', 'student.exams.index')->name('student.exams.index');
        Volt::route('/exams/{exam}', 'student.exams.show')->name('student.exams.show');

        Volt::route('/invoices', 'student.invoices.index')->name('student.invoices.index');
        Volt::route('/invoices/{invoice}', 'student.invoices.show')->name('student.invoices.show');
        Volt::route('/invoices/{invoice}/pay', 'student.invoices.pay')->name('student.invoices.pay');

        // Student-specific quick actions
        Volt::route('/schedule', 'student.schedule.index')->name('student.schedule.index');
        Volt::route('/grades', 'student.grades.index')->name('student.grades.index');
        Volt::route('/attendance-history', 'student.attendance.history')->name('student.attendance.history');
    });

    // Admin Routes
    Route::middleware(['role:admin'])->prefix('admin')->group(function () {
        // Admin Dashboard
        Volt::route('/dashboard', 'admin.dashboard')->name('admin.dashboard');

        Volt::route('/users', 'admin.users.index')->name('admin.users.index');
        Volt::route('/users/create', 'admin.users.create')->name('admin.users.create');
        Volt::route('/users/{user}', 'admin.users.show')->name('admin.users.show');
        Volt::route('/users/{user}/edit', 'admin.users.edit')->name('admin.users.edit');

        Volt::route('/roles', 'admin.roles.index')->name('admin.roles.index');
        Volt::route('/roles/create', 'admin.roles.create')->name('admin.roles.create');
        Volt::route('/roles/{role}/edit', 'admin.roles.edit')->name('admin.roles.edit');

        Volt::route('/teachers', 'admin.teachers.index')->name('admin.teachers.index');
        Volt::route('/teachers/create', 'admin.teachers.create')->name('admin.teachers.create');
        Volt::route('/teachers/{teacherProfile}', 'admin.teachers.show')->name('admin.teachers.show');
        Volt::route('/teachers/{teacherProfile}/edit', 'admin.teachers.edit')->name('admin.teachers.edit');
        Volt::route('/teachers/{teacherProfile}/session', 'admin.teachers.sessions')->name('admin.teachers.sessions');
        Volt::route('/teachers/{teacherProfile}/exams', 'admin.teachers.exams')->name('admin.teachers.exams');
        Volt::route('/teachers/{teacherProfile}/timetable', 'admin.teachers.timetable')->name('admin.teachers.timetable');

        Volt::route('/parents', 'admin.parents.index')->name('admin.parents.index');
        Volt::route('/parents/{parentProfile}', 'admin.parents.show')->name('admin.parents.show');

        Volt::route('/children', 'admin.children.index')->name('admin.children.index');
        Volt::route('/children/{childProfile}', 'admin.children.show')->name('admin.children.show');

        Volt::route('/curricula', 'admin.curricula.index')->name('admin.curricula.index');
        Volt::route('/curricula/create', 'admin.curricula.create')->name('admin.curricula.create');
        Volt::route('/curricula/{curriculum}', 'admin.curricula.show')->name('admin.curricula.show');
        Volt::route('/curricula/{curriculum}/edit', 'admin.curricula.edit')->name('admin.curricula.edit');

        Volt::route('/subjects', 'admin.subjects.index')->name('admin.subjects.index');
        Volt::route('/subjects/create', 'admin.subjects.create')->name('admin.subjects.create');
        Volt::route('/subjects/{subject}', 'admin.subjects.show')->name('admin.subjects.show');
        Volt::route('/subjects/{subject}/edit', 'admin.subjects.edit')->name('admin.subjects.edit');

        Volt::route('/academic-years', 'admin.academic-years.index')->name('admin.academic-years.index');
        Volt::route('/academic-years/create', 'admin.academic-years.create')->name('admin.academic-years.create');
        Volt::route('/academic-years/{academicYear}/edit', 'admin.academic-years.edit')->name('admin.academic-years.edit');
        Volt::route('/academic-years/{academicYear}', 'admin.academic-years.show')->name('admin.academic-years.show');

        Volt::route('/payment-plans', 'admin.payment-plans.index')->name('admin.payment-plans.index');
        Volt::route('/payment-plans/create', 'admin.payment-plans.create')->name('admin.payment-plans.create');
        Volt::route('/payment-plans/{paymentPlan}/edit', 'admin.payment-plans.edit')->name('admin.payment-plans.edit');
        Volt::route('/payment-plans/{paymentPlan}', 'admin.payment-plans.show')->name('admin.payment-plans.show');

        Volt::route('/enrollments', 'admin.enrollments.index')->name('admin.enrollments.index');
        Volt::route('/enrollments/create', 'admin.enrollments.create')->name('admin.enrollments.create');
        Volt::route('/enrollments/{programEnrollment}', 'admin.enrollments.show')->name('admin.enrollments.show');
        Volt::route('/enrollments/{programEnrollment}/edit', 'admin.enrollments.edit')->name('admin.enrollments.edit');

        Volt::route('/subject-enrollments', 'admin.subject-enrollments.index')->name('admin.subject-enrollments.index');
        Volt::route('/subject-enrollments/create', 'admin.subject-enrollments.create')->name('admin.subject-enrollments.create');
        Volt::route('/subject-enrollments/{subjectEnrollment}', 'admin.subject-enrollments.show')->name('admin.subject-enrollments.show');
        Volt::route('/subject-enrollments/{subjectEnrollment}/edit', 'admin.subject-enrollments.edit')->name('admin.subject-enrollments.edit');

        Volt::route('/invoices', 'admin.invoices.index')->name('admin.invoices.index');
        Volt::route('/invoices/create', 'admin.invoices.create')->name('admin.invoices.create');
        Volt::route('/invoices/{invoice}', 'admin.invoices.show')->name('admin.invoices.show');
        Volt::route('/invoices/{invoice}/edit', 'admin.invoices.edit')->name('admin.invoices.edit');

        Volt::route('/payments', 'admin.payments.index')->name('admin.payments.index');
        Volt::route('/payments/create', 'admin.payments.create')->name('admin.payments.create');
        Volt::route('/payments/{payment}', 'admin.payments.show')->name('admin.payments.show');
        Volt::route('/payments/{payment}/edit', 'admin.payments.edit')->name('admin.payments.edit');

        Volt::route('/timetable', 'admin.timetable.index')->name('admin.timetable.index');
        Volt::route('/timetable/create', 'admin.timetable.create')->name('admin.timetable.create');
        Volt::route('/timetable/{timetableSlot}/edit', 'admin.timetable.edit')->name('admin.timetable.edit');
        Volt::route('/timetable/{timetableSlot}', 'admin.timetable.show')->name('admin.timetable.show');

        // Admin Reports
        Volt::route('/reports/students', 'admin.reports.students')->name('admin.reports.students');
        Volt::route('/reports/attendance', 'admin.reports.attendance')->name('admin.reports.attendance');
        Volt::route('/reports/exams', 'admin.reports.exams')->name('admin.reports.exams');
        Volt::route('/reports/finances', 'admin.reports.finances')->name('admin.reports.finances');
        Volt::route('/reports/overview', 'admin.reports.overview')->name('admin.reports.overview');

        Volt::route('/activity-logs', 'admin.activity-logs.index')->name('admin.activity-logs.index');

        // Admin Settings & System Management
        Volt::route('/settings', 'admin.settings.index')->name('admin.settings.index');
        Volt::route('/system-status', 'admin.system.status')->name('admin.system.status');
        Volt::route('/logs', 'admin.system.logs')->name('system.logs');
        Volt::route('/backups', 'admin.system.backups')->name('system.backups');
        Volt::route('/maintenance', 'admin.system.maintenance')->name('system.maintenance');
        Volt::route('/updates', 'admin.system.updates')->name('system.updates');
    });

    // Shared Routes (available to multiple roles)
    Volt::route('/profile', 'profile.edit')->name('profile.edit');
     Volt::route('/calendar', 'calendar.index')->name('calendar.index');
    Volt::route('/calendar/create', 'calendar.create')->name('calendar.create');
    Volt::route('/calendar/{event}', 'calendar.show')->name('calendar.show');
    Volt::route('/calendar/{event}/edit', 'calendar.edit')->name('calendar.edit');
    Volt::route('/notifications', 'notifications.index')->name('notifications.index');
});
