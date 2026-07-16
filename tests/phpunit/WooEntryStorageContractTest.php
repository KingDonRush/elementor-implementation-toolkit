<?php
/**
 * Pure proof that Woo Entry persistence never falls through to CCT storage.
 */

use EIT\Entry\EntryStorageGateway;
use EIT\Infrastructure\Transaction;
use EIT\Woo\WooValueGateway;
use PHPUnit\Framework\TestCase;

class WooEntryStorageContractTest extends TestCase {

	public function test_product_entry_creation_uses_woo_crud_properties(): void {
		$product = new class() {
			public $name = '';
			public $regular_price = '';
			public $status = '';

			public function set_name( $value ) {
				$this->name = $value;
			}

			public function set_regular_price( $value ) {
				$this->regular_price = $value;
			}

			public function set_status( $value ) {
				$this->status = $value;
			}

			public function save() {
				return 90;
			}
		};
		$transaction = new class() extends Transaction {
			public function run( callable $callback ) {
				return $callback();
			}
		};
		$woo = new WooValueGateway( null, fn() => $product );
		$storage = new EntryStorageGateway( null, null, $transaction, $woo );
		$name_id = '2c98c0f4-79c7-5f46-a9d1-005f1e14a821';
		$price_id = 'cb0a6673-b44b-51b3-9882-193fbd16410f';
		$contract = [
			'entity' => [ 'strategy' => 'adapter', 'adapter' => [ 'id' => 'woocommerce' ] ],
			'fields' => [
				[ 'id' => $name_id, 'type' => 'short_text', 'storage' => [ 'key' => 'name' ], 'validation' => [] ],
				[ 'id' => $price_id, 'type' => 'money', 'storage' => [ 'key' => 'regular_price' ], 'validation' => [] ],
			],
		];
		$result = $storage->save(
			$contract,
			[ $name_id => 'Product', $price_id => [ 'amount' => '29.90', 'currency' => 'BRL' ] ],
			0,
			'publish',
			1
		);

		self::assertSame( 90, $result );
		self::assertSame( 'Product', $product->name );
		self::assertSame( '29.90', $product->regular_price );
		self::assertSame( 'publish', $product->status );
	}
}
