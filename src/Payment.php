<?php declare(strict_types=1);

namespace DG\Fio;


/**
 * Payment order for the Fio XML import. Values are validated in the constructor.
 */
abstract class Payment
{
	public const MaxCommentLength = 140;


	/**
	 * Writes the order element; the sender's account decides the order currency.
	 * @throws \InvalidArgumentException  when the payment cannot be sent from this account
	 */
	abstract public function writeXml(\XMLWriter $writer, Account $from): void;


	/**
	 * Identifies the same payment across repeated calls, for duplicate detection.
	 */
	abstract public function getFingerprint(): string;


	/**
	 * What was submitted, so the caller can show it next to the batch waiting in the internet banking.
	 * @return array<string, string>
	 */
	abstract public function toArray(): array;


	/**
	 * Complete import document with this single order.
	 */
	public function toXml(Account $from): string
	{
		$writer = new \XMLWriter;
		$writer->openMemory();
		$writer->setIndent(true);
		$writer->setIndentString("\t");
		$writer->startDocument('1.0', 'UTF-8');
		$writer->startElement('Import');
		$writer->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
		$writer->writeAttribute('xsi:noNamespaceSchemaLocation', 'http://www.fio.cz/schema/importIB.xsd');
		$writer->startElement('Orders');
		$this->writeXml($writer, $from);
		$writer->endElement();
		$writer->endElement();
		$writer->endDocument();
		return $writer->outputMemory();
	}


	/**
	 * Amount with exactly two decimals, e.g. 100 → '100.00'.
	 * @throws \InvalidArgumentException
	 */
	protected static function formatAmount(float $amount): string
	{
		$cents = $amount * 100;
		if (!is_finite($amount) || $amount <= 0) {
			throw new \InvalidArgumentException('Amount must be a positive number.');
		} elseif (abs($cents - round($cents)) > 1e-6) {
			throw new \InvalidArgumentException("Amount $amount has more than two decimal places.");
		}
		return number_format($amount, 2, '.', '');
	}


	/**
	 * @throws \InvalidArgumentException
	 */
	protected static function validateSymbol(?string $value, int $maxLength, string $name): ?string
	{
		if ($value === null || ($value = trim($value)) === '') {
			return null;
		} elseif (!preg_match('~^\d{1,' . $maxLength . '}$~', $value)) {
			throw new \InvalidArgumentException("$name must be up to $maxLength digits, '$value' given.");
		}
		return $value;
	}


	/**
	 * @throws \InvalidArgumentException
	 */
	protected static function validateText(?string $value, int $maxLength, string $name): ?string
	{
		if ($value === null || ($value = trim($value)) === '') {
			return null;
		} elseif (mb_strlen($value) > $maxLength) {
			throw new \InvalidArgumentException("$name must be at most $maxLength characters, " . mb_strlen($value) . ' given.');
		} elseif (preg_match('~[\x00-\x08\x0B\x0C\x0E-\x1F]~', $value)) {
			// they would end up in the XML and Fio would reject the whole file as malformed
			throw new \InvalidArgumentException("$name must not contain control characters.");
		}
		return $value;
	}


	/**
	 * The order is always in the currency of the sender's account; a caller-supplied currency only confirms it.
	 * @throws \InvalidArgumentException
	 */
	protected static function assertCurrency(?string $currency, Account $from): void
	{
		if ($currency !== null && strtoupper(trim($currency)) !== $from->currency) {
			throw new \InvalidArgumentException("The account is in $from->currency, a payment in " . strtoupper(trim($currency)) . ' cannot be sent from it.');
		}
	}


	/**
	 * @throws \InvalidArgumentException
	 */
	public static function parseDate(string $date, string $name = 'date'): string
	{
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Prague'));
		if (!$parsed || $parsed->format('Y-m-d') !== $date) {
			throw new \InvalidArgumentException("Invalid $name '$date', expected YYYY-MM-DD.");
		}
		return $date;
	}


	/**
	 * Due date, today or later (Prague time).
	 * @throws \InvalidArgumentException
	 */
	protected static function validateDate(string $date): string
	{
		self::parseDate($date);
		if ($date < self::today()) {
			throw new \InvalidArgumentException("Payment date $date is in the past.");
		}
		return $date;
	}


	public static function today(): string
	{
		return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
	}


	/**
	 * Writes the elements in the given order (the XSD requires a sequence), skipping nulls.
	 * @param  array<string, ?string>  $elements
	 */
	protected static function writeElements(\XMLWriter $writer, string $name, array $elements): void
	{
		$writer->startElement($name);
		foreach ($elements as $element => $value) {
			if ($value !== null) {
				$writer->writeElement($element, $value);
			}
		}
		$writer->endElement();
	}
}
