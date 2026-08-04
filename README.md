# Floorplan Studio

A web app where users upload a floorplan image (hand-drawn or digital), Claude analyzes it
and redraws it as a clean, vector floorplan, and users can then draw changes directly on top
to have Claude regenerate an updated version.

## How it actually works (read this first)

Claude cannot generate photo-like images. What it *can* do very well is look at your floorplan
photo and understand its structure. So the pipeline is:

1. You upload a photo/scan/screenshot of a floorplan (hand-drawn or system-made).
2. Claude's vision looks at it and extracts the structure — walls, rooms, doors, windows,
   dimensions — into a structured JSON format.
3. The app renders that JSON as a clean SVG floorplan in the browser. **This SVG is your
   "generic system-made floorplan."**
4. You draw on top of it (pen tool for marking walls/rooms to add or remove, note tool for
   typed instructions like "make this bedroom bigger").
5. Those marks, plus the current structured JSON, go back to Claude, which returns an updated
   JSON. The app re-renders it as the new version.
6. Every version is saved, so you can browse history and go back.

This is a genuinely working pipeline, not a placeholder — but it's worth understanding that the
"redraw" step produces a clean vector drawing derived from the image's structure, not a pixel-editing
of the original photo.

## What's in this package

```
config/config.php        <- fill in your DB + Anthropic API key here
sql/schema.sql            <- import this into your MySQL database
includes/                 <- DB connection, auth, Claude API integration
api/                       <- AJAX endpoints (create project, analyze, regenerate, etc.)
assets/css/style.css       <- all styling
assets/js/                 <- SVG renderer, drawing tool, page logic
index.php, editor.php, login.php, register.php, logout.php
uploads/                   <- uploaded floorplan images get stored here (writable)
```

## Setup on cPanel

### 1. Create the database
In cPanel → **MySQL Databases**:
- Create a database (e.g. `yourcpaneluser_floorplans`)
- Create a database user and password
- Add that user to the database with **All Privileges**

### 2. Import the schema
In cPanel → **phpMyAdmin**, select your new database, go to the **Import** tab, and upload
`sql/schema.sql`. This creates three tables: `users`, `projects`, `floorplan_versions`.

### 3. Get an Anthropic API key
Sign up at https://console.anthropic.com/ and create an API key. This app calls the real
Anthropic API directly from PHP (via cURL), so you need your own key and it will use your
own API credits. Vision-capable models are required (the config defaults to `claude-sonnet-5`).

### 4. Edit config/config.php
Open `config/config.php` and fill in:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'yourcpaneluser_floorplans');
define('DB_USER', 'yourcpaneluser_dbuser');
define('DB_PASS', 'the-password-you-set');

