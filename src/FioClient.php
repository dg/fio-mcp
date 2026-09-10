<?php declare(strict_types=1);

namespace DG\Fio;


/**
 * Client for the Fio banka API. It reads movements for a date range and imports payment orders;
 * it never touches the server-side "last download" cursor.
 */
final class FioClient
{
	/** how long an identical payment is refused as a probable duplicate */
	public const DuplicateWindow = 600;
	private const BaseUrl = 'https://fioapi.fio.cz/v1/rest/';
	private const UnknownStateHint = 'It is unknown whether Fio received the payment: check the internet banking for a batch waiting for authorization before sending it again.';

	/** @var \Closure(string, string, array<string, mixed>): array{int, string} */
	private readonly \Closure $http;
	private readonly StateStore $store;


	/**
	 * @param  ?StateStore  $store  shared state, by default a file in the temp directory derived from the token
	 * @param  ?\Closure(string $method, string $url, array<string, mixed> $fields): array{int, string}  $http
	 *   returns [status, body]; throws \RuntimeException when the request does not complete
	 */
	public function __construct(
		#[\SensitiveParameter]
		private readonly string $token,
		?StateStore $store = null,
		?\Closure $http = null,
	) {
		$this->store = $store ?? StateStore::forToken($token);
		$this->http = $http ?? self::curlRequest(...);
	}


	/**
	 * Movements between two dates, inclusive. Data older than 90 days needs extra authorization in the internet banking.
	 * @throws FioException
	 */
	public function getTransactions(string $from, string $to): Statement
	{
		$body = $this->request('GET', "periods/{token}/$from/$to/transactions.json");
		try {
			$statement = Statement::fromJson($body);
		} catch (\JsonException|\UnexpectedValueException $e) {
			throw new FioException('Fio returned an unreadable export: ' . $e->getMessage(), 0, $e);
		}
		$account = $statement->account->toArray();
		$seal = $this->seal($account);
		$this->store->update(static function (array &$state) use ($account, $seal): void {
			$state['account'] = $account;
			$state['accountSeal'] = $seal;
		});
		return $statement;
	}


	/**
	 * Account the token belongs to, cached permanently; only the very first call downloads it.
	 * The cache decides accountFrom and the currency of every payment, so it is sealed with the
	 * token: on a shared temp directory nobody else can plant an account there.
	 * @throws FioException
	 */
	public function getAccount(): Account
	{
		$cached = $this->store->update(static fn(array &$state) => [$state['account'] ?? null, $state['accountSeal'] ?? null]);
		if (is_array($cached[0]) && is_string($cached[1]) && hash_equals($this->seal($cached[0]), $cached[1])) {
			return Account::fromArray($cached[0]);
		}
		$today = Payment::today();
		return $this->getTransactions($today, $today)->account;
	}


	/**
	 * Uploads a single payment order. It is executed only after authorization in the internet banking.
	 * An identical payment within DuplicateWindow is refused unless $allowDuplicate is set.
	 * @throws FioException
	 * @throws \InvalidArgumentException
	 */
	public function sendPayment(Payment $payment, bool $allowDuplicate = false): ImportResult
	{
		$account = $this->getAccount();
		$xml = $payment->toXml($account);
		$fingerprint = $payment->getFingerprint();
		$this->reserveFingerprint($fingerprint, $allowDuplicate);

		try {
			$body = $this->request('POST', 'import/', [
				'type' => 'xml',
				'lng' => 'en',
				'file' => new \CURLStringFile($xml, 'payment.xml', 'application/xml'),
			]);
		} catch (HttpException $e) {
			// a gateway error (502, 504, ...) can arrive after Fio already queued the batch, so keep the
			// fingerprint; only a status that means "refused before processing" clears it
			if (!$e->isRefused()) {
				throw new TransportException($e->getMessage() . ' ' . self::UnknownStateHint, 0, $e);
			}
			$this->releaseFingerprint($fingerprint);
			throw $e;

		} catch (TransportException $e) {
			throw new TransportException($e->getMessage() . ' ' . self::UnknownStateHint, 0, $e);
		}

		try {
			$result = ImportResult::fromXml($body);
		} catch (\UnexpectedValueException $e) {
			throw new FioException($this->mask($e->getMessage()) . ' ' . self::UnknownStateHint, 0, $e);
		}

		if (!$result->isAccepted()) {
			$this->releaseFingerprint($fingerprint);
		}
		return $result;
	}


