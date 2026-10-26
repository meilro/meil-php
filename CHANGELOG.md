# Changelog

## 0.4.0 (2026-10-26)

New:

- `$meil->broadcasts->test()`: a test to your test audience (made on the Audiences page of the dashboard). Each of its contacts gets a copy filled in with their own data, with "[Test]" before the subject. Pass a broadcast id to test that broadcast, which stays as it is, or its content (`from`, `subject`, `html` or `template_id`, `variables`…) to test it without saving anything. It answers with the addresses it went to. Without a test audience that has contacts, it throws a `ValidationException` named `test_audience_missing`.

## 0.3.0 (2026-10-25)

New:

- Your own confirmation page: `contacts->add()` takes `confirm_page` (a page on the audience's domain, like `https://example.com/confirm`), so the confirmation email's button opens your page instead of meil.ro's, with `?token=…` added to the link.
- `contacts->getConfirmation($token)`: where that sign-up stands (`pending`, `confirmed` or `expired`), with the address and the audiences by name. Changes nothing.
- `contacts->confirm($token, $ip)`: confirms it when the person presses your button, storing the visitor's IP as proof. Harmless when pressed twice (`already_confirmed`); an expired link comes back as `'status' => 'expired'`, not as an exception.

Changed:

- With `'confirm' => true`, someone who already confirmed is added without a new email only to lists on the same domain (its subdomains included). Another domain of your account sends its own confirmation email.

## 0.2.0 (2026-10-21)

Breaking:

- `contacts->add()` takes `audience_ids` (a list, also for one audience) instead of `audience_id`, and answers with `audiences`: one entry per audience, each with its own `subscribed`.

New:

- `contacts->add()` with `'confirm' => true`: the person is subscribed only after confirming from an email. Optional `confirmed_url`, `language`, `from` and `reply_to`.
- `contacts->update()` with `audiences` (`[audienceId => true|false]`), for a preference page on your own site.
- Every audience of a contact shows `pending_confirmation`, `subscribed_at` and `unsubscribed_at`. Listed contacts show `pending_confirmation`.
- `preview_text` on emails, broadcasts and templates, and the `no_preview_text` content check.
- Templates have a `domain`: set it when creating or changing one (optional, the key's domain by default). A key sees only its own domains' templates.
- `domain` is optional when creating an audience: the key's domain by default.

## 0.1.0 (2026-10-14)

First release.

- A method for each of the API's 27 operations: emails, audiences, contacts, broadcasts, templates and the content check.
- Answers come back as associative arrays, with PHPDoc array shapes for editors and static analysis.
- Errors are thrown as `Meil\Exception\MeilException` subclasses, with `getErrorName()`, `getStatusCode()`, `getMessage()` and `getId()`.
- Retries for rate limits, network errors and 502/503/504/520–524. `emails->send()` and `broadcasts->create()` always send an Idempotency-Key.
- A 202 `under_review` answer comes back as a result with `'status' => 'under_review'`, not as an exception.
- `emails->iterate()` and `audiences->iterateContacts()` page automatically, as generators.
- PHP 8.1+, needing only ext-curl and ext-json, so it runs on shared hosting and in WordPress.
