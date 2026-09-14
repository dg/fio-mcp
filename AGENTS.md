# To My Agents!

It is my fervent wish that this file guide every AI coding agent working with code in this repository.

It describes an MCP server for the Fio banka API (`https://fioapi.fio.cz/v1/rest/`): reading account movements and creating payments, which the user then authorizes in the internet banking.

## Commands

- `vendor/bin/tester tests -s` – tests (no real HTTP calls)
- `vendor/bin/phpstan analyse` – static analysis, level 8, over `src` and `server.php`
- both run in CI (`.github/workflows/tests.yml`) on PHP 8.2 to 8.5
- after adding a class, run `composer dump-autoload` (classmap autoload)

## Architecture

- `server.php` – entry point: env `FIO_TOKEN` and `FIO_ALLOW_PAYMENTS`, builds the `mcp/sdk` server, instructions for the model, stdio transport. A missing token does not prevent startup; it is reported by the first tool call.
- `src/McpTools.php` – thin adapter with `#[McpTool]`: `fio_list_transactions`, `fio_send_domestic_payment`, `fio_send_sepa_payment`. Gets the client as a factory (closure); payment tools check the gate before calling it. Keeps a short cache of the last downloaded range.
- `src/McpToolCallGuard.php` – converts exceptions thrown by tools into `ToolCallException` (a result with `isError`).
- `src/FioClient.php` – HTTP client: `getTransactions()`, `getAccount()`, `sendPayment()`. The only place holding the token. The HTTP layer is an injectable closure (tests).
- `src/StateStore.php` – JSON file in the temp directory shared by all processes using the same token, accessed under `flock`. Holds account info and fingerprints of sent payments.
- `src/Statement.php`, `src/Transaction.php`, `src/Account.php` – parsing of the JSON export.
- `src/Payment.php`, `src/DomesticPayment.php`, `src/SepaPayment.php` – orders with validation in the constructor and Fio XML generation.
- `src/ImportResult.php` – response to an import.
- `src/BankAccount.php` – Czech account number (mod 11), IBAN (mod 97), BIC.
- `src/exceptions.php` – `FioException` (message for the user), `TransportException` (request did not complete, state unknown) and `HttpException` (error status; `isRefused()` tells whether Fio refused the request before processing it).

## Deliberate decisions

