# Contributing

Thanks for helping improve `kirora/kirora-php`.

## Questions and support

For help with your integration, email **support@kirora.io**. Issues are for bugs and
feature requests only.

## Security issues

Don't open a public issue. See [SECURITY.md](SECURITY.md).

## Reporting bugs

[Open an issue](../../issues/new/choose) using the bug report form. A minimal code sample
that reproduces the problem is the most useful thing you can include.

## Requesting features

Open an issue using the feature request form. Describe the problem you're trying to solve,
not just the solution. We read every request, but can't promise to build them all.

## Pull requests

Small fixes (bugs, docs, typos) are welcome as pull requests directly. For new features or
changes to the public API, please open an issue first so we can agree on the approach.
Kirora's SDKs share the same API across platforms, so these changes need to be designed
together.

Before submitting:

- Add or update tests for your change, in `tests/`. `tests/ContractTest.php` covers every
  service method; `tests/Pest.php` holds the fake API the tests share.
- Run `composer test`, `composer analyse`, `composer format:check` and
  `composer codegen:check`.
- Update the README if you changed public behaviour, and add a line to `CHANGELOG.md`.
- Don't edit `src/Models/`, `src/Enums/ErrorCode.php`, `src/Enums/WebhookEvent.php` or
  `src/Operations.php` by hand. They're generated with `composer codegen` from the API spec
  that `spec/source.json` pins, and CI fails if they're out of date. Maintainers move the
  pin when the API changes, so pull requests shouldn't change it.
- When you release, bump `Kirora::VERSION` in `src/Kirora.php` and add its section to
  `CHANGELOG.md`. CI fails if a release tag doesn't match `Kirora::VERSION`.
- When you add a service method, follow [Adding a method](#adding-a-method).

## Development

The tests need PHP 8.2 or later; the SDK itself supports 8.1.

```sh
composer install
composer test
composer analyse        # PHPStan
composer format:check   # php-cs-fixer; `composer format` fixes
composer codegen:check
```

### Updating the API spec

The models, error codes, webhook event names and retry table are generated from Kirora's
OpenAPI spec, published at [kiroratech/openapi](https://github.com/kiroratech/openapi).
`spec/source.json` pins the commit to generate from, with its `version` and `sha256`. To
move to a newer spec, update all three and run `composer codegen`. Kirora's Node and Go
SDKs pin the spec the same way, so keep them on the same commit where you can.

It downloads the spec and refuses to run if it doesn't match `spec/source.json`; the error
prints the values to use. The download is cached in `.cache/openapi`, so later runs work
offline. To try a spec that isn't published yet, pass its path:
`composer codegen -- path/to/openapi.json`. That skips the checksum and version checks, so
don't commit what it generates until the spec is published and pinned.

### Adding a method

- Name it after the `operationId` without the resource: `listContacts` is
  `contacts->list()`. An operation on something that belongs to a contact goes on a
  sub-service under `src/Service/Contacts/`, reached as a property, as resend-php does:
  `setContactTopic` is `contacts->topics->set()`. A bulk operation ends in
  `Batch`: `identifyContactsBatch` is `contacts->identifyBatch()`. A list has `list()` for
  one page and `all()` for every item.
- Take path ids first, then the body, then the query (if any), then the call options, as
  the Node SDK does.
- Return the generated model for its `data`. A schema whose name PHP reserves gets `Model`
  added, so `List` is `ListModel`.
- How it retries comes from the operation's `x-retry` in the spec, through
  `src/Operations.php`, so a method needs nothing of its own for it.
- Add the `operationId` to `OPERATIONS` in `scripts/codegen.php`, run `composer codegen`,
  and add the method to `tests/ContractTest.php`, which fails until every operation there
  is covered.

By contributing, you agree that your contributions are licensed under the
[MIT License](LICENSE).