	/**
	 * @param  array<string, mixed>  $fields
	 * @throws FioException
	 */
	private function request(
		string $method,
		string $path,
		#[\SensitiveParameter]
		array $fields = [],
	): string
	{
		$url = self::BaseUrl . str_replace('{token}', $this->token, $path);
		if ($method === 'POST') {
			$fields = ['token' => $this->token] + $fields;
		}

		try {
			[$status, $body] = ($this->http)($method, $url, $fields);
		} catch (\RuntimeException $e) {
			throw new TransportException('Request to Fio failed: ' . $this->mask($e->getMessage()), 0);
		}

		return match ($status) {
			200 => $body,
			404 => throw new HttpException($status, 'Fio rejected the request parameters (HTTP 404).'),
			409 => throw new HttpException($status, 'Fio refused the request as too frequent, it allows one request per token every 30 seconds (HTTP 409). Nothing was processed, retry in 30 seconds.'),
			413 => throw new HttpException($status, 'The date range contains more than 50,000 movements (HTTP 413). Narrow the range.'),
			422 => throw new HttpException($status, 'Fio refuses movements older than 90 days without extra authorization (HTTP 422). Either narrow dateFrom to the last 90 days, or unlock the history in the internet banking (Settings > API, lock icon at the token); the unlock lasts 10 minutes.'),
			500 => throw new HttpException($status, 'Fio rejected the token (HTTP 500): it does not exist, is inactive, expired (tokens last at most 180 days), or lacks the right to submit payment orders. Check FIO_TOKEN.'),
			default => throw new HttpException($status, "Fio returned HTTP $status: " . $this->mask(mb_substr(trim(strip_tags($body)), 0, 300))),
		};
	}


	/**
	 * @throws FioException
	 */
	private function reserveFingerprint(string $fingerprint, bool $allowDuplicate): void
	{
		$now = time();
		$sentAt = $this->store->update(static function (array &$state) use ($fingerprint, $allowDuplicate, $now): ?int {
			$payments = array_filter(
				(array) ($state['payments'] ?? []),
				static fn($time) => $time > $now - self::DuplicateWindow,
			);
			$previous = $payments[$fingerprint] ?? null;
			if ($previous === null || $allowDuplicate) {
				$payments[$fingerprint] = $now;
			}
			$state['payments'] = $payments;
			return $allowDuplicate ? null : $previous;
		});

		if ($sentAt !== null) {
			throw new FioException(sprintf(
				'An identical payment (same recipient, amount, variable symbol and date) was already sent %d seconds ago'
				. ' and is waiting in the internet banking. Ask the user whether it really should be sent a second time.',
				$now - $sentAt,
			));
		}
	}


	private function releaseFingerprint(string $fingerprint): void
	{
		$this->store->update(static function (array &$state) use ($fingerprint): void {
			unset($state['payments'][$fingerprint]);
		});
	}


	private function mask(string $s): string
	{
		return str_replace($this->token, '***', $s);
	}


	/**
	 * @param  array<string, mixed>  $account
	 */
	private function seal(array $account): string
	{
		return hash_hmac('sha256', json_encode($account, JSON_THROW_ON_ERROR), $this->token);
	}


	/**
	 * @param  array<string, mixed>  $fields
	 * @return array{int, string}
	 */
	private static function curlRequest(
		string $method,
		#[\SensitiveParameter]
		string $url,
		#[\SensitiveParameter]
		array $fields,
	): array
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_USERAGENT => 'dg/fio-mcp',
		]);
		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
		}

		$body = curl_exec($ch);
		if (!is_string($body)) {
			$error = curl_error($ch);
			if (in_array(curl_errno($ch), [CURLE_SSL_CACERT, CURLE_SSL_CACERT_BADFILE], true)) {
				$error .= ' (PHP does not trust the certificate authority of fioapi.fio.cz; configure curl.cainfo in php.ini with a current CA bundle.)';
			}
			throw new \RuntimeException($error);
		}
		return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
	}
}
