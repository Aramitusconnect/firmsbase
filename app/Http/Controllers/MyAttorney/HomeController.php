<?php

declare(strict_types=1);

namespace App\Http\Controllers\MyAttorney;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * HomeController — Mission 2 (MyAttorney Marketplace Core), checkpoint
 * 4. A minimal, honestly-scoped landing page — the real search
 * experience (section 39) lands in checkpoint 5 and replaces this
 * view's content, not this route.
 */
class HomeController extends Controller
{
    public function index(): View
    {
        return view('myattorney.home');
    }
}
