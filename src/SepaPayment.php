<?php declare(strict_types=1);

namespace DG\Fio;


/**
 * SEPA payment in EUR to an IBAN (T2Transaction, "europlatba").
 */
final class SepaPayment extends Payment
{
	public const
		Standard = '431008',
		Priority = '431009',
		Instant = '431018';

	public const MaxMessageLength = 105;
	private const RemittanceLines = 3;
	private const RemittanceLineLength = 35;

	/** country_type from fio_xml_type.xsd; Fio rejects the whole file for anything else */
	private const Countries = [
		'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AN', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ', 'BA',
		'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT', 'BV', 'BW',
		'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CW',
		'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ',
		'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR',
		'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM', 'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ',
		'IR', 'IS', 'IT', 'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ',
		'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH',
		'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA', 'NC',
		'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL',
		'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD', 'SE',
		'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD',
		'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO', 'TP', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM',
		'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'XK', 'YE', 'YT', 'ZA', 'ZM', 'ZR',
		'ZW',
	];

	public readonly string $iban;
	public readonly ?string $bic;
	public readonly string $amount;
	public readonly string $recipientName;
	public readonly ?string $recipientStreet;
	public readonly ?string $recipientCity;
	public readonly ?string $recipientCountry;
	public readonly string $date;
	public readonly ?string $variableSymbol;
	public readonly ?string $constantSymbol;
	public readonly ?string $specificSymbol;
	public readonly ?string $message;
	public readonly ?string $comment;


	/**
	 * @throws \InvalidArgumentException
	 */
	public function __construct(
		string $iban,
		float $amount,
		string $recipientName,
		?string $bic = null,
		?string $recipientStreet = null,
		?string $recipientCity = null,
		?string $recipientCountry = null,
		?string $date = null,
		?string $variableSymbol = null,
		?string $constantSymbol = null,
		?string $specificSymbol = null,
		?string $message = null,
		?string $comment = null,
		public readonly string $paymentType = self::Standard,
	) {
		if (!in_array($paymentType, [self::Standard, self::Priority, self::Instant], true)) {
			throw new \InvalidArgumentException("Unknown payment type '$paymentType'.");
		}
		$this->iban = BankAccount::normalizeIban($iban);
		$this->bic = $bic === null || trim($bic) === '' ? null : BankAccount::normalizeBic($bic);
		$this->amount = self::formatAmount($amount);
		$this->recipientName = self::validateText($recipientName, 35, 'Recipient name')
			?? throw new \InvalidArgumentException('Recipient name is required.');
		$this->recipientStreet = self::validateText($recipientStreet, 35, 'Recipient street');
		$this->recipientCity = self::validateText($recipientCity, 35, 'Recipient city');
		$this->recipientCountry = self::validateCountry($recipientCountry);
		$this->date = self::validateDate($date ?? self::today());
		$this->variableSymbol = self::validateSymbol($variableSymbol, 10, 'Variable symbol');
		$this->constantSymbol = self::validateSymbol($constantSymbol, 4, 'Constant symbol');
		$this->specificSymbol = self::validateSymbol($specificSymbol, 10, 'Specific symbol');
		$this->message = self::validateText($message, self::MaxMessageLength, 'Message for recipient');
		$this->comment = self::validateText($comment, self::MaxCommentLength, 'Comment');
	}


	public function writeXml(\XMLWriter $writer, Account $from): void
	{
		if ($from->currency !== 'EUR') {
			throw new \InvalidArgumentException("SEPA payments can be sent only from a EUR account, this account is in $from->currency.");
		}

		$lines = self::splitMessage($this->message);
		self::writeElements($writer, 'T2Transaction', [
			'accountFrom' => $from->number,
			'currency' => $from->currency,
			'amount' => $this->amount,
			'accountTo' => $this->iban,
			'ks' => $this->constantSymbol,
			'vs' => $this->variableSymbol,
			'ss' => $this->specificSymbol,
			'bic' => $this->bic,
			'date' => $this->date,
			'comment' => $this->comment,
			'benefName' => $this->recipientName,
			'benefStreet' => $this->recipientStreet,
			'benefCity' => $this->recipientCity,
			'benefCountry' => $this->recipientCountry,
			'remittanceInfo1' => $lines[0] ?? null,
			'remittanceInfo2' => $lines[1] ?? null,
			'remittanceInfo3' => $lines[2] ?? null,
			'paymentType' => $this->paymentType,
		]);
	}


	public function getFingerprint(): string
	{
		return implode('|', ['sepa', $this->iban, $this->amount, $this->variableSymbol, $this->date]);
	}


	public function toArray(): array
	{
		return array_filter([
			'iban' => $this->iban,
			'bic' => $this->bic,
			'amount' => $this->amount,
			'date' => $this->date,
			'recipientName' => $this->recipientName,
			'recipientCountry' => $this->recipientCountry,
			'variableSymbol' => $this->variableSymbol,
			'constantSymbol' => $this->constantSymbol,
			'specificSymbol' => $this->specificSymbol,
			'message' => $this->message,
			'comment' => $this->comment,
		], static fn($value) => $value !== null);
	}


	/**
	 * Splits the message into remittance lines on spaces; Fio would silently trim spaces at the line
	 * edges (the XSD type is xs:token), so breaking mid-word would corrupt the text for the recipient.
	 * @return list<string>
	 * @throws \InvalidArgumentException
	 */
	private static function splitMessage(?string $message): array
	{
		if ($message === null) {
			return [];
		}

		$lines = [''];
		foreach (preg_split('~\s+~u', $message) as $word) {
			foreach (mb_str_split($word, self::RemittanceLineLength) as $part) { // a word longer than a line
				$last = array_key_last($lines);
				$candidate = $lines[$last] === '' ? $part : $lines[$last] . ' ' . $part;
				if (mb_strlen($candidate) <= self::RemittanceLineLength) {
					$lines[$last] = $candidate;
				} else {
					$lines[] = $part;
				}
			}
		}

		if (count($lines) > self::RemittanceLines) {
			throw new \InvalidArgumentException(sprintf(
				'Message for recipient does not fit into %d lines of %d characters, shorten it or use shorter words.',
				self::RemittanceLines,
				self::RemittanceLineLength,
			));
		}
		return $lines;
	}


	private static function validateCountry(?string $country): ?string
	{
		if ($country === null || ($country = strtoupper(trim($country))) === '') {
			return null;
		} elseif (!in_array($country, self::Countries, true)) {
			throw new \InvalidArgumentException("Fio does not accept '$country' as a recipient country code.");
		}
		return $country;
	}
}
