<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\ContactMessage;
use App\Models\Product;
use App\Models\Site;
use Illuminate\Http\Request;

class WebsiteController extends Controller
{
    public function home()
    {
        $company = CompanySetting::current();

        $categories = Category::whereNull('parent_id')->where('status', true)
            ->withCount('products')
            ->with(['products' => fn ($q) => $q->where('status', true)->whereNotNull('image_path')->limit(1)])
            ->whereHas('products')
            ->orderBy('name')
            ->get();

        $products = Product::with('stockUnit')
            ->where('status', true)->where('has_variants', false)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        // Top-of-page highlight row — prefer products with a real photo
        // (they carry the card visually), topped up with the newest
        // otherwise so the row still has 3 even with no photos uploaded yet.
        $featuredProducts = $products->sortByDesc(fn (Product $p) => $p->image_path ? 1 : 0)->take(3)->values();

        $branches = Site::where('status', true)->orderBy('name')->get();

        return view('website.home', [
            'company' => $company,
            'categories' => $categories,
            'products' => $products,
            'featuredProducts' => $featuredProducts,
            'branches' => $branches,
            'heroStats' => $this->catalogStats(),
        ]);
    }

    public function about()
    {
        return view('website.about', [
            'company' => CompanySetting::current(),
            'branches' => Site::where('status', true)->orderBy('name')->get(),
            'stats' => $this->catalogStats(),
        ]);
    }

    /**
     * Real figures for the hero/about "at a glance" stat rows — never
     * invented copy, so it stays honest as the catalog grows (or starts
     * empty).
     */
    private function catalogStats(): array
    {
        return [
            'products' => Product::where('status', true)->count(),
            'categories' => Category::whereNull('parent_id')->where('status', true)
                ->whereHas('products', fn ($q) => $q->where('status', true))
                ->count(),
            'branches' => Site::where('status', true)->count(),
        ];
    }

    /**
     * Static placeholder — no gallery/press content model exists yet
     * (explicit decision: ship the page design now, wire real content
     * later).
     */
    public function media()
    {
        return view('website.media', ['company' => CompanySetting::current()]);
    }

    /**
     * Static "we're hiring" page — no job-posting management exists yet
     * (explicit decision: a real listings feature is a separate, later
     * piece of work).
     */
    public function career()
    {
        return view('website.career', ['company' => CompanySetting::current()]);
    }

    public function contact()
    {
        return view('website.contact', [
            'company' => CompanySetting::current(),
            'branches' => Site::where('status', true)->orderBy('name')->get(),
        ]);
    }

    public function submitContact(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        ContactMessage::create($validated);

        return back()->with('success', "Thanks, {$validated['name']} — we've received your message and will get back to you soon.");
    }
}
