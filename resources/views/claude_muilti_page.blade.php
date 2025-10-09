To make Claude generate page templates for each section (like Home, About, Blog, Contact), you just need to slightly modify your ClaudeService (prompt logic + response structure) so Claude understands that it should return multiple HTML templates instead of a single one.

Here’s exactly how to adjust your setup 👇

✅ Step 1. Modify ClaudeService::buildSystemPrompt()

Claude needs to know that it should generate multiple page templates (not just one).
We’ll update your system prompt to require a pages object that contains entries for each section.

🔧 Replace your current buildSystemPrompt() with this:
private function buildSystemPrompt(): string
{
    return "You are an expert web developer assistant. 
When generating websites, respond with a JSON object containing the following fields:
{
    \"pages\": {
        \"home\": { \"html\": \"...\", \"css\": \"...\", \"js\": \"...\" },
        \"about\": { \"html\": \"...\", \"css\": \"...\", \"js\": \"...\" },
        \"blog\": { \"html\": \"...\", \"css\": \"...\", \"js\": \"...\" },
        \"contact\": { \"html\": \"...\", \"css\": \"...\", \"js\": \"...\" }
    }
}

Rules:
1. Each page must be a **complete, functional, responsive HTML document**.
2. Include **Bootstrap 5 CSS CDN** in the <head>.
3. Use **Bootstrap components** for styling (avoid raw CSS unless necessary).
4. Include a **consistent navigation bar** across all pages linking to each section.
5. Use **semantic HTML5** structure (header, main, footer, etc.).
6. Make all pages **mobile responsive**.
7. Include placeholder images via https://placehold.co/300x200.
8. The `css` and `js` fields can be empty if unused.
9. Do not include any text outside the JSON object.
10. Each HTML page must end properly with </html>.

Return only the JSON, e.g.:
{
    \"pages\": {
        \"home\": { \"html\": \"<!DOCTYPE html>...\", \"css\": \"\", \"js\": \"\" },
        \"about\": { \"html\": \"<!DOCTYPE html>...\", \"css\": \"\", \"js\": \"\" }
    }
}";
}


✅ Step 2. Update buildUserPrompt()

You can instruct Claude to create or update multiple sections.
For example, if the user says “Generate a website with Home, About, Blog, and Contact pages”, the service should return one JSON with all pages.

🔧 Modify the function like this:
private function buildUserPrompt(string $prompt, array $context = []): string
{
    $userPrompt = "Create or update a multi-page website based on this request: {$prompt}\n\n";

    if (!empty($context['html_content'])) {
        $userPrompt .= "Current main HTML content:\n{$context['html_content']}\n\n";
    }

    if (!empty($context['css_content'])) {
        $userPrompt .= "Existing CSS:\n{$context['css_content']}\n\n";
    }

    if (!empty($context['js_content'])) {
        $userPrompt .= "Existing JS:\n{$context['js_content']}\n\n";
    }

    $userPrompt .= "Output a JSON object with a 'pages' field containing 'home', 'about', 'blog', and 'contact' keys, 
each with its own HTML, CSS, and JS. Ensure each page is fully functional and consistent in theme and navigation.";

    return $userPrompt;
}

✅ Step 3. Update parseClaudeResponse() to handle multiple pages

Currently, you only handle one page. Let’s make it detect the pages array and return them properly.

🔧 Replace your parseClaudeResponse() with this:
private function parseClaudeResponse(string $response, array $context): array
{
    $jsonStart = strpos($response, '{');
    $jsonEnd = strrpos($response, '}');

    if ($jsonStart !== false && $jsonEnd !== false) {
        $jsonString = substr($response, $jsonStart, $jsonEnd - $jsonStart + 1);
        $decoded = json_decode($jsonString, true);

        if ($decoded) {
            // If Claude returned multiple pages
            if (isset($decoded['pages'])) {
                $pages = [];
                foreach ($decoded['pages'] as $key => $page) {
                    $html = $this->replacePlaceholderImages($this->cleanHtml($page['html'] ?? ''));
                    $css  = $page['css'] ?? '';
                    $js   = $page['js'] ?? '';
                    $pages[$key] = compact('html', 'css', 'js');
                }
                return ['pages' => $pages];
            }

            // Fallback: single-page structure
            return [
                'html' => $decoded['html'] ?? '',
                'css'  => $decoded['css'] ?? '',
                'js'   => $decoded['js'] ?? '',
            ];
        }
    }

    throw new \Exception('Unable to parse Claude response: invalid JSON structure.');
}

