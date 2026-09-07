<?php declare(strict_types=1);

namespace DG\Fio;


/**
 * One account movement from the JSON export.
 */
final class Transaction
{
	public function __construct(
		/** ID pohybu, unique per movement */
		public readonly int $id,
		public readonly string $date,
		/** positive for incoming, negative for outgoing */
		public readonly float $amount,
		public readonly string $currency,
		public readonly ?string $counterAccount = null,
		public readonly ?string $counterAccountName = null,
		public readonly ?string $bankName = null,
		public readonly ?string $bic = null,
		public readonly ?string $variableSymbol = null,
		public readonly ?string $constantSymbol = null,
		public readonly ?string $specificSymbol = null,
		public readonly ?string $message = null,
		public readonly ?string $userIdentification = null,
		public readonly ?string $payerReference = null,
		public readonly ?string $type = null,
		public readonly ?string $executedBy = null,
		public readonly ?string $details = null,
		public readonly ?string $comment = null,
		/** ID pokynu, shared e.g. by a payment and its fee */
		public readonly ?int $instructionId = null,
	) {
	}


	/**
	 * @param  array<string, ?array{value: mixed}>  $row
	 */
	public static function fromJson(array $row): self
	{
		$get = static fn(int $column): mixed => $row['column' . $column]['value'] ?? null;
		$str = static function (int $column) use ($get): ?string {
			$value = $get($column);
			return $value === null || ($value = trim((string) $value)) === '' ? null : $value;
		};

		$counterAccount = $str(2);
		$bankCode = $str(3);
		// column3 carries the BIC instead of a bank code for foreign movements
		if ($counterAccount !== null && $bankCode !== null && preg_match('~^\d{4}\z~', $bankCode)) {
			$counterAccount .= '/' . $bankCode;
		}

		return new self(
			id: (int) $get(22),
			date: self::parseDate($get(0)),
			amount: (float) $get(1),
			currency: (string) $str(14),
			counterAccount: $counterAccount,
			counterAccountName: $str(10),
			bankName: $str(12),
			bic: $str(26),
			variableSymbol: $str(5),
			constantSymbol: $str(4),
			specificSymbol: $str(6),
			message: $str(16),
			userIdentification: $str(7),
			payerReference: $str(27),
			type: $str(8),
			executedBy: $str(9),
			details: $str(18),
			comment: $str(25),
			instructionId: $get(17) === null ? null : (int) $get(17),
		);
	}


	/**
	 * JSON carries dates as epoch milliseconds of local midnight; the other formats as 'Y-m-d+hh:mm'.
	 */
	public static function parseDate(mixed $value): string
	{
		if (is_int($value) || is_float($value)) {
			return (new \DateTimeImmutable('@' . intdiv((int) $value, 1000)))
				->setTimezone(new \DateTimeZone('Europe/Prague'))
				->format('Y-m-d');
		} elseif (is_string($value) && preg_match('~^\d{4}-\d{2}-\d{2}~', $value)) {
			return substr($value, 0, 10);
		}
		throw new \UnexpectedValueException('Unexpected date value in Fio export: ' . var_export($value, true));
	}


	public function isIncoming(): bool
	{
		return $this->amount > 0;
	}


	public function isOutgoing(): bool
	{
		return $this->amount < 0;
	}


	/**
	 * A zero movement is neither direction; Fio books e.g. reversed fees that way.
	 */
	public function getDirection(): string
	{
		return match (true) {
			$this->isIncoming() => 'incoming',
			$this->isOutgoing() => 'outgoing',
			default => 'zero',
		};
	}


	/**
	 * @return array<string, int|float|string>
	 */
	public function toArray(): array
	{
		$data = ['direction' => $this->getDirection()] + get_object_vars($this);
		return array_filter($data, static fn($value) => $value !== null);
	}
}
