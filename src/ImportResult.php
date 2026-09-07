<?php declare(strict_types=1);

namespace DG\Fio;


/**
 * Parsed response to an import (schema responseImportIB.xsd).
 */
final class ImportResult
{
	public function __construct(
		/** 0 accepted, 1 errors, 2 warnings, 11 syntax error, 12/14 empty, 13 file too large */
		public readonly int $errorCode,
		/** ok, warning, error or fatal */
		public readonly string $status,
		/** ID of the batch waiting for authorization */
		public readonly ?string $batchId,
		/** @var list<string> */
		public readonly array $messages,
		/** @var array<string, array{credit: ?string, debit: ?string}> keyed by currency */
		public readonly array $sums,
	) {
	}


	/**
	 * @throws \UnexpectedValueException
	 */
	public static function fromXml(string $xml): self
	{
		$root = @simplexml_load_string($xml);
		if ($root === false || !isset($root->result->errorCode)) {
			throw new \UnexpectedValueException('Unexpected response to the import: ' . mb_substr(trim(strip_tags($xml)), 0, 200));
		}

		$result = $root->result;
		$messages = [];
		foreach ($result->message as $message) {
			$messages[] = trim((string) $message);
		}
		foreach ($root->ordersDetails->detail ?? [] as $detail) {
			foreach ($detail->messages->message ?? [] as $message) {
				$messages[] = sprintf('[%s %s] %s', $message['status'], $message['errorCode'], trim((string) $message));
			}
		}

		$sums = [];
		foreach ($result->sums->sum ?? [] as $sum) {
			$sums[(string) $sum['id']] = [
				'credit' => isset($sum->sumCredit) ? (string) $sum->sumCredit : null,
				'debit' => isset($sum->sumDebet) ? (string) $sum->sumDebet : null,
			];
		}

		return new self(
			errorCode: (int) $result->errorCode,
			status: trim((string) $result->status),
			batchId: isset($result->idInstruction) ? (string) $result->idInstruction : null,
			messages: array_values(array_filter($messages, static fn($s) => $s !== '')),
			sums: $sums,
		);
	}


	/**
	 * Accepted orders wait in the internet banking for authorization; warnings do not block them.
	 */
	public function isAccepted(): bool
	{
		return in_array($this->status, ['ok', 'warning'], true);
	}
}
