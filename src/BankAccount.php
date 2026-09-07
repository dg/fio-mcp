<?php declare(strict_types=1);

namespace DG\Fio;


/**
 * Czech bank account number in the form [prefix-]number/bankCode, plus IBAN and BIC checks.
 */
final class BankAccount
{
	private const PrefixWeights = [10, 5, 8, 4, 2, 1];
	private const NumberWeights = [6, 3, 7, 9, 10, 5, 8, 4, 2, 1];


	public function __construct(
		public readonly string $prefix,
		public readonly string $number,
		public readonly string $bankCode,
	) {
	}


	/**
	 * Parses and validates '[prefix-]number/bankCode', including the mod 11 checksum.
	 * @throws \InvalidArgumentException
	 */
	public static function parse(string $account): self
	{
		$account = self::stripWhitespace($account);
		if (!preg_match('~^(?:(\d{1,6})-)?(\d{2,10})/(\d{4})\z~', $account, $m)) {
			throw new \InvalidArgumentException("Invalid Czech account number '$account', expected [prefix-]number/bankCode, e.g. 19-2000145399/0800.");
		}

		$prefix = ltrim($m[1], '0');
		$number = ltrim($m[2], '0');
		if ($number === '') {
			throw new \InvalidArgumentException("Invalid Czech account number '$account'.");
		} elseif (!self::checkWeights($prefix, self::PrefixWeights) || !self::checkWeights($number, self::NumberWeights)) {
			throw new \InvalidArgumentException("Czech account number '$account' fails the checksum, check it for typos.");
		}

		return new self($prefix, $number, $m[3]);
	}


	/**
	 * Account number without the bank code, as Fio expects it in accountTo.
	 */
	public function getAccountNumber(): string
	{
		return ($this->prefix === '' ? '' : $this->prefix . '-') . $this->number;
	}


	public function __toString(): string
	{
		return $this->getAccountNumber() . '/' . $this->bankCode;
	}


	/**
	 * Removes spaces, uppercases and validates the mod 97 checksum.
	 * @throws \InvalidArgumentException
	 */
	public static function normalizeIban(string $iban): string
	{
		$iban = strtoupper(self::stripWhitespace($iban));
		if (!preg_match('~^[A-Z]{2}\d{2}[A-Z0-9]{11,30}\z~', $iban)) {
			throw new \InvalidArgumentException("Invalid IBAN '$iban'.");
		}

		$digits = '';
		foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $char) {
			$digits .= ctype_digit($char) ? $char : (string) (ord($char) - 55);
		}

		$remainder = 0;
		foreach (str_split($digits, 7) as $chunk) {
			$remainder = (int) ($remainder . $chunk) % 97;
		}

		if ($remainder !== 1) {
			throw new \InvalidArgumentException("IBAN '$iban' fails the checksum, check it for typos.");
		}
		return $iban;
	}


	/**
	 * @throws \InvalidArgumentException
	 */
	public static function normalizeBic(string $bic): string
	{
		$bic = strtoupper(self::stripWhitespace($bic));
		if (!preg_match('~^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?\z~', $bic)) {
			throw new \InvalidArgumentException("Invalid BIC '$bic'.");
		}
		return $bic;
	}


	/**
	 * Removes every kind of whitespace, including the non-breaking space people paste from documents.
	 */
	private static function stripWhitespace(string $s): string
	{
		return (string) preg_replace('~[\s\x{00A0}]+~u', '', $s);
	}


	/**
	 * @param  list<int>  $weights
	 */
	private static function checkWeights(string $digits, array $weights): bool
	{
		$digits = str_pad($digits, count($weights), '0', STR_PAD_LEFT);
		$sum = 0;
		foreach ($weights as $i => $weight) {
			$sum += (int) $digits[$i] * $weight;
		}
		return $sum % 11 === 0;
	}
}
