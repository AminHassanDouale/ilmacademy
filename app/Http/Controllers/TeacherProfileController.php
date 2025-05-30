<?php

namespace App\Http\Controllers;

use App\Models\TeacherProfile;
use App\Http\Requests\StoreTeacherProfileRequest;
use App\Http\Requests\UpdateTeacherProfileRequest;

class TeacherProfileController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTeacherProfileRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(TeacherProfile $teacherProfile)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(TeacherProfile $teacherProfile)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTeacherProfileRequest $request, TeacherProfile $teacherProfile)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TeacherProfile $teacherProfile)
    {
        //
    }
}
