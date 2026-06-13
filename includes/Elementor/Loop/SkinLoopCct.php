<?php
/**
 * Elementor Loop Grid/Carousel skin backed by a Toolkit CCT table.
 */

namespace EIT\Elementor\Loop;

use Elementor\Controls_Manager;
use ElementorPro\Modules\LoopBuilder\Skins\Skin_Loop_Base;
use ElementorPro\Modules\LoopBuilder\Widgets\Base as LoopWidgetBase;
use ElementorPro\Plugin;
use EIT\CCT\CurrentItemContext;
use EIT\CCT\DefinitionManager;
use EIT\CCT\Repository;
use EIT\Support\CctFieldCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SkinLoopCct extends Skin_Loop_Base {

	private $result;
	private $current_item;
	private $repository;

	public function get_id() {
		return 'toolkit-cct';
	}

	public function get_title() {
		return __( 'Toolkit CCT', 'elementor-implementation-toolkit' );
	}

	public function register_query_controls( LoopWidgetBase $widget ) {
		$this->parent = $widget;

		$this->add_control(
			'cct_type',
			[
				'label'   => __( 'Content Type', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::SELECT,
				'options' => CctFieldCatalog::type_options(),
				'default' => '',
			]
		);
		$this->add_control(
			'status',
			[
				'label'   => __( 'Status', 'elementor-implementation-toolkit' ),
				'type'    => Controls_Manager::SELECT,
				'options' => [
					'publish'   => __( 'Published', 'elementor-implementation-toolkit' ),
					'draft'     => __( 'Draft', 'elementor-implementation-toolkit' ),
					'archived'  => __( 'Archived', 'elementor-implementation-toolkit' ),
					'any'       => __( 'Any', 'elementor-implementation-toolkit' ),
				],
				'default' => 'publish',
			]
		);
		$this->add_control( 'include', [ 'label' => __( 'Include IDs', 'elementor-implementation-toolkit' ), 'type' => Controls_Manager::TEXT, 'placeholder' => '1, 4, 7' ] );
		$this->add_control( 'exclude', [ 'label' => __( 'Exclude IDs', 'elementor-implementation-toolkit' ), 'type' => Controls_Manager::TEXT, 'placeholder' => '2, 5' ] );
		$this->add_control( 'orderby', [ 'label' => __( 'Order By', 'elementor-implementation-toolkit' ), 'type' => Controls_Manager::SELECT, 'options' => CctFieldCatalog::order_options(), 'default' => 'menu_order' ] );
		$this->add_control( 'order', [ 'label' => __( 'Order', 'elementor-implementation-toolkit' ), 'type' => Controls_Manager::SELECT, 'options' => [ 'ASC' => 'ASC', 'DESC' => 'DESC' ], 'default' => 'ASC' ] );
		$this->add_control( 'offset', [ 'label' => __( 'Offset', 'elementor-implementation-toolkit' ), 'type' => Controls_Manager::NUMBER, 'min' => 0, 'default' => 0 ] );
	}

	public function query_posts() {
		$type = DefinitionManager::sanitize_slug( $this->get_instance_value( 'cct_type' ) );
		$status = sanitize_key( $this->get_instance_value( 'status' ) );
		$per_page = max( 1, absint( $this->parent->get_settings_for_display( 'posts_per_page' ) ) ?: 6 );
		$page = max( 1, absint( $this->parent->get_current_page() ) );
		$statuses = 'any' === $status ? [ 'publish', 'draft', 'archived' ] : [ $status ?: 'publish' ];

		$this->result = $this->repository()->query(
			$type,
			[
				'status'   => $statuses,
				'include'  => $this->parse_ids( $this->get_instance_value( 'include' ) ),
				'exclude'  => $this->parse_ids( $this->get_instance_value( 'exclude' ) ),
				'orderby'  => $this->get_instance_value( 'orderby' ),
				'order'    => $this->get_instance_value( 'order' ),
				'offset'   => absint( $this->get_instance_value( 'offset' ) ),
				'page'     => $page,
				'per_page' => $per_page,
			]
		);

		$query = new \WP_Query();
		$query->found_posts = $this->result['total'];
		$query->post_count = count( $this->result['items'] );
		$query->max_num_pages = $this->result['pages'];
		$query->posts = [];

		return $query;
	}

	public function render() {
		$template_id = absint( $this->parent->get_settings_for_display( 'template_id' ) );
		$is_edit_mode = Plugin::elementor()->editor->is_edit_mode();
		$current_document = Plugin::elementor()->documents->get_current();

		if ( ! $template_id ) {
			if ( $is_edit_mode ) {
				$this->render_empty_view();
			}
			return;
		}

		$this->parent->query_posts();
		$items = $this->result['items'] ?? [];

		if ( empty( $items ) ) {
			$this->handle_no_posts_found();
			return;
		}

		$this->enqueue_loop_document_css_meta( $template_id );
		$this->maybe_add_load_more_wrapper_class();
		$this->parent->before_skin_render();
		$this->render_loop_header();

		try {
			foreach ( $items as $item ) {
				$this->render_item( $template_id, $item );
			}
		} finally {
			$this->current_item = null;
			CurrentItemContext::clear();
			remove_filter( 'elementor/document/wrapper_attributes', [ $this, 'add_item_attributes' ], 20 );
		}

		$this->render_loop_footer();
		$this->parent->after_skin_render();

		if ( $current_document ) {
			Plugin::elementor()->documents->switch_to_document( $current_document );
		}
	}

	public function add_item_attributes( $attributes ) {
		if ( ! $this->current_item ) {
			return $attributes;
		}

		$type = DefinitionManager::sanitize_slug( $this->get_instance_value( 'cct_type' ) );
		$attributes['data-eit-item'] = '1';
		$attributes['data-eit-cct'] = $type;
		$attributes['data-eit-cct-id'] = (string) $this->current_item['id'];

		foreach ( DefinitionManager::fields( $type ) as $key => $field ) {
			if ( empty( $field['filterable'] ) || ! isset( $this->current_item[ $key ] ) ) {
				continue;
			}
			$value = $this->current_item[ $key ];
			$attributes[ 'data-eit-' . str_replace( '_', '-', $key ) ] = is_array( $value ) ? implode( ' ', $value ) : (string) $value;
		}

		return $attributes;
	}

	private function render_item( $template_id, array $item ) {
		$type = DefinitionManager::sanitize_slug( $this->get_instance_value( 'cct_type' ) );
		$document = Plugin::elementor()->documents->get( $template_id );
		if ( ! $document ) {
			return;
		}

		$this->current_item = $item;
		CurrentItemContext::push( $type, $item );
		add_filter( 'elementor/document/wrapper_attributes', [ $this, 'add_item_attributes' ], 20 );

		try {
			$this->print_dynamic_css( abs( crc32( $type . ':' . $item['id'] ) ), $template_id );
			$document->print_content();
		} finally {
			remove_filter( 'elementor/document/wrapper_attributes', [ $this, 'add_item_attributes' ], 20 );
			CurrentItemContext::pop();
			$this->current_item = null;
		}
	}

	private function repository() {
		if ( ! $this->repository ) {
			$this->repository = new Repository();
		}
		return $this->repository;
	}

	private function parse_ids( $value ) {
		return array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) $value ) ) ) );
	}
}
