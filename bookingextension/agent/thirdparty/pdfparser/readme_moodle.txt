Smalot PdfParser — bundled third-party library
==============================================

Library : smalot/pdfparser
Version : 2.12.5
Upstream: https://github.com/smalot/pdfparser
License : LGPL-3.0 (see LICENSE.txt) — compatible with Moodle's GPLv3+.
Declared: ../../thirdpartylibs.xml

Why it is bundled
-----------------
The agent extracts plain text from uploaded PDFs (see
classes/local/wizard/services/attachment/pdf_text_extractor.php). The fast path uses
the poppler-utils `pdftotext` binary when present. This pure-PHP library is the
self-contained fallback so the feature works on any Moodle server with no system
binary and without PHP exec() — which is required for a distributable plugin.

What is bundled (and what is NOT)
---------------------------------
- Bundled: src/Smalot/PdfParser/** only, plus LICENSE.txt and the upstream README
  (UPSTREAM_README.md).
- NOT bundled: the upstream composer dependency symfony/polyfill-mbstring. The library
  only calls global mb_*/iconv/zlib functions; Moodle already requires ext-mbstring,
  ext-iconv and ext-zlib, so the polyfill is a no-op here and is intentionally omitted.
  The src tree has no external `use` statements (only the Smalot namespace), so no
  Composer autoloader is needed.

How it is loaded
----------------
There is no Composer vendor/autoload.php. pdf_text_extractor.php registers a tiny
PSR-4 spl_autoload for the `Smalot\PdfParser\` prefix pointing at src/ here, lazily,
the first time PDF parsing is attempted. See pdf_text_extractor::ensure_pdfparser_autoloader().

How to upgrade
--------------
1. Download the desired release tarball from the upstream GitHub releases page.
2. Replace the src/ directory and LICENSE.txt with the new release's contents.
3. Confirm `grep -rE "^use " src | grep -v "^.*use Smalot"` still returns nothing
   (i.e. no new external dependency was introduced). If it does, either bundle that
   dependency too or pin to the last version without it.
4. Update the version number here and in ../../thirdpartylibs.xml.
5. Smoke-test by uploading a text PDF in the agent chat with the pdftotext binary
   absent, so the fallback path is exercised.