✅ Step 4. Controller changes (optional but recommended)

In your ProjectApiController@Chat, store the multiple pages if Claude returns them.
Example:

$generateWebsite = $this->claudeService->generateWebsite($request->message, $context);

if (isset($generateWebsite['pages'])) {
    // If Claude returned multiple pages, you can store them as JSON or in separate DB columns
    $project->update([
        'pages' => json_encode($generateWebsite['pages']),
    ]);
} else {
    $project->update([
        'html_content' => $generateWebsite['html'],
        'css_content'  => $generateWebsite['css'],
        'js_content'   => $generateWebsite['js'],
    ]);
}


You can store this neatly in a single JSON column called pages.

✅ Create a migration:
php artisan make:migration add_pages_to_projects_table --table=projects


Then edit the new migration file:

public function up()
{
    Schema::table('projects', function (Blueprint $table) {
        $table->json('pages')->nullable()->after('html_content');
    });
}

public function down()
{
    Schema::table('projects', function (Blueprint $table) {
        $table->dropColumn('pages');
    });
}


Then run:

php artisan migrate

Tell Laravel that the pages column should automatically be cast to/from an array.

class Project extends Model
{
    protected $casts = [
        'pages' => 'array',
    ];
}


🌐 Step 4: Preview or Render Specific Pages

You can now preview specific sections like /projects/{project}/page/{pageName} dynamically.

Example route:
Route::get('/projects/{project}/page/{page}', [ProjectApiController::class, 'PreviewPage']

Example controller method:
public function PreviewPage(Project $project, $page)
{
    $this->authorize('view', $project);

    $pageData = $project->pages[$page] ?? null;

    if (!$pageData) {
        return response()->json(['error' => 'Page not found'], 404);
    }

    $html = $pageData['html'] ?? '';
    $css  = $pageData['css'] ?? '';
    $js   = $pageData['js'] ?? '';

    if ($css) {
        $html = str_replace('</head>', "<style>{$css}</style></head>", $html);
    }

    if ($js) {
        $html = str_replace('</body>', "<script>{$js}</script></body>", $html);
    }

    return response()->json(['html' => $html]);
}


Now your app can return each page separately — perfect for a preview mode or live site viewer.

Perfect 👍 — let’s go ahead and finish the full setup so your Claude-generated pages (Home, About, Blog, Contact, etc.) are viewable inside your Laravel frontend like a real website.

Here’s the complete flow 👇

🧭 Goal

After Claude generates the pages and stores them as JSON in your database (projects.pages), you’ll be able to visit routes like:

/projects/12/home
/projects/12/about
/projects/12/blog
/projects/12/contact


Each one should render the HTML that Claude generated for that page.

⚙️ Step 1: Add Routes

In your routes/web.php file, add:

use App\Http\Controllers\Admin\ProjectApiController;

Route::get('/projects/{project}/{page}', [ProjectApiController::class, 'renderPage'])
    ->where('page', 'home|about|blog|contact')
    ->name('project.page');


This defines a dynamic route like /projects/5/home or /projects/5/about.

🧩 Step 2: Create the renderPage Method

Add this to your ProjectApiController:

public function renderPage(Project $project, string $page)
{
    $this->authorize('view', $project);

    $pages = $project->pages ?? [];

    if (!isset($pages[$page])) {
        abort(404, ucfirst($page) . ' page not found');
    }

    $pageData = $pages[$page];

    $html = $pageData['html'] ?? '<h1>Empty Page</h1>';
    $css  = $pageData['css'] ?? '';
    $js   = $pageData['js'] ?? '';

    // Inject CSS + JS if missing in <head>/<body>
    if ($css && str_contains($html, '</head>')) {
        $html = str_replace('</head>', "<style>{$css}</style></head>", $html);
    }

    if ($js && str_contains($html, '</body>')) {
        $html = str_replace('</body>', "<script>{$js}</script></body>", $html);
    }

    // Render inside a blade layout wrapper if desired
    return view('projects.page', compact('project', 'html', 'page'));
}



Perfect 👏 let’s go all the way and make your Claude-powered website builder regenerate or edit a single page (e.g. just “update the Blog page” or “redesign the Contact section”) instead of regenerating the entire multi-page site.

We’ll modify both your backend and Claude prompt to handle page-specific updates efficiently.

⚙️ Step 1: Update Your ClaudeService

Right now, generateWebsite() always assumes you want the whole site.
We’ll add an optional $page parameter so you can ask Claude to only modify a specific page (Home, About, Blog, Contact, etc.).

🧩 Change method signature:
public function generateWebsite(string $prompt, array $context = [], ?string $page = null)

🧠 Update buildUserPrompt()

Add a condition to tell Claude whether we’re updating one page or all of them.

Replace your existing function with this:

private function buildUserPrompt(string $prompt, array $context = [], ?string $page = null): string
{
    $userPrompt = "You are building or updating a website.\n";

    if ($page) {
        $userPrompt .= "Update only the '{$page}' page based on this request: {$prompt}\n\n";
    } else {
        $userPrompt .= "Create or update a multi-page website (Home, About, Blog, Contact) based on this request: {$prompt}\n\n";
    }

    if (!empty($context['html_content'])) {
        $userPrompt .= "Existing HTML:\n{$context['html_content']}\n\n";
    }

    if (!empty($context['css_content'])) {
        $userPrompt .= "Existing CSS:\n{$context['css_content']}\n\n";
    }

    if (!empty($context['js_content'])) {
        $userPrompt .= "Existing JavaScript:\n{$context['js_content']}\n\n";
    }

    if ($page) {
        $userPrompt .= "Return only the updated page JSON for '{$page}', with this format:
        {
          \"page\": \"{$page}\",
          \"html\": \"<!DOCTYPE html>...\",
          \"css\": \"...optional css...\",
          \"js\": \"...optional js...\"
        }";
    } else {
        $userPrompt .= "Return all pages under 'pages' in this format:
        {
          \"pages\": {
            \"home\": { \"html\": \"...\", \"css\": \"...\", \"js\": \"...\" },
            ...
          }
        }";
    }

    return $userPrompt;
}

