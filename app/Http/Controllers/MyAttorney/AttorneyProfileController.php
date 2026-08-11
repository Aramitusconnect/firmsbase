<?php

declare(strict_types=1);

namespace App\Http\Controllers\MyAttorney;

use App\Http\Controllers\Controller;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\ViewModels\PublicAttorneyProfile;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * AttorneyProfileController — Mission 2 (MyAttorney Marketplace
 * Core), section 42. `/attorneys/{slug}`. Same explicit-slug-lookup
 * rationale as FirmProfileController (DirectoryAttorney's own
 * HasPublicUuid already claims the uuid route key).
 */
class AttorneyProfileController extends Controller
{
    public function show(string $slug): View
    {
        $attorney = DirectoryAttorney::query()->where('slug', $slug)->first();

        if ($attorney === null || ! $attorney->isPubliclyVisible()) {
            throw new NotFoundHttpException;
        }

        return view('myattorney.attorneys.show', [
            'profile' => PublicAttorneyProfile::fromModel($attorney),
        ]);
    }
}