- **The "last download" cursor is not used.** The code must not contain `last`, `set-last-id` or `set-last-date`: they move the server-side marker and could make another program using the same token miss movements. Only `periods` is read.
- **No rate limiting on our side, no retry after HTTP 409.** A client that waited 30 s before every request held the state file lock for the whole wait and made the MCP calls hang, so the pacing is left to the agent: the server instructions and tool descriptions tell it not to call the tools in parallel and to wait about 30 s after a 409. A 409 means refused before processing, so a retry creates no duplicate.
- **Duplicate payment protection**: the payment fingerprint (type, recipient, amount, VS, date) is stored **before** sending. It stays whenever the outcome is unknown: a transport failure, an unreadable response, and any status that is not in `HttpException::isRefused()` (a 502 or 504 from a gateway can arrive after Fio queued the batch). It is removed only when Fio demonstrably refused the order. HTTP 500 counts as refused, because per §8 it means a bad token or a malformed request, never a half-processed upload.
- **Account info is cached permanently** on every export, because it never changes for a token. A payment therefore usually needs no extra export. The cache decides `accountFrom` and the order currency, so it is sealed with an HMAC of the token: a planted state file on a shared temp directory cannot redirect a payment.
- **The order currency is the account currency**, the bank converts nothing. The `currency` parameter of `fio_send_domestic_payment` is a confirmation: a mismatch is refused before upload, because a wrong currency would otherwise come back only as an accepted `warning`.
- **The `MCP` mark** in `comment` ("Vaše označení", the sender's note) is added by `McpTools`, not by the payment classes, which stay generic.
- `untrustedContent: true` in read output and the SECURITY block in the instructions: movement texts are written by third parties.

## Fio API traps

- **There is no sandbox.** Testing happens on a real account; automated tests therefore use a fake HTTP layer.
- **Limit of 1 request per 30 s per token** (HTTP 409) is enforced only sometimes: isolated requests a few seconds apart usually pass, two payment imports sent at the same moment got a 409 (verified on a real account).
- **Movements older than 90 days** need an unlock in the internet banking (Settings > API, valid 10 min), otherwise HTTP 422.
- **HTTP 500 = invalid or inactive token**, not a server error. 404 = malformed URL parameters.
- **JSON export**: dates are epoch milliseconds (local midnight, converted in `Europe/Prague`); the newer API also returns a string such as `2024-01-15+0100`; both forms are parsed. A missing column is an explicit `null`. Columns are not numbered sequentially (22 = movement ID, 17 = instruction ID…); the mapping lives in `Transaction::fromJson()`.
- **Movement ID is unique, instruction ID is not** (a payment and its fee share the instruction ID).
- **Symbols are strings**: KS/VS may have leading zeros.
- **Import is `multipart/form-data`** with the file in the `file` field (`CURLStringFile`); a bare POST body ends with an "internal error".
- **Element order in the XML is defined by the XSD** (`importIB.xsd` + `fio_xml_type.xsd`, a sequence), not by the order of the tables in the documentation. `Payment::writeElements()` writes in array order.
- **Documentation and XSD disagree**: per the XSD `comment` has at most 140 characters (the documentation says 255 for domestic payments); `benefName` and the address are 50 in the XSD, 35 in the documentation. The code keeps the stricter values.
- **`accountFrom` is only the number, without prefix and bank code** (`account_fio_type`, 1–10 digits); it is taken from `info.accountId` of the export.
- **Order currency = account currency** (for T2Transaction the documentation explicitly says "account currency"). SEPA is therefore sent only from a EUR account; a domestic payment from a non-CZK account only to Fio accounts (bank code 2010). The bank would report a mismatch only as an easily overlooked `warning`.
- **Import response** (`responseImportIB.xsd`): `result/errorCode`, `result/idInstruction` (batch number), `result/status`, `result/message`, `result/sums/sum[@id=currency]/sumCredit|sumDebet`, details in `ordersDetails/detail/messages/message[@status,@errorCode]`. Status `warning` = accepted.
- **Account examples in the Fio documentation fail the checksum** (`2400222222/2010`, `CZ7920100000002400222222`); do not use them in validation tests.
- **`remittanceInfo` is `xs:token`**, so Fio trims spaces at the line edges; the message must be split on spaces, never mid-word.
- **`country_type` is a fixed enumeration**, copied into `SepaPayment::Countries`. A code outside it (e.g. `EL`) makes Fio reject the whole file.
- **`column3` of the export is not always a bank code** (foreign movements carry a BIC), so it is appended to the counter-account only when it is 4 digits.
- **The SDK validates tool input against the schema before the tool runs**, and reports a failure as a JSON-RPC error (-32602), not as a result with `isError`. Checks in tool bodies therefore only guard direct PHP calls.
- **Anthropic Directory does not list connectors that transfer money**, so this server cannot be submitted as is; only a read-only edition without the payment tools could be.

## Tests

- `tests/Statement.phpt`, `tests/Payment.phpt`, `tests/ImportResult.phpt`, `tests/BankAccount.phpt` – pure units, export fixture in `tests/fixtures/`.
- `tests/FioClient.phpt` – through a fake HTTP layer: error mapping, token masking, multipart, duplicates, the sealed account cache.
- `tests/McpToolCallGuard.phpt` – all four conversion branches.
- `tests/McpTools.phpt` – discovery (names, titles, annotations, schemas), the gate and filters. **Update it after adding a tool.**
- Smoke test over stdio:

```bash
printf '%s\n%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}' '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' | FIO_TOKEN= php server.php
```
