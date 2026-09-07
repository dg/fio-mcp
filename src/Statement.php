<?php declare(strict_types=1);

namespace DG\Fio;


/**
 * Parsed JSON export for a date range: account header, balances and movements.
 */
final class Statement
{
	public function __construct(
		public readonly Account $account,
		public readonly ?float $openingBalance,
		public readonly ?float $closingBalance,
		public readonly string $dateFrom,
		public readonly string $dateTo,
		/** @var list<Transaction> */
		public readonly array $transactions,
	) {
	}


	/**
	 * @throws \JsonException
	 * @throws \UnexpectedValueException
	 */
	public static function fromJson(string $json): self
	{
		$data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
		$statement = $data['accountStatement'] ?? throw new \UnexpectedValueException('Fio export is missing accountStatement.');
		$info = $statement['info'] ?? [];
		if (!isset($info['accountId'], $info['bankId'], $info['currency'], $info['dateStart'], $info['dateEnd'])) {
			throw new \UnexpectedValueException('Fio export is missing the account header.');
		}

		$transactions = [];
		foreach ($statement['transactionList']['transaction'] ?? [] as $row) {
			if (!is_array($row)) {
				throw new \UnexpectedValueException('Fio export contains a movement that is not an object.');
			}
			$transactions[] = Transaction::fromJson($row);
		}

		return new self(
			account: new Account(
				number: (string) $info['accountId'],
				bankCode: (string) $info['bankId'],
				currency: (string) $info['currency'],
				iban: $info['iban'] ?? $info['IBAN'] ?? null,
				bic: $info['bic'] ?? $info['BIC'] ?? null,
			),
			openingBalance: isset($info['openingBalance']) ? (float) $info['openingBalance'] : null,
			closingBalance: isset($info['closingBalance']) ? (float) $info['closingBalance'] : null,
			dateFrom: Transaction::parseDate($info['dateStart']),
			dateTo: Transaction::parseDate($info['dateEnd']),
			transactions: $transactions,
		);
	}
}
