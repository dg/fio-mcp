<?php declare(strict_types=1);

namespace DG\Fio;


/**
 * Error reported by the Fio API or a refused operation; the message is meant for the user.
 */
class FioException extends \RuntimeException
{
}


/**
 * The request did not complete (network, TLS, timeout), so it is unknown whether Fio processed it.
 */
class TransportException extends FioException
{
}


/**
 * Fio answered with an error status.
 */
class HttpException extends FioException
{
	public function __construct(
		public readonly int $status,
		string $message,
	) {
		parent::__construct($message);
	}


	/**
	 * Statuses that mean Fio refused the request before processing it, so an upload certainly did not happen.
	 */
	public function isRefused(): bool
	{
		return in_array($this->status, [400, 404, 409, 413, 415, 422, 500], true);
	}
}