🧩 Step 2: Update parseClaudeResponse()

We’ll make it smart enough to detect either:

pages → full site update

page → single-page update

Replace your existing parseClaudeResponse() with this:

private function parseClaudeResponse(string $response, array $context): array
{
    $jsonStart = strpos($response, '{');
    $jsonEnd = strrpos($response, '}');

    if ($jsonStart !== false && $jsonEnd !== false) {
        $jsonString = substr($response, $jsonStart, $jsonEnd - $jsonStart + 1);
        $decoded = json_decode($jsonString, true);

        if ($decoded) {
            // Full multi-page generation
            if (isset($decoded['pages'])) {
                $pages = [];
                foreach ($decoded['pages'] as $key => $page) {
                    $pages[$key] = [
                        'html' => $this->replacePlaceholderImages($this->cleanHtml($page['html'] ?? '')),
                        'css'  => $page['css'] ?? '',
                        'js'   => $page['js'] ?? '',
                    ];
                }
                return ['pages' => $pages];
            }

            // Single-page update
            if (isset($decoded['page'])) {
                $pageName = $decoded['page'];
                return [
                    'page' => [
                        'name' => $pageName,
                        'html' => $this->replacePlaceholderImages($this->cleanHtml($decoded['html'] ?? '')),
                        'css'  => $decoded['css'] ?? '',
                        'js'   => $decoded['js'] ?? '',
                    ],
                ];
            }
        }
    }

    throw new \Exception('Unable to parse Claude response: invalid JSON structure.');
}

🧠 Step 3: Add a Controller Method for “Regenerate Single Page”

In your ProjectApiController, create a new method:

