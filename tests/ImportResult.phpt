<?php declare(strict_types=1);

use DG\Fio\ImportResult;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('accepted', function () {
	$result = ImportResult::fromXml(<<<'XML'
		<?xml version="1.0" encoding="UTF-8"?>
		<responseImport xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.fio.cz/schema/responseImportIB.xsd">
			<result>
				<errorCode>0</errorCode>
				<idInstruction>105964331</idInstruction>
				<status>ok</status>
				<sums>
					<sum id="CZK">
						<sumCredit>0</sumCredit>
						<sumDebet>100.00</sumDebet>
					</sum>
				</sums>
			</result>
			<ordersDetails>
				<detail id="1">
					<messages>
						<message status="ok" errorCode="0">OK</message>
					</messages>
				</detail>
			</ordersDetails>
		</responseImport>
		XML);

	Assert::true($result->isAccepted());
	Assert::same(0, $result->errorCode);
	Assert::same('ok', $result->status);
	Assert::same('105964331', $result->batchId);
	Assert::same(['CZK' => ['credit' => '0', 'debit' => '100.00']], $result->sums);
	Assert::same(['[ok 0] OK'], $result->messages);
});


test('warning is accepted', function () {
	$result = ImportResult::fromXml(<<<'XML'
		<responseImport>
			<result>
				<errorCode>2</errorCode>
				<idInstruction>105964332</idInstruction>
				<status>warning</status>
			</result>
			<ordersDetails>
				<detail id="1">
					<messages>
						<message status="warning" errorCode="1063">Currency of the payment does not match the account currency.</message>
					</messages>
				</detail>
			</ordersDetails>
		</responseImport>
		XML);

	Assert::true($result->isAccepted());
	Assert::same(['[warning 1063] Currency of the payment does not match the account currency.'], $result->messages);
	Assert::same([], $result->sums);
});


test('error rejects the batch', function () {
	$result = ImportResult::fromXml(<<<'XML'
		<responseImport>
			<result>
				<errorCode>1</errorCode>
				<status>error</status>
				<message>Batch rejected.</message>
			</result>
			<ordersDetails>
				<detail id="1">
					<messages>
						<message status="error" errorCode="1082">Invalid account number.</message>
					</messages>
				</detail>
			</ordersDetails>
		</responseImport>
		XML);

	Assert::false($result->isAccepted());
	Assert::null($result->batchId);
	Assert::same(['Batch rejected.', '[error 1082] Invalid account number.'], $result->messages);
});


test('syntax error without details', function () {
	$result = ImportResult::fromXml('<responseImport><result><errorCode>11</errorCode><status>fatal</status></result></responseImport>');
	Assert::false($result->isAccepted());
	Assert::same(11, $result->errorCode);
	Assert::same([], $result->messages);
});


test('unexpected response', function () {
	Assert::exception(
		fn() => ImportResult::fromXml('<html><body>The server encountered an internal error</body></html>'),
		UnexpectedValueException::class,
		'Unexpected response to the import: The server encountered an internal error',
	);
});
