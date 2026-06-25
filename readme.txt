=== AI Display Checklist for WPForms ===
Contributors: lstred
Tags: wpforms, ai, checklist, image analysis, openai, gpt-4o, display cards
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Analyze uploaded display-board photos with AI to automatically check or uncheck WPForms checklist items matching detected card names.

== Description ==

**AI Display Checklist for WPForms** connects an image-upload field in any WPForms form to an AI vision model (OpenAI GPT-4o by default). After a user uploads a photo of a flooring or carpet display board, the plugin:

1. Sends the image to the AI for analysis.
2. Identifies which display-card names are visible.
3. Compares detected names against one or more WPForms checkbox/checklist fields.
4. Checks or unchecks the matching items automatically (based on your configured action mode).
5. Shows a clear review panel so the user can confirm or override changes before submitting.

**The form is never submitted automatically.** The plugin only assists the user in filling out the checklist.

= Primary use case =

A user photographs a flooring showroom's sample-board display wall. Each binder/card has a product name printed vertically. The WPForms form has a checklist of expected product names. The plugin detects which names are visible and marks them accordingly.

= Features =

* **Multiple form mappings** – configure different rules for different forms.
* **Check or Uncheck mode** – check detected items, or uncheck them (for "remove from active display" workflows).
* **Auto-analyze or manual button** – trigger analysis on upload or via an "Analyze Image" button.
* **Detailed review panel** – shows matched items, visible-but-unmatched cards, low-confidence detections, and ignored text.
* **Server-side fuzzy matching** – normalises punctuation, capitalisation, roman numerals, and common OCR mistakes.
* **Rate limiting** – prevents abuse by limiting analysis requests per IP.
* **Debug logging** – optional, off by default; API keys and image data are never logged.
* **Provider interface** – designed to support Anthropic Claude or Google Gemini in future versions.

= Requirements =

* WordPress 5.8+
* PHP 7.4+
* WPForms (Lite or Pro) installed and active
* An OpenAI API key with access to GPT-4o (or GPT-4o-mini)

== Installation ==

1. Download the plugin ZIP file.
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP file and click **Install Now**.
4. Activate the plugin.
5. Go to **Settings → AI Display Checklist** to configure.

= Manual installation =

1. Unzip the file and upload the `ai-display-checklist-for-wpforms` folder to `/wp-content/plugins/`.
2. Activate via **Plugins → Installed Plugins**.

== Setup Instructions ==

= Step 1 – Add your OpenAI API key =

1. Go to **Settings → AI Display Checklist**.
2. Under **General Settings**, paste your OpenAI API key (starts with `sk-`).
3. Choose your model (GPT-4o is recommended for best accuracy).
4. Click **Test AI Connection** to verify the key works.
5. Click **Save Settings**.

= Step 2 – Create a WPForms form =

Your form needs at minimum:
* A **File Upload** field (set to accept images).
* One or more **Checkbox** fields containing your display-card names as choices.

= Step 3 – Add a mapping =

1. Switch to the **Form Mappings** tab.
2. Click **Add New Mapping**.
3. Select your WPForms form.
4. Select the **Image Upload Field** the user will drop their photo into.
5. Select one or more **Checklist Fields** (checkbox fields) to compare against the image.
6. Choose **Action Mode**:
   * *Check detected items* – ticks checkboxes when the AI sees the card in the photo.
   * *Uncheck detected items* – removes ticks when the AI sees the card (useful for "items to pull from display" workflows).
7. Choose whether analysis runs **automatically** after upload or only when the user clicks the Analyze button.
8. Set the confidence threshold (0.65 is a good starting point).
9. Enable the mapping and save.

= Step 4 – Place the form on a page =

Add the WPForms shortcode or block to a page as usual. The plugin's scripts are only loaded on pages containing a configured, enabled form.

== Security Notes ==

* All AJAX and REST requests are protected with WordPress nonces.
* The API key is stored in `wp_options` and never printed back to the browser.
* File uploads are validated by MIME type (finfo), size, and `getimagesize()` before being passed to the AI.
* Only the explicitly configured checkbox fields are modified. The plugin cannot affect unrelated checkboxes or other forms.
* Rate limiting is applied per IP address using WordPress transients.
* Debug logging is disabled by default. When enabled, it writes to the standard WordPress error log. API keys and image binary data are never written to logs.
* No form submissions are altered. No WPForms form definitions are modified.

== Testing Instructions ==

1. **WPForms inactive** – deactivate WPForms; verify the plugin shows a warning in the admin and does not error on the front end.
2. **No API key** – leave the API key blank; verify an appropriate error is returned when the Analyze button is clicked.
3. **Invalid API key** – enter a wrong key; use "Test AI Connection" to confirm the error is surfaced clearly.
4. **Large image** – upload an image over the configured max size; verify rejection with a friendly message.
5. **Non-image file** – upload a PDF or text file; verify rejection.
6. **AI detects checklist match** – upload a real display-board photo; verify the correct checkbox is ticked.
7. **AI detects card not in checklist** – verify unmatched cards appear in the review panel but no checkbox is changed.
8. **Action mode = uncheck detected** – pre-tick all checkboxes; upload the image; verify matching items are unticked.
9. **Multiple checklist fields** – configure two checkbox fields; verify both are updated correctly.
10. **Manual override** – after AI updates checkboxes, manually change one; verify the user's change is respected.
11. **Two forms on same page** – only the configured form should have the Analyze button; the other should be unaffected.
12. **Rate limit** – exceed the configured request limit; verify a 429 error is returned.

== Frequently Asked Questions ==

= Does the plugin submit the form for me? =
No. It only helps fill in the checklist. The user must review and click Submit themselves.

= Can I use a different AI provider? =
Currently only OpenAI is supported. The codebase includes a provider interface (`AICWF_AI_Provider_Interface`) so Anthropic Claude and Google Gemini can be added in future versions.

= Will this affect forms I haven't configured? =
No. The plugin is inactive on all forms that do not have an enabled mapping.

= Is the image stored anywhere? =
The image is read from the temporary upload location, sent to the AI API (as a base64 data URI), and then the temporary file is discarded as part of the normal PHP request lifecycle. The plugin does not save images to the Media Library or any other location.

== Changelog ==

= 1.0.0 =
* Initial release.
* OpenAI GPT-4o vision integration.
* Admin settings page with form mapping management.
* Server-side name matching with normalisation and fuzzy scoring.
* Rate limiting, file validation, and nonce-protected REST endpoint.
* Detailed front-end review panel.