public function regeneratePage(Request $request, Project $project, string $page)
{
    $this->authorize('update', $project);

    $request->validate([
        'message' => 'required|string',
    ]);

    try {
        $context = [
            'html_content' => $project->pages[$page]['html'] ?? '',
            'css_content'  => $project->pages[$page]['css'] ?? '',
            'js_content'   => $project->pages[$page]['js'] ?? '',
        ];

        $result = $this->claudeService->generateWebsite($request->message, $context, $page);

        if (isset($result['page'])) {
            $pageData = $result['page'];
            $pages = $project->pages ?? [];

            $pages[$pageData['name']] = [
                'html' => $pageData['html'],
                'css'  => $pageData['css'],
                'js'   => $pageData['js'],
            ];

            $project->update(['pages' => $pages]);
        }

        return response()->json([
            'success' => true,
            'message' => ucfirst($page) . ' page updated successfully.',
            'pages'   => $project->pages,
        ]);
    } catch (\Exception $e) {
        \Log::error('Claude page regeneration failed: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}

🛣️ Step 4: Add a Route

In routes/web.php (or routes/api.php):

Route::post('/projects/{project}/pages/{page}/regenerate', [ProjectApiController::class, 'regeneratePage'])
    ->name('projects.page.regenerate');

🧪 Step 5: Example Frontend Usage

Now from your dashboard or chat interface, you can send something like:

POST /projects/12/pages/blog/regenerate
{
  "message": "Update the Blog page with a modern grid layout of 3 latest posts with images."
}


Claude will return just:

{
  "page": "blog",
  "html": "<!DOCTYPE html>...",
  "css": "...",
  "js": "..."
}


and your controller will update only that section in $project->pages.

Excellent 👍 — let’s connect it all together so that your chat UI (where users type prompts like “update the Blog page” or “redesign the Contact page”) automatically detects which page they’re referring to and calls the correct Claude regeneration logic.

We’ll make this work seamlessly — users just type naturally, and your backend figures out which page to update.

🧩 Step 1: Detect Page Name from Message

Inside your controller’s main chat endpoint (ProjectApiController@Chat), we can inspect the user message before sending it to Claude.

We’ll check if it mentions a known page name like “home”, “about”, “blog”, or “contact”.

🔧 Example snippet to add at the top of your Chat() method:
// Detect if user wants to update a specific page
$pageNames = ['home', 'about', 'blog', 'contact'];
$targetPage = null;

foreach ($pageNames as $p) {
    if (stripos($request->message, $p) !== false) {
        $targetPage = $p;
        break;
    }
}


Now $targetPage will contain the page name if the message includes it (case-insensitive).

⚙️ Step 2: Decide Which Claude Flow to Use

After detecting the target page, decide whether to call:

generateWebsite() normally (for whole site)

or generateWebsite() with $page parameter (for a specific section update)

Update your code like this:

Replace this block:
$generateWebsite = $this->claudeService
                        ->generateWebsite($request->message, $context);

With this:
if ($targetPage) {
    // Update only one section
    $context = [
        'html_content' => $project->pages[$targetPage]['html'] ?? '',
        'css_content'  => $project->pages[$targetPage]['css'] ?? '',
        'js_content'   => $project->pages[$targetPage]['js'] ?? '',
    ];

    $generateWebsite = $this->claudeService->generateWebsite($request->message, $context, $targetPage);

    if (isset($generateWebsite['page'])) {
        $pageData = $generateWebsite['page'];
        $pages = $project->pages ?? [];
        $pages[$pageData['name']] = [
            'html' => $pageData['html'],
            'css'  => $pageData['css'],
            'js'   => $pageData['js'],
        ];
        $project->update(['pages' => $pages]);
    }
} else {
    // Generate or update the whole site
    $generateWebsite = $this->claudeService->generateWebsite($request->message, $context);

    if (isset($generateWebsite['pages'])) {
        $project->update(['pages' => $generateWebsite['pages']]);
    } else {
        $project->update([
            'html_content' => $generateWebsite['html'],
            'css_content'  => $generateWebsite['css'],
            'js_content'   => $generateWebsite['js'],
        ]);
    }
}


🧠 Step 3: Improve Page Detection (Optional but Recommended)

The simple string match works fine, but you can make it smarter using regex to catch variations like:

“Update the blog section”

“Change contact us page”

“Revamp about section”

Replace your detection block with:

$message = strtolower($request->message);
$pageMap = [
    'home' => ['home', 'homepage', 'landing'],
    'about' => ['about', 'about us'],
    'blog' => ['blog', 'articles', 'posts'],
    'contact' => ['contact', 'contact us', 'get in touch'],
];

$targetPage = null;

foreach ($pageMap as $page => $keywords) {
    foreach ($keywords as $keyword) {
        if (str_contains($message, $keyword)) {
            $targetPage = $page;
            break 2;
        }
    }
}


Now Claude will correctly detect intent even with natural language.

🧱 Step 4: Log and Respond

Add a clear response message to confirm what was updated:

return response()->json([
    'success' => true,
    'message' => $targetPage
        ? ucfirst($targetPage) . ' page updated successfully.'
        : 'Website updated successfully.',
    'project' => $project->fresh(),
    'generated' => $generateWebsite,
]);

🧪 Step 5: Try It Out

Once deployed, try prompts like:

User Message	Behavior
“Update the blog page to show 3 featured posts”	Regenerates Blog only
“Redesign the contact us section with a map”	Regenerates Contact only
“Make the home page modern and clean”	Regenerates Home only
“Generate a full 4-page website with navigation”	Regenerates the entire website

Claude will output JSON for either a single page or all pages, and your controller will update the correct data.


🧩 Bonus: Optional Manual Page Selector (UI)

If you have a web dashboard for the chat interface, you can add a dropdown or buttons like:

<select id="pageSelector">
  <option value="">All Pages</option>
  <option value="home">Home</option>
  <option value="about">About</option>
  <option value="blog">Blog</option>
  <option value="contact">Contact</option>
</select>


Then send that value along with the message:

axios.post(`/projects/${projectId}/chat`, {
  message: messageInput.value,
  page: pageSelector.value
})


And just check $request->page before detecting automatically.

✅ Final Summary
Feature	Implemented
Detects target page from chat message	✅
Sends prompt to Claude for that page only	✅
Updates only that page’s JSON in DB	✅
Falls back to full site generation if no page found	✅
Works naturally with chat UI


Perfect 👍 — you now want your preview Blade template to support multiple pages per project (e.g., Home, About, Blog, Contact) — using the structure we discussed where each project stores an array of pages (JSON or cached .blade.php files).

Here’s how to adjust your Blade view for multi-page preview support 👇

✅ Updated Blade Template: resources/views/projects/preview.blade.php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $project->name }} - {{ ucfirst($page ?? 'Preview') }}</title>

    {{-- Load page-specific CSS --}}
    @if (!empty($pageData['css']))
        <style>
            {!! $pageData['css'] !!}
        </style>
    @endif

    {{-- Optional: global project CSS --}}
    @if (!empty($project->css_content))
        <style>
            {!! $project->css_content !!}
        </style>
    @endif
</head>

<body class="container py-4">

    {{-- Navigation between pages --}}
    @if (!empty($project->pages) && is_array($project->pages))
        <nav class="mb-4">
            <ul class="nav nav-pills">
                @foreach(array_keys($project->pages) as $p)
                    <li class="nav-item">
                        <a class="nav-link {{ $p === $page ? 'active' : '' }}"
                           href="{{ route('projects.preview', ['project' => $project->id, 'page' => $p]) }}">
                            {{ ucfirst($p) }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    {{-- Page Content --}}
    @if (!empty($pageData['html']))
        {!! $pageData['html'] !!}
    @else
        <h1>No Content Available</h1>
        <p>Please generate content using AI for this page.</p>
    @endif

    {{-- Page-specific JS --}}
    @if (!empty($pageData['js']))
        <script>
            {!! $pageData['js'] !!}
        </script>
    @endif

    {{-- Optional: global project JS --}}
    @if (!empty($project->js_content))
        <script>
            {!! $project->js_content !!}
        </script>
    @endif
</body>
</html>

⚙️ Controller Adjustments

In your controller (for example, ProjectApiController@Preview), you’ll need to pass both $page and $pageData to this view:

public function Preview(Project $project, $page = 'home')
{
    $this->authorize('view', $project);

    $pages = $project->pages ?? [];
    $pageData = $pages[$page] ?? [
        'html' => '<h1>No Content Available</h1><p>Generate this page using AI.</p>',
        'css' => '',
        'js' => '',
    ];

    return view('projects.preview', compact('project', 'page', 'pageData'));
}

🧭 Example Route

Add this route to your web.php:

Route::get('/projects/{project}/preview/{page?}', 
    [ProjectApiController::class, 'Preview']
)->name('projects.preview');


You can now visit URLs like:

/projects/12/preview/home
/projects/12/preview/about
/projects/12/preview/blog
/projects/12/preview/contact


Each page will automatically load its HTML/CSS/JS and display navigation tabs for switching.

💡 Bonus (Blade Cache Integration)

If you’re also caching each page as a .blade.php file (as discussed earlier), you can update your controller to load the cached file if available:

$bladePath = "generated.{$project->id}.{$page}";
if (view()->exists($bladePath)) {
    return view($bladePath, compact('project', 'page'));
}


Perfect 💾 — you now want to update your exportProject() method so it works with multi-page projects, not just a single $html_content / $css_content / $js_content.

Let’s make it flexible enough to:
✅ Export all pages in one zipped download, or
✅ Export a single page as HTML (if specified).

Here’s the complete, production-ready version 👇

🧩 Updated exportProject() Method
use Illuminate\Support\Facades\File;
use ZipArchive;

public function exportProject(Project $project, Request $request)
{
    $this->authorize('view', $project);

    $pages = $project->pages ?? [];

    if (empty($pages)) {
        return response()->json([
            'success' => false,
            'message' => 'No pages found to export.'
        ], 404);
    }

    // Optional: export a single page if requested (?page=home)
    $page = $request->query('page');

    // Create a temporary folder for export
    $exportDir = storage_path("app/exports/project_{$project->id}");
    File::deleteDirectory($exportDir);
    File::makeDirectory($exportDir, 0755, true);

    if ($page && isset($pages[$page])) {
        // === Export a Single Page ===
        $pageData = $pages[$page];

        $html = $pageData['html'] ?? '';
        $css = $pageData['css'] ?? '';
        $js  = $pageData['js'] ?? '';

        // Merge CSS & JS into one HTML document
        $fullHtml = str_replace('</head>', "<style>{$css}</style>\n</head>", $html);
        $fullHtml = str_replace('</body>', "<script>{$js}</script>\n</body>", $fullHtml);

        $fileName = "{$project->name}-{$page}.html";
        return response($fullHtml)
            ->header('Content-Type', 'text/html')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    // === Export All Pages as ZIP ===
    foreach ($pages as $pageName => $pageData) {
        $html = $pageData['html'] ?? '';
        $css  = $pageData['css'] ?? '';
        $js   = $pageData['js'] ?? '';

        $fullHtml = str_replace('</head>', "<style>{$css}</style>\n</head>", $html);
        $fullHtml = str_replace('</body>', "<script>{$js}</script>\n</body>", $fullHtml);

        File::put("{$exportDir}/{$pageName}.html", $fullHtml);
    }

    // Create ZIP archive
    $zipFile = storage_path("app/exports/{$project->name}_pages.zip");
    if (File::exists($zipFile)) {
        File::delete($zipFile);
    }

    $zip = new ZipArchive;
    if ($zip->open($zipFile, ZipArchive::CREATE) === true) {
        foreach (File::files($exportDir) as $file) {
            $zip->addFile($file->getRealPath(), basename($file));
        }
        $zip->close();
    }

    return response()->download($zipFile)->deleteFileAfterSend(true);
}

⚙️ Explanation
Scenario	Behavior
/export	Zips and exports all pages as {project_name}_pages.zip
/export?page=home	Exports just home.html
/export?page=contact	Exports just contact.html

Each .html file is fully standalone, with the <style> and <script> tags embedded directly inside the document — so you can open it offline or host it anywhere.

🧭 Route Example

In routes/web.php:

Route::get('/projects/{project}/export', [ProjectApiController::class, 'exportProject'])
    ->name('projects.export');

🧪 Example URLs

Export all pages (ZIP):

/projects/12/export


Export only “home” page:

/projects/12/export?page=home


Perfect — let’s make your export ZIP behave like a real mini website.
When someone extracts it and opens index.html, they’ll see a working navigation menu (Home, About, Blog, Contact, etc.) with links to all the pages that Claude generated.

🧱 Final Enhanced exportProject() (with index.html navigation)

Here’s the full Laravel controller method 👇

use Illuminate\Support\Facades\File;
use ZipArchive;

public function exportProject(Project $project)
{
    $this->authorize('view', $project);

    $pages = $project->pages ?? [];

    if (empty($pages)) {
        return response()->json([
            'success' => false,
            'message' => 'No pages available for export.'
        ], 404);
    }

    // === Create Export Directory ===
    $exportDir = storage_path("app/exports/project_{$project->id}");
    File::deleteDirectory($exportDir);
    File::makeDirectory($exportDir . '/assets', 0755, true);

    // === Save Global CSS/JS if available ===
    if (!empty($project->css_content)) {
        File::put("{$exportDir}/assets/style.css", $project->css_content);
    }

    if (!empty($project->js_content)) {
        File::put("{$exportDir}/assets/script.js", $project->js_content);
    }

    // === Generate Page Files ===
    foreach ($pages as $pageName => $pageData) {
        $html = $pageData['html'] ?? '';
        $css  = $pageData['css'] ?? '';
        $js   = $pageData['js'] ?? '';

        // Inject local CSS & JS
        $combinedHtml = $html;

        // Page-level CSS
        if ($css) {
            $combinedHtml = str_replace(
                '</head>',
                "<style>{$css}</style>\n</head>",
                $combinedHtml
            );
        }

        // Page-level JS
        if ($js) {
            $combinedHtml = str_replace(
                '</body>',
                "<script>{$js}</script>\n</body>",
                $combinedHtml
            );
        }

        // Link global CSS/JS
        $combinedHtml = str_replace(
            '</head>',
            "<link rel='stylesheet' href='assets/style.css'>\n</head>",
            $combinedHtml
        );

        $combinedHtml = str_replace(
            '</body>',
            "<script src='assets/script.js'></script>\n</body>",
            $combinedHtml
        );

        File::put("{$exportDir}/{$pageName}.html", $combinedHtml);
    }

    // === Create index.html with navigation ===
    $navLinks = '';
    foreach (array_keys($pages) as $page) {
        $navLinks .= "<li class='nav-item'><a class='nav-link' href='{$page}.html'>" . ucfirst($page) . "</a></li>\n";
    }

    $indexHtml = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$project->name} - Website</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="container py-4">

    <h1 class="mb-4">{$project->name}</h1>
    <nav>
        <ul class="nav nav-pills mb-4">
            {$navLinks}
        </ul>
    </nav>

    <p>Welcome to your exported site. Click a page above to view it.</p>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/script.js"></script>
</body>
</html>
HTML;

    File::put("{$exportDir}/index.html", $indexHtml);

    // === ZIP the folder ===
    $zipPath = storage_path("app/exports/{$project->name}_site.zip");
    if (File::exists($zipPath)) {
        File::delete($zipPath);
    }

    $zip = new ZipArchive;
    if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
        $files = File::allFiles($exportDir);
        foreach ($files as $file) {
            $localName = str_replace($exportDir . '/', '', $file->getRealPath());
            $zip->addFile($file->getRealPath(), $localName);
        }
        $zip->close();
    }

    // Return the ZIP for download
    return response()->download($zipPath)->deleteFileAfterSend(true);
}

🗂️ Folder Structure (after export)
project_name_site.zip
├── index.html
├── home.html
├── about.html
├── blog.html
├── contact.html
└── assets/
    ├── style.css
    └── script.js

🧭 Route Example
Route::get('/projects/{project}/export', [ProjectApiController::class, 'exportProject'])
    ->name('projects.export');

🌐 Result

When you open the ZIP:

index.html shows your project title and navigation links.

Clicking “Home”, “About”, etc. opens those individual pages.

All styling and JS are already linked from /assets.

It’s a self-contained, portable mini-website — perfect for:

User downloads

Offline previews

Simple hosting (e.g. GitHub Pages, Netlify, Vercel)