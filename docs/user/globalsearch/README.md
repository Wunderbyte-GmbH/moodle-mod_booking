[Back to user documentation](../README.md)

# Global search

mod_booking feeds Moodle's global search (`core_search`), so booking instances, booking options
and their subbookings can be found from the search box of the site — not only inside the booking
tables.

## Search areas

Three search areas are shipped. Each of them is switched on and off individually under
*Site administration → Plugins → Search → Manage global search → Search areas*:

| Area | What it finds |
|------|---------------|
| Booking instances | Name and introduction of the booking activity |
| Booking options | One result per booking option, see the content list below |
| Subbookings | Subbookings of a booking option; the result links to the option |

Global search has to be enabled site wide (*Advanced features → Enable global search*) and an
engine has to be configured. File contents of attachments are only indexed by engines which
support file indexing (Solr, Elasticsearch); the `simpledb` engine ignores them.

## What is indexed for a booking option

The document of a booking option is assembled from the option itself and from related data.
Which sources are used is configured in the booking settings under **Global search**
(*Site administration → Plugins → Activity modules → Booking*):

* Description
* Location, institution and address
* Day of the week and time
* Identifier
* Internal annotation — **off by default**, because it is an internal field
* Custom fields (with the display values of select fields, not the stored keys)
* Teachers
* Entities (only when `local_entities` is installed)
* Name of the connected Moodle course
* Competencies
* Name of the certificate template
* Attached files

Two more settings fine tune this:

* **Custom fields to be indexed** — restricts indexing to the selected custom fields. Without a
  selection all custom fields are indexed. Custom fields which are not visible to everybody
  (teacher only or hidden fields) are *never* indexed, because one document is shown to every
  user who may see the booking option.
* **Invisible booking options** — either index them and show them only to users with
  `mod/booking:canseeinvisibleoptions` (default), or keep them out of the index entirely.

Changing one of these settings asks core search to index the booking areas again; the next run of
the scheduled search task rebuilds the affected documents.

## Who sees which result

A result is shown when the user may see the course module and, for invisible options, holds
`mod/booking:canseeinvisibleoptions`. The availability conditions of mod_booking (`bo_cond_*`)
do **not** hide results: they decide whether somebody may book an option, not whether they may
see it.

Personal data is never indexed: booking answers, issued certificates and the teacher journal stay
out of the index.

Global search only delivers results to logged in users. Publicly visible catalogue pages (for
example shortcode pages for guests) are a different mechanism and are not affected by these
settings.

## Keeping the index up to date

Core search indexes incrementally by the modification date of a booking option. Changes to
related data do not touch that date on their own, so mod_booking bumps it when

* a teacher is added to or removed from an option or from one of its dates,
* the name of the connected Moodle course changes.

When the definition of a custom field changes (for example a renamed select option), every option
document would change at once. In that case mod_booking asks core search to reindex its areas
instead of rewriting every single option.

Renaming an **entity** in `local_entities` does not notify mod_booking, since that plugin fires no
event. After a bulk rename of entities, trigger a reindex of the booking areas on the search areas
page.
