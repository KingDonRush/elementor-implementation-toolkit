<?php
/**
 * Public taxonomy projection contracts without per-item term queries.
 */

namespace EIT\Collection {
	function get_terms( array $args ) {
		return \CollectionTaxonomyProjectionState::terms( $args );
	}

	function get_term_link( $term, $taxonomy ) {
		return \CollectionTaxonomyProjectionState::term_link( $term, $taxonomy );
	}

	function esc_url_raw( $url ) {
		return 1 === preg_match( '#^https?://#i', (string) $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ? (string) $url : '';
	}
}

namespace {
	use EIT\Collection\CollectionExplainer;
	use EIT\Collection\CollectionProjector;
	use PHPUnit\Framework\TestCase;

	final class CollectionTaxonomyProjectionState {
		public static $queries = [];
		public static $links = [];

		public static function reset(): void {
			self::$queries = [];
			self::$links = [];
		}

		public static function terms( array $args ): array {
			self::$queries[] = $args;
			$catalog = [
				'topic' => [
					3 => [ '<b>Alpha</b>', 'Alpha Unsafe!' ],
					9 => [ 'Beta', 'beta' ],
				],
				'region' => [
					7 => [ '<script>South</script>', 'south' ],
				],
			];
			$result = [];
			foreach ( array_reverse( $args['include'] ) as $term_id ) {
				if ( isset( $catalog[ $args['taxonomy'] ][ $term_id ] ) ) {
					$result[] = (object) [
						'term_id' => $term_id,
						'name' => $catalog[ $args['taxonomy'] ][ $term_id ][0],
						'slug' => $catalog[ $args['taxonomy'] ][ $term_id ][1],
					];
				}
			}
			return $result;
		}

		public static function term_link( $term, string $taxonomy ): string {
			$key = $taxonomy . ':' . $term->term_id;
			self::$links[ $key ] = ( self::$links[ $key ] ?? 0 ) + 1;
			return 'region' === $taxonomy
				? 'javascript:alert(1)'
				: 'https://example.test/' . $taxonomy . '/' . sanitize_title( $term->slug );
		}
	}

	final class CollectionTaxonomyProjectionContractTest extends TestCase {
		protected function setUp(): void {
			CollectionTaxonomyProjectionState::reset();
		}

		public function test_taxonomies_are_projected_as_safe_objects_with_one_batch_per_taxonomy(): void {
			$fields = $this->fields();
			$items = [
				[ 'id' => '1', 'title' => 'First', 'url' => 'https://example.test/first', 'values' => [ 'topics' => [ 9, 3 ], 'region' => [ 7 ] ] ],
				[ 'id' => '2', 'title' => 'Second', 'url' => 'https://example.test/second', 'values' => [ 'topics' => [ 3, 999, 9 ], 'region' => [] ] ],
			];

			$projected = ( new CollectionProjector() )->items( $items, $fields );

			self::assertCount( 2, CollectionTaxonomyProjectionState::$queries );
			self::assertSame( [ 9, 3, 999 ], CollectionTaxonomyProjectionState::$queries[0]['include'] );
			self::assertSame( [ 7 ], CollectionTaxonomyProjectionState::$queries[1]['include'] );
			self::assertSame( [ 'topic', 'region' ], array_column( CollectionTaxonomyProjectionState::$queries, 'taxonomy' ) );
			self::assertSame( [ 'topic:3' => 1, 'topic:9' => 1, 'region:7' => 1 ], CollectionTaxonomyProjectionState::$links );
			self::assertSame(
				[
					[ 'id' => 9, 'name' => 'Beta', 'slug' => 'beta', 'url' => 'https://example.test/topic/beta' ],
					[ 'id' => 3, 'name' => 'Alpha', 'slug' => 'alpha-unsafe', 'url' => 'https://example.test/topic/alpha-unsafe' ],
				],
				$projected[0]['values']['topics']
			);
			self::assertSame( [ [ 'id' => 7, 'name' => 'South', 'slug' => 'south', 'url' => '' ] ], $projected[0]['values']['region'] );
			self::assertSame( [ 3, 9 ], array_column( $projected[1]['values']['topics'], 'id' ) );
			self::assertNotContains( 999, $projected[1]['values']['topics'], true );
		}

		public function test_projected_taxonomy_objects_remain_comparable_by_term_id(): void {
			$fields = $this->fields();
			$items = ( new CollectionProjector() )->items(
				[ [ 'id' => '1', 'title' => 'First', 'url' => '', 'values' => [ 'topics' => [ 9, 3 ] ] ] ],
				[ 'topics' => $fields['topics'] ]
			);
			$explain = ( new CollectionExplainer() )->explain(
				$items,
				[ 'filters' => [ [ 'field_id' => 'topics', 'operator' => 'in', 'value' => [ 3 ] ] ] ],
				[ 'topics' => $fields['topics'] ],
				'wp_query'
			);

			self::assertTrue( $explain[0]['checks'][0]['result'] );
			self::assertSame( [ 9, 3 ], array_column( $explain[0]['checks'][0]['actual'], 'id' ) );
		}

		private function fields(): array {
			return [
				'topics' => [ 'id' => 'topics', 'name' => 'Topics', 'type' => 'taxonomy', 'taxonomy' => [ 'slug' => 'topic' ] ],
				'region' => [ 'id' => 'region', 'name' => 'Region', 'type' => 'taxonomy', 'storage' => [ 'key' => 'region' ] ],
			];
		}
	}
}
