<?php declare(strict_types=1);

namespace DG\Fio;


/**
 * Payment to a Czech account (DomesticTransaction).
 */
final class DomesticPayment extends Payment
{
	public const
		Standard = '431001',
		Priority = '431005';

	private const FioBankCode = '2010';

	public readonly string $amount;
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
		public readonly BankAccount $to,
		float $amount,
		?string $date = null,
		?string $variableSymbol = null,
		?string $constantSymbol = null,
		?string $specificSymbol = null,
		?string $message = null,
		?string $comment = null,
		public readonly string $paymentType = self::Standard,
		/** confirms the intended currency; the order is always in the currency of the sender's account */
		public readonly ?string $currency = null,
	) {
		if (!in_array($paymentType, [self::Standard, self::Priority], true)) {
			throw new \InvalidArgumentException("Unknown payment type '$paymentType'.");
		}
		$this->amount = self::formatAmount($amount);
		$this->date = self::validateDate($date ?? self::today());
		$this->variableSymbol = self::validateSymbol($variableSymbol, 10, 'Variable symbol');
		$this->constantSymbol = self::validateSymbol($constantSymbol, 4, 'Constant symbol');
		$this->specificSymbol = self::validateSymbol($specificSymbol, 10, 'Specific symbol');
		$this->message = self::validateText($message, 140, 'Message for recipient');
		$this->comment = self::validateText($comment, self::MaxCommentLength, 'Comment');
	}


	public function writeXml(\XMLWriter $writer, Account $from): void
	{
		self::assertCurrency($this->currency, $from);
		if ($from->currency !== 'CZK' && $this->to->bankCode !== self::FioBankCode) {
			throw new \InvalidArgumentException("The account is in $from->currency; domestic payments in other currencies than CZK are possible only to accounts at Fio banka (bank code 2010).");
		}

		self::writeElements($writer, 'DomesticTransaction', [
			'accountFrom' => $from->number,
			'currency' => $from->currency,
			'amount' => $this->amount,
			'accountTo' => $this->to->getAccountNumber(),
			'bankCode' => $this->to->bankCode,
			'ks' => $this->constantSymbol,
			'vs' => $this->variableSymbol,
			'ss' => $this->specificSymbol,
			'date' => $this->date,
			'messageForRecipient' => $this->message,
			'comment' => $this->comment,
			'paymentType' => $this->paymentType,
		]);
	}


	public function getFingerprint(): string
	{
		return implode('|', ['domestic', (string) $this->to, $this->amount, $this->variableSymbol, $this->date]);
	}


	public function toArray(): array
	{
		return array_filter([
			'account' => (string) $this->to,
			'amount' => $this->amount,
			'date' => $this->date,
			'variableSymbol' => $this->variableSymbol,
			'constantSymbol' => $this->constantSymbol,
			'specificSymbol' => $this->specificSymbol,
			'message' => $this->message,
			'comment' => $this->comment,
		], static fn($value) => $value !== null);
	}
}
