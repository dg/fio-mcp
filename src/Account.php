<?php declare(strict_types=1);

namespace DG\Fio;


/**
 * Identification of the account the token belongs to. It never changes for a given token.
 */
final class Account
{
	public function __construct(
		public readonly string $number,
		public readonly string $bankCode,
		public readonly string $currency,
		public readonly ?string $iban = null,
		public readonly ?string $bic = null,
	) {
	}


	/**
	 * @param  array<string, mixed>  $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			number: (string) $data['number'],
			bankCode: (string) $data['bankCode'],
			currency: (string) $data['currency'],
			iban: isset($data['iban']) ? (string) $data['iban'] : null,
			bic: isset($data['bic']) ? (string) $data['bic'] : null,
		);
	}


	/**
	 * @return array{number: string, bankCode: string, currency: string, iban: ?string, bic: ?string}
	 */
	public function toArray(): array
	{
		return [
			'number' => $this->number,
			'bankCode' => $this->bankCode,
			'currency' => $this->currency,
			'iban' => $this->iban,
			'bic' => $this->bic,
		];
	}
}
