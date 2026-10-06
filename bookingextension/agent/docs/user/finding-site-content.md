# Finding content across the site

The assistant can search the *content* of your platform — course descriptions, text-and-media
areas, HTML blocks and more — by meaning, not just by exact words. Ask for a topic and it finds
the places where that topic is covered, even when the wording differs. This is read-only, so the
assistant answers right away without a confirmation step.

You only ever get results **you are allowed to see**: the search checks your access to every
single hit before showing it, and it does not reveal that inaccessible content exists elsewhere.

## Asking for content

Example requests:

- "Is there anything on this platform about fire safety?"
- "Which of our courses deals with data protection? I don't remember the title."
- "Find the page that explains the enrolment procedure."
- "Gibt es hier Material zum Thema Brandschutz?"

> **You:** Is there anything about raccoons on the platform?
>
> **Assistant:** Yes — the course *Nature Basics* has a text section "Raccoons are great":
> [link]. Would you like more detail?

The assistant searches by meaning: asking about "GDPR" also finds content that talks about
"data protection" or "Datenschutz".

## Good to know

- **New content takes a moment to appear.** The search works on an index that is refreshed on a
  schedule (typically every 30 minutes). Content you created just now may not be findable yet —
  try again a little later.
- **Access decides visibility.** Content in a course you cannot access never appears in your
  results. If you expect a hit and don't get one, check that you can open the content the normal
  way first.
- **Not everything is searchable.** An administrator chooses which content types (courses,
  text-and-media areas, HTML blocks, files, …) are included — see below.

## For administrators: switching the search on

The feature needs three things, in this order:

1. **An embeddings-capable AI provider.** Configure the Wunderbyte provider with an embedding
   model, and set the agent's embeddings store to *database* (`embeddingsstore = db` in the
   agent settings). The [connect page](../../connect_claude.php) area of the plugin and the
   Moodle AI provider settings (*Site administration → General → AI → AI providers*) are the
   places to look.
2. **Enable content areas and scopes.** On the governance page
   (`/mod/booking/bookingextension/agent/sitesearch_governance.php`) you switch individual
   content areas on (e.g. course summaries, text-and-media areas, HTML blocks) and control the
   scope with rules — site-wide, per course category, or per course. Each area shows an effort
   estimate (document count, estimated chunks and a traffic-light) before you commit; file
   indexing is a separate per-area toggle.
3. **Let the index build.** The scheduled task *Rebuild site-content search index*
   (`\bookingextension_agent\task\rebuild_site_content_embeddings`, visible under
   *Site administration → Server → Tasks → Scheduled tasks*) picks up enabled areas on its next
   run and keeps the index current afterwards. To index immediately, run it manually:

   ```
   php admin/cli/scheduled_task.php --execute='\bookingextension_agent\task\rebuild_site_content_embeddings'
   ```

Disabling an area removes its indexed content on the next run. The index never stores who may
see what — access is checked live for every search, so role changes take effect immediately.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "Semantic site search is not enabled" | Embeddings store not set to *database*, or no embedding-capable provider configured (step 1). |
| Brand-new content is not found | The index task has not run since the content was created — wait for the next run or trigger it manually (step 3). |
| Content exists but never appears for a user | The user cannot access it the normal way either (course visibility, enrolment) — this is intentional. |
| An area shows no results at all | The area or its scope rule is disabled on the governance page (step 2). |

See also: [How it understands you](how-it-understands-you.md) ·
[Privacy](privacy.md) · [Troubleshooting](troubleshooting.md)
