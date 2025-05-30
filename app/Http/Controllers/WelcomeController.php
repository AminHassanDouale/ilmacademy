<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Curriculum;

class WelcomeController extends Controller
{
    /**
     * Show the welcome page.
     */
    public function index()
    {
        // You can pass any data needed for your welcome page
        // For example, featured curricula:
        $featuredCurricula = Curriculum::take(3)->get();

        return view('welcome', [
            'featuredCurricula' => $featuredCurricula,
        ]);
    }

    /**
     * Show the privacy policy page.
     */
    public function privacy()
    {
        return view('privacy');
    }

    /**
     * Show the terms of service page.
     */
    public function terms()
    {
        return view('terms');
    }

    /**
     * Show the about us page.
     */
    public function about()
    {
        // You can pass data like team members if needed
        return view('about');
    }

    /**
     * Show the contact page.
     */
    public function contact()
    {
        return view('contact');
    }

    /**
     * Handle contact form submission.
     */
    public function submitContact(Request $request)
    {
        // Validate the request
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'message' => 'required|string',
        ]);

        // Here you would process the contact form submission
        // For example, send an email, store in database, etc.

        // Redirect back with success message
        return redirect()->route('contact')->with('success', 'Your message has been sent! We will get back to you soon.');
    }
}
