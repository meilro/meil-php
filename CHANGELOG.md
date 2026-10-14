# Changelog

## 0.1.0 (2026-10-14)

First release.

- A method for each of the API's 27 operations: emails, audiences, contacts, broadcasts, templates and the content check.
- Answers come back as associative arrays, with PHPDoc array shapes for editors and static analysis.
- Errors are thrown as `Meil\Exception\MeilException` subclasses, with `getErrorName()`, `getStatusCode()`, `getMessage()` and `getId()`.
- Retries for rate limits, network errors and 502/503/504/520–524. `emails->send()` and `broadcasts->create()` always send an Idempotency-Key.
- A 202 `under_review` answer comes back as a result with `'status' => 'under_review'`, not as an exception.
- `emails->iterate()` and `audiences->iterateContacts()` page automatically, as generators.
- PHP 8.1+, needing only ext-curl and ext-json, so it runs on shared hosting and in WordPress.
