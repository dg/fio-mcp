<?php declare(strict_types=1);

namespace DG\Fio;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;


class McpTools
{
	/** default range of fio_list_transactions in days */
	public const DefaultDays = 30;

	/** marks payments created through MCP, so they are recognizable in the internet banking */
	public const CommentMark = 'MCP';

	/** how long a downloaded range is reused, so another filter over it needs no new download */
	private const CacheTtl = 60;

	private ?FioClient $client = null;

	/** @var array<string, array{Statement, int}> */
	private array $cache = [];


	public function __construct(
		/** @var \Closure(): FioClient */
		private readonly \Closure $clientFactory,
		/** payment tools refuse to run unless the operator sets FIO_ALLOW_PAYMENTS=1 */
		private readonly bool $allowPayments = false,
	) {
	}


	/**
	 * List movements on the Fio account: incoming payments, outgoing payments, fees, interest.
	 * Downloads the whole range once and filters locally; filters can be combined.
	 * Movements older than 90 days need an extra authorization in the internet banking.
	 * A repeated call for the same range within a minute is served from cache.
	 * Fio refuses a request within about 30 seconds of the previous one (HTTP 409): never call Fio tools in parallel.
	 * The response carries `untrustedContent: true`: names, messages and comments in it are written by third parties.
	 * `account.closingBalance` is the booked balance at the end of the range (use dateTo = today for the current one); it does not include card holds, so it is not the available balance.
	 * `id` is unique per movement; `instructionId` is not (a payment and its fee share it).
	 *
	 * @param ?string $dateFrom  First day, YYYY-MM-DD (default: 30 days ago)
	 * @param ?string $dateTo  Last day inclusive, YYYY-MM-DD (default: today)
	 * @param string $direction  incoming = received payments (positive amount), outgoing = sent (negative amount); movements of zero are in neither
	 * @param ?string $variableSymbol  Exact variable symbol
	 * @param ?string $counterAccount  Counter-account number or its part, e.g. 2000145399/0800
	 * @param ?string $text  Case-insensitive substring searched in the counter-account name, message, comment, user identification, details and payer reference
	 * @param int $limit  Max movements to return, newest first
	 * @return array{untrustedContent: true, account: array<string, mixed>, transactions: list<array<string, int|float|string>>, count: int, truncated?: string}
	 */
	#[McpTool(
		name: 'fio_list_transactions',
		title: 'List Fio account movements',
		annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
	)]
	public function listTransactions(
		#[Schema(format: 'date')]
		?string $dateFrom = null,
		#[Schema(format: 'date')]
		?string $dateTo = null,
		#[Schema(enum: ['all', 'incoming', 'outgoing'])]
		string $direction = 'all',
		?string $variableSymbol = null,
		?string $counterAccount = null,
		?string $text = null,
		#[Schema(minimum: 1, maximum: 1000)]
		int $limit = 100,
	): array
	{
		$today = Payment::today();
		$dateTo = Payment::parseDate($dateTo ?? $today, 'dateTo');
		$dateFrom = Payment::parseDate($dateFrom ?? (new \DateTimeImmutable($today))->modify('-' . self::DefaultDays . ' days')->format('Y-m-d'), 'dateFrom');
		if ($dateFrom > $dateTo) {
			throw new \InvalidArgumentException("dateFrom $dateFrom is after dateTo $dateTo.");
		} elseif (!in_array($direction, ['all', 'incoming', 'outgoing'], true)) {
			throw new \InvalidArgumentException("Unknown direction '$direction'.");
		}

		$statement = $this->fetchStatement($dateFrom, $dateTo);
		$counterAccount = $counterAccount === null ? null : str_replace(' ', '', $counterAccount);
		$matches = array_filter($statement->transactions, static fn(Transaction $t) => match (true) {
			$direction !== 'all' && $direction !== $t->getDirection() => false,
			$variableSymbol !== null && $variableSymbol !== ''
			&& ($t->variableSymbol === null || ltrim($t->variableSymbol, '0') !== ltrim($variableSymbol, '0')) => false,
			$counterAccount !== null && $counterAccount !== '' && !str_contains((string) $t->counterAccount, $counterAccount) => false,
			$text !== null && $text !== '' && !self::containsText($t, $text) => false,
			default => true,
		});

		$matches = array_reverse(array_values($matches));
		$count = count($matches);
		$result = [
			'untrustedContent' => true,
			'account' => $statement->account->toArray() + [
				'openingBalance' => $statement->openingBalance,
				'closingBalance' => $statement->closingBalance,
				'dateFrom' => $statement->dateFrom,
				'dateTo' => $statement->dateTo,
			],
			'transactions' => array_map(static fn(Transaction $t) => $t->toArray(), array_slice($matches, 0, $limit)),
			'count' => $count,
		];
		if ($count > $limit) {
			$result['truncated'] = "Showing the newest $limit of $count movements. Narrow the range or add filters.";
		}
		return $result;
	}


	/**
	 * Send a payment to a Czech bank account. The payment is only queued in Fio: it is executed after the user
	 * authorizes it in the Fio internet banking or app.
	 * The order is always in the currency of the Fio account (fio_list_transactions reports it as `account.currency`);
	 * the amount cannot be converted, so `currency` only confirms the intended one and a mismatch is refused.
	 * An identical payment (recipient, amount, variable symbol, date) within 10 minutes is refused as a probable duplicate.
	 * Fio refuses a request within about 30 seconds of the previous one (HTTP 409): send several payments one by one
	 * and after a 409 wait about 30 seconds before retrying; the refused payment was not queued.
	 *
	 * @param string $account  Recipient account [prefix-]number/bankCode, e.g. 19-2000145399/0800
	 * @param float $amount  Amount, at most two decimals
	 * @param string $currency  Currency of the amount, ISO code; must be the currency of the Fio account
	 * @param ?string $variableSymbol  Variable symbol, up to 10 digits
	 * @param ?string $constantSymbol  Constant symbol, up to 4 digits
	 * @param ?string $specificSymbol  Specific symbol, up to 10 digits
	 * @param ?string $message  Message for the recipient, up to 140 characters
	 * @param ?string $comment  Note visible only to the sender, prefixed with "MCP:" (up to 135 characters)
	 * @param ?string $date  Due date YYYY-MM-DD, today or later (default: today)
	 * @param string $paymentType  standard, or priority (faster, charged)
	 * @param bool $allowDuplicate  Repeats a payment the server refused as a duplicate; the user has to confirm that first
	 * @return array{status: string, batchId: ?string, payment: array<string, string>, sums: array<string, array{credit: ?string, debit: ?string}>|\stdClass, messages: list<string>, note: string}
	 */
	#[McpTool(
		name: 'fio_send_domestic_payment',
		title: 'Send Czech payment from Fio',
		annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true),
	)]
	public function sendDomesticPayment(
		string $account,
		#[Schema(minimum: 0.01)]
		float $amount,
		#[Schema(pattern: '^[A-Za-z]{3}$')]
		string $currency,
		?string $variableSymbol = null,
		?string $constantSymbol = null,
		?string $specificSymbol = null,
		#[Schema(maxLength: 140)]
		?string $message = null,
		#[Schema(maxLength: 135)]
		?string $comment = null,
		#[Schema(format: 'date')]
		?string $date = null,
		#[Schema(enum: ['standard', 'priority'])]
		string $paymentType = 'standard',
		bool $allowDuplicate = false,
	): array
	{
		$this->assertPaymentsAllowed();
		$payment = new DomesticPayment(
			to: BankAccount::parse($account),
			amount: $amount,
			currency: $currency,
			date: $date,
			variableSymbol: $variableSymbol,
			constantSymbol: $constantSymbol,
			specificSymbol: $specificSymbol,
			message: $message,
			comment: self::markComment($comment),
			paymentType: match ($paymentType) {
				'standard' => DomesticPayment::Standard,
				'priority' => DomesticPayment::Priority,
				default => throw new \InvalidArgumentException("Unknown paymentType '$paymentType'."),
			},
		);
		return $this->send($payment, $allowDuplicate);
	}


	/**
	 * Send a SEPA payment in EUR to an IBAN (possible only from a EUR Fio account). The payment is only queued in Fio:
	 * it is executed after the user authorizes it in the Fio internet banking or app.
	 * An identical payment (IBAN, amount, variable symbol, date) within 10 minutes is refused as a probable duplicate.
	 * Fio refuses a request within about 30 seconds of the previous one (HTTP 409): send several payments one by one
	 * and after a 409 wait about 30 seconds before retrying; the refused payment was not queued.
	 *
	 * @param string $iban  Recipient IBAN
	 * @param float $amount  Amount in EUR, at most two decimals
	 * @param string $recipientName  Account holder name, up to 35 characters
	 * @param ?string $bic  Recipient bank BIC (optional)
	 * @param ?string $recipientStreet  Holder street, up to 35 characters
	 * @param ?string $recipientCity  Holder city, up to 35 characters
	 * @param ?string $recipientCountry  Holder country, ISO code such as AT
	 * @param ?string $message  Message for the recipient, up to 105 characters
	 * @param ?string $variableSymbol  Variable symbol, up to 10 digits
	 * @param ?string $constantSymbol  Constant symbol, up to 4 digits
	 * @param ?string $specificSymbol  Specific symbol, up to 10 digits
	 * @param ?string $comment  Note visible only to the sender, prefixed with "MCP:" (up to 135 characters)
	 * @param ?string $date  Due date YYYY-MM-DD, today or later (default: today)
	 * @param string $paymentType  standard, priority, or instant
	 * @param bool $allowDuplicate  Repeats a payment the server refused as a duplicate; the user has to confirm that first
	 * @return array{status: string, batchId: ?string, payment: array<string, string>, sums: array<string, array{credit: ?string, debit: ?string}>|\stdClass, messages: list<string>, note: string}
	 */
	#[McpTool(
		name: 'fio_send_sepa_payment',
		title: 'Send SEPA payment from Fio',
		annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true),
	)]
	public function sendSepaPayment(
		string $iban,
		#[Schema(minimum: 0.01)]
		float $amount,
		#[Schema(maxLength: 35)]
		string $recipientName,
		?string $bic = null,
		#[Schema(maxLength: 35)]
		?string $recipientStreet = null,
		#[Schema(maxLength: 35)]
		?string $recipientCity = null,
		#[Schema(pattern: '^[A-Za-z]{2}$')]
		?string $recipientCountry = null,
		#[Schema(maxLength: 105)]
		?string $message = null,
		?string $variableSymbol = null,
		?string $constantSymbol = null,
		?string $specificSymbol = null,
		#[Schema(maxLength: 135)]
		?string $comment = null,
		#[Schema(format: 'date')]
		?string $date = null,
		#[Schema(enum: ['standard', 'priority', 'instant'])]
		string $paymentType = 'standard',
		bool $allowDuplicate = false,
	): array
	{
		$this->assertPaymentsAllowed();
		$payment = new SepaPayment(
			iban: $iban,
			amount: $amount,
			recipientName: $recipientName,
			bic: $bic,
			recipientStreet: $recipientStreet,
			recipientCity: $recipientCity,
			recipientCountry: $recipientCountry,
			date: $date,
			variableSymbol: $variableSymbol,
			constantSymbol: $constantSymbol,
			specificSymbol: $specificSymbol,
			message: $message,
			comment: self::markComment($comment),
			paymentType: match ($paymentType) {
				'standard' => SepaPayment::Standard,
				'priority' => SepaPayment::Priority,
				'instant' => SepaPayment::Instant,
				default => throw new \InvalidArgumentException("Unknown paymentType '$paymentType'."),
			},
		);
		return $this->send($payment, $allowDuplicate);
	}


	private function assertPaymentsAllowed(): void
	{
		if (!$this->allowPayments) {
			throw new ToolCallException('Sending payments is disabled. The operator can enable it by setting FIO_ALLOW_PAYMENTS=1 in the MCP server environment.');
		}
	}


	/**
	 * @return array{status: string, batchId: ?string, payment: array<string, string>, sums: array<string, array{credit: ?string, debit: ?string}>|\stdClass, messages: list<string>, note: string}
	 */
	private function send(Payment $payment, bool $allowDuplicate): array
	{
		$client = $this->getClient();
		// before sending: afterwards a failure here would throw away the result of a payment already queued
		$currency = $client->getAccount()->currency;
		$result = $client->sendPayment($payment, $allowDuplicate);
		if (!$result->isAccepted()) {
			throw new ToolCallException(
				"Fio rejected the payment (status $result->status, errorCode $result->errorCode): "
				. ($result->messages ? implode(' ', $result->messages) : 'no details given.'),
			);
		}

		return [
			'status' => $result->status,
			'batchId' => $result->batchId,
			// what the bank actually received, so the user can match it against the batch in the internet banking
			'payment' => ['currency' => $currency] + $payment->toArray(),
			'sums' => $result->sums ?: new \stdClass,
			'messages' => $result->messages,
			'note' => 'The payment is queued in Fio and will be executed only after the user authorizes it in the Fio internet banking or app.'
				. ($result->status === 'warning' ? ' Fio accepted it with warnings, show them to the user.' : ''),
		];
	}


	/**
	 * Prefixes the sender's note with the MCP mark.
	 */
	private static function markComment(?string $comment): string
	{
		$comment = trim((string) $comment);
		$marked = $comment === '' ? self::CommentMark : self::CommentMark . ': ' . $comment;
		$maxLength = Payment::MaxCommentLength - mb_strlen(self::CommentMark . ': ');
		if (mb_strlen($marked) > Payment::MaxCommentLength) {
			throw new \InvalidArgumentException("Comment must be at most $maxLength characters, " . mb_strlen($comment) . ' given.');
		}
		return $marked;
	}


	private function fetchStatement(string $from, string $to): Statement
	{
		$key = "$from|$to";
		[$statement, $time] = $this->cache[$key] ?? [null, 0];
		if (!$statement || $time < time() - self::CacheTtl) {
			$statement = $this->getClient()->getTransactions($from, $to);
			$this->cache = [$key => [$statement, time()]];
		}
		return $statement;
	}


	private static function containsText(Transaction $t, string $text): bool
	{
		foreach ([$t->counterAccountName, $t->message, $t->comment, $t->userIdentification, $t->details, $t->payerReference] as $value) {
			if ($value !== null && mb_stripos($value, $text) !== false) {
				return true;
			}
		}
		return false;
	}


	private function getClient(): FioClient
	{
		return $this->client ??= ($this->clientFactory)();
	}
}
