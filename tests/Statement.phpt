<?php declare(strict_types=1);

use DG\Fio\Statement;
use DG\Fio\Transaction;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('account header and balances', function () {
	$statement = Statement::fromJson(file_get_contents(__DIR__ . '/fixtures/statement.json'));

	Assert::same([
		'number' => '2400222222',
		'bankCode' => '2010',
		'currency' => 'CZK',
		'iban' => 'CZ7920100000002400222222',
		'bic' => 'FIOBCZPPXXX',
	], $statement->account->toArray());
	Assert::same(185.03, $statement->openingBalance);
	Assert::same(1335.05, $statement->closingBalance);
	Assert::same('2012-08-01', $statement->dateFrom);
	Assert::same('2012-09-02', $statement->dateTo);
	Assert::count(3, $statement->transactions);
});


test('null columns are omitted', function () {
	$statement = Statement::fromJson(file_get_contents(__DIR__ . '/fixtures/statement.json'));

	Assert::same([
		'direction' => 'incoming',
		'id' => 1_155_172_472,
		'date' => '2012-08-31',
		'amount' => 0.02,
		'currency' => 'CZK',
		'type' => 'Připsaný úrok',
		'instructionId' => 2_135_081_594,
	], $statement->transactions[0]->toArray());
});


test('all columns, symbols keep leading zeros', function () {
	$statement = Statement::fromJson(file_get_contents(__DIR__ . '/fixtures/statement.json'));

	Assert::same([
		'direction' => 'incoming',
		'id' => 1_155_172_473,
		'date' => '2012-09-01',
		'amount' => 1250.0,
		'currency' => 'CZK',
		'counterAccount' => '19-2000145399/0800',
		'counterAccountName' => 'Béďa Trávníček',
		'bankName' => 'Česká spořitelna, a.s.',
		'bic' => 'GIBACZPX',
		'variableSymbol' => '0020240017',
		'constantSymbol' => '0558',
		'message' => 'Za hračky',
		'userIdentification' => 'Faktura 2024017',
		'payerReference' => 'REF-42',
		'type' => 'Bezhotovostní příjem',
		'instructionId' => 2_135_081_595,
	], $statement->transactions[1]->toArray());
});


test('outgoing payment is negative', function () {
	$statement = Statement::fromJson(file_get_contents(__DIR__ . '/fixtures/statement.json'));
	$t = $statement->transactions[2];

	Assert::false($t->isIncoming());
	Assert::same(-100.0, $t->amount);
	Assert::same('outgoing', $t->toArray()['direction']);
	Assert::same('2012-09-02', $t->date);
});


test('epoch milliseconds are local midnight in Prague', function () {
	Assert::same('2012-08-31', Transaction::parseDate(1_346_364_000_000)); // 2012-08-30T22:00Z
	Assert::same('2024-01-15', Transaction::parseDate(1_705_273_200_000)); // 2024-01-14T23:00Z, winter time
	Assert::same('2024-01-15', Transaction::parseDate('2024-01-15+0100'));
	Assert::exception(fn() => Transaction::parseDate(null), UnexpectedValueException::class);
});


test('zero movement has no direction', function () {
	$t = Transaction::fromJson([
		'column22' => ['value' => 1],
		'column0' => ['value' => '2024-01-15+0100'],
		'column1' => ['value' => 0],
		'column14' => ['value' => 'CZK'],
	]);

	Assert::same('zero', $t->getDirection());
	Assert::false($t->isIncoming());
	Assert::false($t->isOutgoing());
});


test('a BIC in the bank-code column is not glued to the counter-account', function () {
	$t = Transaction::fromJson([
		'column22' => ['value' => 1],
		'column0' => ['value' => '2024-01-15+0100'],
		'column1' => ['value' => -10],
		'column14' => ['value' => 'EUR'],
		'column2' => ['value' => 'AT611904300234573201'],
		'column3' => ['value' => 'BKAUATWWXXX'],
		'column7' => ['value' => '  '],
	]);

	Assert::same('AT611904300234573201', $t->counterAccount);
	Assert::null($t->userIdentification);
});


test('a malformed movement is reported as unreadable', function () {
	Assert::exception(
		fn() => Statement::fromJson('{"accountStatement":{"info":{"accountId":"1","bankId":"2010","currency":"CZK","dateStart":"2024-01-15+0100","dateEnd":"2024-01-15+0100"},"transactionList":{"transaction":[null]}}}'),
		UnexpectedValueException::class,
		'%a%not an object%a%',
	);
	Assert::exception(
		fn() => Statement::fromJson('{"accountStatement":{"info":{}}}'),
		UnexpectedValueException::class,
		'Fio export is missing the account header.',
	);
});


test('empty range', function () {
	$statement = Statement::fromJson('{"accountStatement":{"info":{"accountId":"2400222222","bankId":"2010","currency":"EUR","iban":null,"bic":null,"openingBalance":10,"closingBalance":10,"dateStart":"2024-01-15+0100","dateEnd":"2024-01-15+0100"},"transactionList":{"transaction":[]}}}');

	Assert::same('EUR', $statement->account->currency);
	Assert::null($statement->account->iban);
	Assert::same([], $statement->transactions);
});