define('ANTHROPIC_API_KEY', 'sk-ant-...');
```

You can leave `OUTBOUND_PROXY` blank unless your host specifically tells you it's required —
see the troubleshooting section below if you run into connection errors.

### 5. Upload the files
Upload the entire contents of this package to your desired directory on cPanel (e.g.
`public_html/` for the domain root, or `public_html/floorplans/` for a subfolder) via
**File Manager** or FTP. Keep the folder structure intact.

### 6. Make uploads/ writable
Make sure the `uploads/` folder is writable by the web server. In File Manager, right-click
`uploads` → Permissions → set to `755` (this is usually already correct after upload, but
check if you get upload errors).

### 7. Visit the site
Go to your domain (or subfolder) in a browser. You'll land on the sign-in page — click
"Create one" to register your first account, then create a project and upload a floorplan image.

## Notes, limits, and things you may want to change

- **Deleting a project**: hover a project card on the dashboard and click the small ✕ in
  the top-right corner. This permanently removes the project, all its versions, and its
  uploaded image.
- **Testing your API setup**: the "Test Claude connection" button on the dashboard sends a
  tiny text-only request (no image) so you can confirm your API key and connectivity work
  before troubleshooting anything image-specific.
- **Authentication is intentionally simple** — username/email + password, PHP sessions,
  `password_hash()`/`password_verify()`. There's no password reset flow or email verification.
  Add these if you're opening this up beyond yourself/your team.
- **Max upload size** is 8MB by default (`MAX_UPLOAD_BYTES` in config.php). Your host's
  `upload_max_filesize` / `post_max_size` PHP settings must also allow this — check cPanel's
  MultiPHP INI Editor if uploads fail.
- **Claude API costs**: each image analysis and each "apply changes" click is one API call.
  Keep an eye on usage in the Anthropic console.
- **The regenerate step summarizes your pen strokes as bounding boxes** (not full stroke
  paths) before sending to Claude, to keep prompts compact. For most edits (mark a wall to
  remove it, circle an area to add a room, note near a spot) this works well. Very intricate
  freehand edits are better described with the note tool.
- **No image-to-image editing**: the original uploaded photo is stored for reference (shown
  in the sidebar) but only the *first* version is derived directly from it; every version
  after that is derived from the structured JSON + your annotations, not from re-reading the
  photo.
- **SVG export**: the "Download SVG" button in the editor saves the current version as a
  standalone `.svg` file you can open in a browser, Illustrator, Inkscape, etc.

## Troubleshooting

- **"Database connection failed"** on any page → check `config/config.php` credentials
  match exactly what you created in cPanel (cPanel DB names/users are usually prefixed with
  your cPanel username, e.g. `myuser_floorplans`).
- **Blank page / 500 error** → check cPanel's **Errors** log (under Metrics) or enable
  display_errors temporarily; almost always a config.php typo or a PHP version issue (this
  app needs PHP 7.4+ with the `mysqli` and `gd`/`fileinfo` extensions, which cPanel enables
  by default).
- **"Anthropic API key is not configured"** → you haven't replaced the placeholder key in
  config.php yet.
- **Upload succeeds but analysis fails** → check the error message shown; it's usually
  either the API key, a network/firewall block on outbound HTTPS from your host (rare on
  cPanel), or a temporarily overloaded Anthropic API (retry).
- **"Claude did not return valid JSON"** → this means the API call itself succeeded (no
  connectivity issue), but Claude's response text couldn't be parsed as the expected floorplan
  JSON. The app extracts JSON robustly (it strips markdown code fences and finds the outermost
  `{...}` even if Claude adds a sentence of commentary around it, despite being told not to),
  and specifically detects and reports if the response was cut off by hitting the `max_tokens`
  limit — but if the model's output was genuinely malformed, this error can still occur.
  Click the failed version in the sidebar to see the full stored error, which now includes
  Claude's actual raw response text — that will show you exactly what came back. If it looks
  like the response was on the right track but got cut short, try increasing `max_tokens` in
  `claude_analyze_image()` / `claude_regenerate()` in `includes/functions.php` (default 8000),
  or try a simpler/smaller source image.

- **"The request body is not valid JSON: ..." errors (empty document, or unexpected character)**
  → in practice, this was ultimately traced to a stray trailing newline/whitespace character in
  the `ANTHROPIC_API_KEY` value in `config/config.php` — easy to introduce invisibly when
  copy-pasting a key from a browser, and devastating because it corrupts the `x-api-key` header
  at the HTTP protocol level (a stray `\r` or `\n` prematurely ends the header line), while
  still *looking* like a normal, fully-sent request from cURL's point of view. This app now
  automatically trims whitespace from the API key (and the optional proxy settings) before
  using them, so this specific mistake can't happen again even if you paste a key with extra
  whitespace. If you ever see this error again:
  1. Double-check `config/config.php` doesn't have obviously wrong values.
  2. Click **"Test Claude connection"** on the dashboard for a quick, isolated check with a
     redacted wire-level debug log.
  3. Click **"Test raw HTTPS POST"** to rule out a network-level issue unrelated to Anthropic.
  4. If both of those look fine but you're still stuck, request IDs from failed calls (visible
     in Anthropic's response, e.g. `req_...`) can be given to Anthropic support directly — they
     can see exactly what their backend received for a specific request, which settles
     ambiguous cases faster than guessing from the client side.

## Tested

This package was built and verified end-to-end in a local PHP + MySQL environment before
delivery: user registration, login/session handling, project creation, image upload and
storage, the Claude-analysis error-handling path, dashboard and editor page rendering, and
project deletion (with file cleanup) all passed. The one thing that could *not* be tested
without your own API key is an actual live call to Claude — that code path follows the
documented Anthropic Messages API format exactly, but you should try it first with a small
test project once your key is in place.
